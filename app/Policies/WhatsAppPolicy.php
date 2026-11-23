<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\ConversationVisibility;

/**
 * WhatsApp authorization. Connecting/disconnecting the number is Admin-only (integrations.manage). Conversation access
 * follows ConversationVisibility; linking a chat to a lead needs leads.assign (managers and admins).
 */
class WhatsAppPolicy
{
    public function manage(User $user): bool
    {
        return $user->checkPermissionTo(Permission::IntegrationsManage->value);
    }

    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ConversationsView->value);
    }

    public function view(User $user, WhatsAppConversation $conversation): bool
    {
        return ConversationVisibility::canView($user, $conversation);
    }

    public function reply(User $user, WhatsAppConversation $conversation): bool
    {
        return $user->checkPermissionTo(Permission::ConversationsReply->value) && $this->view($user, $conversation);
    }

    public function link(User $user, WhatsAppConversation $conversation): bool
    {
        return $user->checkPermissionTo(Permission::LeadsAssign->value) && $this->view($user, $conversation);
    }
}
