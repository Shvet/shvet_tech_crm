<?php

namespace App\Services\WhatsApp;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Leads\LeadVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which WhatsApp conversations a user may see. Derived from LeadVisibility so there is still one rule:
 *
 *   Admin / Sales Manager  every conversation in the workspace, including ones not linked to a lead yet
 *   Sales Agent            only conversations linked to a lead they own (unlinked chats wait for a manager)
 *
 * Seeing a conversation additionally requires conversations.view; replying requires conversations.reply.
 */
final class ConversationVisibility
{
    /**
     * @param  Builder<WhatsAppConversation>  $query
     * @return Builder<WhatsAppConversation>
     */
    public static function scope(Builder $query, User $user): Builder
    {
        if (! $user->checkPermissionTo(Permission::ConversationsView->value)) {
            return $query->whereRaw('1 = 0');
        }

        return match (LeadVisibility::level($user)) {
            LeadVisibility::ALL => $query,
            LeadVisibility::OWN => $query->whereIn('whatsapp_conversations.lead_id', LeadVisibility::scope(Lead::query(), $user)->select('leads.id')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function canView(User $user, WhatsAppConversation $conversation): bool
    {
        return self::scope(WhatsAppConversation::query()->whereKey($conversation->getKey()), $user)->exists();
    }
}
