<?php

namespace App\Actions\WhatsApp;

use App\Enums\WhatsAppAccountStatus;
use App\Models\WhatsAppAccount;
use App\Support\AuditLogger;

/** Deletes the stored token and stops sending/receiving. Conversations, messages and leads are kept. */
class DisconnectWhatsApp
{
    public function __invoke(WhatsAppAccount $account): void
    {
        $account->update([
            'access_token' => null,
            'status' => WhatsAppAccountStatus::Disconnected,
            'subscription_status' => 'pending',
        ]);
        $account->templates()->delete();

        AuditLogger::record('whatsapp.account.disconnected', $account, ['phone_number_id' => $account->phone_number_id]);
    }
}
