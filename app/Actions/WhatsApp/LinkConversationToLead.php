<?php

namespace App\Actions\WhatsApp;

use App\Actions\Leads\CreateLead;
use App\Enums\MembershipStatus;
use App\Exceptions\DuplicateLeadException;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Services\Leads\PhoneNormalizer;
use App\Services\WhatsApp\WhatsAppLog;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Connects a WhatsApp conversation (and its contact) to a CRM lead.
 *
 * Automatic (first inbound message), never guessing:
 *   - exactly one lead in the workspace has this phone number  -> link to it
 *   - several leads share it                                   -> "ambiguous", a manager picks
 *   - none, and the number allows auto-creation               -> create a WhatsApp lead (unassigned or default owner)
 * Existing leads are never modified or merged.
 *
 * Manual: a manager/admin links a conversation to a lead they can see (manual()).
 */
class LinkConversationToLead
{
    public function __construct(private CreateLead $createLead) {}

    public function automatic(WhatsAppConversation $conversation, WhatsAppAccount $account): void
    {
        /** @var WhatsAppContact $contact */
        $contact = $conversation->contact;

        if ($contact->lead_id !== null && Lead::query()->whereKey($contact->lead_id)->exists()) {
            $this->apply($conversation, $contact, (int) $contact->lead_id);

            return;
        }

        $phone = PhoneNormalizer::normalize('+'.$contact->wa_id);
        $matches = $phone === null ? collect() : Lead::query()->where('phone_e164', $phone)->orderBy('id')->limit(2)->pluck('id');

        if ($matches->count() === 1) {
            $this->apply($conversation, $contact, (int) $matches->first());

            return;
        }

        if ($matches->count() > 1) {
            $conversation->update(['link_status' => WhatsAppConversation::AMBIGUOUS]);

            return;
        }

        if (! $account->auto_create_leads) {
            return;
        }

        $lead = $this->createLeadFor($contact, $phone, $account);
        if ($lead !== null) {
            $this->apply($conversation, $contact, (int) $lead->getKey());
        }
    }

    /** A person (manager/admin) links the conversation to a lead they may see. Caller has authorized both. */
    public function manual(User $actor, WhatsAppConversation $conversation, Lead $lead): void
    {
        $this->apply($conversation, $conversation->contact, (int) $lead->getKey());
        AuditLogger::record('whatsapp.conversation.linked', $conversation, ['lead' => $lead->public_id, 'by' => $actor->getKey()]);
    }

    private function apply(WhatsAppConversation $conversation, WhatsAppContact $contact, int $leadId): void
    {
        DB::transaction(function () use ($contact, $leadId) {
            $contact->update(['lead_id' => $leadId]);
            // Every conversation with this contact (e.g. on a second number) follows the same lead.
            WhatsAppConversation::query()->where('whatsapp_contact_id', $contact->getKey())
                ->update(['lead_id' => $leadId, 'link_status' => WhatsAppConversation::LINKED]);
        });
        $conversation->refresh();
    }

    private function createLeadFor(WhatsAppContact $contact, ?string $phone, WhatsAppAccount $account): ?Lead
    {
        $source = LeadSource::query()->where('key', 'whatsapp')->where('is_active', true)->first();
        if ($source === null || $phone === null) {
            WhatsAppLog::warning('lead.not_created', ['reason' => $source === null ? 'no_whatsapp_source' : 'invalid_phone']);

            return null;
        }

        $data = [
            'full_name' => $contact->profile_name ?: $phone,
            'phone_e164' => $phone,
            'lead_source_id' => $source->getKey(),
            'owner_id' => $account->default_owner_id,
            'external_provider' => 'whatsapp',
            'external_id' => $contact->wa_id,
        ];

        // The default owner may have left the workspace since it was chosen: then the lead stays unassigned for a manager.
        if ($data['owner_id'] !== null && ! TenantMembership::query()->where('user_id', $data['owner_id'])->where('status', MembershipStatus::Active->value)->exists()) {
            $data['owner_id'] = null;
        }

        try {
            $lead = $this->createLead->import($data)['lead'];
        } catch (DuplicateLeadException) {
            return null; // a lead appeared meanwhile; the next message links it
        }

        AuditLogger::record('whatsapp.lead.created', $lead, ['contact' => $contact->getKey()]);

        return $lead;
    }
}
