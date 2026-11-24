<?php

namespace App\Actions\WhatsApp;

use App\Enums\WhatsAppAccountStatus;
use App\Exceptions\MetaApiException;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Leads\ActiveMemberGuard;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WhatsAppLog;
use App\Support\AuditLogger;
use App\Support\Facades\Tenancy;
use Illuminate\Validation\ValidationException;

/**
 * Connects (or reconnects) the workspace's WhatsApp number with a System User token entered by an Admin.
 *
 * Before anything is stored, Meta must confirm that the token can read the phone number AND that the number belongs to
 * the given WhatsApp Business Account. A number already connected to another workspace is refused (one owner per number).
 * Subscribing the app to the account's webhooks and syncing templates are attempted afterwards; their failure is shown,
 * not fatal.
 */
class ConnectWhatsAppAccount
{
    public function __construct(private CloudApiClient $client, private SyncWhatsAppTemplates $syncTemplates) {}

    /** @param  array{waba_id: string, phone_number_id: string, access_token: string, auto_create_leads?: bool, default_owner_id?: ?int}  $data */
    public function __invoke(User $actor, array $data): WhatsAppAccount
    {
        if (($data['default_owner_id'] ?? null) !== null) {
            ActiveMemberGuard::ensure((int) $data['default_owner_id'], 'default_owner_id');
        }

        $taken = Tenancy::withoutScope(fn () => WhatsAppAccount::query()
            ->where('phone_number_id', $data['phone_number_id'])
            ->where('tenant_id', '!=', Tenancy::idOrFail())
            ->exists());
        if ($taken) {
            throw ValidationException::withMessages(['phone_number_id' => 'This WhatsApp number is already connected to another workspace.']);
        }

        $existing = WhatsAppAccount::query()->where('status', '!=', WhatsAppAccountStatus::Disconnected->value)->first();
        if ($existing && $existing->phone_number_id !== $data['phone_number_id']) {
            throw ValidationException::withMessages(['phone_number_id' => 'Disconnect the current WhatsApp number before connecting a different one.']);
        }

        try {
            $info = $this->client->phoneNumber($data['phone_number_id'], $data['access_token']);
            $owned = $this->client->phoneNumberIds($data['waba_id'], $data['access_token']);
        } catch (MetaApiException $e) {
            WhatsAppLog::warning('connect.rejected', ['code' => $e->code()]);
            throw ValidationException::withMessages(['access_token' => $this->explain($e)]);
        }

        if (! in_array($data['phone_number_id'], $owned, true)) {
            throw ValidationException::withMessages(['phone_number_id' => 'This phone number id does not belong to that WhatsApp Business Account.']);
        }

        $account = WhatsAppAccount::query()->firstOrNew(['phone_number_id' => $data['phone_number_id']]);
        $reconnect = $account->exists;
        $account->fill([
            'waba_id' => $data['waba_id'],
            'display_phone_number' => $info['display_phone_number'] ? mb_substr($info['display_phone_number'], 0, 32) : null,
            'verified_name' => $info['verified_name'] ? mb_substr($info['verified_name'], 0, 255) : null,
            'access_token' => $data['access_token'],
            'status' => WhatsAppAccountStatus::Active,
            'auto_create_leads' => (bool) ($data['auto_create_leads'] ?? true),
            'default_owner_id' => $data['default_owner_id'] ?? null,
            'last_validated_at' => now(),
            'last_error_code' => null,
            'last_error_summary' => null,
        ]);
        $account->created_by ??= $actor->getKey();
        $account->save();

        try {
            $this->client->subscribeApp($account->waba_id, $data['access_token']);
            $account->update(['subscription_status' => 'subscribed']);
        } catch (MetaApiException $e) {
            $account->update(['subscription_status' => 'failed', 'last_error_code' => $e->code(), 'last_error_summary' => 'Could not subscribe the app to this account\'s webhooks. '.$this->explain($e)]);
        }

        try {
            ($this->syncTemplates)($account);
        } catch (MetaApiException $e) {
            WhatsAppLog::warning('templates.sync_failed', ['code' => $e->code()]);
        }

        AuditLogger::record($reconnect ? 'whatsapp.account.reconnected' : 'whatsapp.account.connected', $account, ['phone_number_id' => $account->phone_number_id]);

        return $account;
    }

    private function explain(MetaApiException $e): string
    {
        return match ($e->category) {
            MetaApiException::AUTH => 'Meta rejected this access token (invalid or expired).',
            MetaApiException::PERMISSION => 'This token cannot access that number. Give the System User the whatsapp_business_management and whatsapp_business_messaging permissions on the account.',
            MetaApiException::NOT_FOUND, MetaApiException::INVALID => 'Meta could not find that WhatsApp Business Account or phone number id. Check both ids.',
            default => 'Meta could not be reached or returned an error. Try again in a minute.',
        };
    }
}
