<?php

namespace App\Actions\WhatsApp;

use App\Enums\WhatsAppAccountStatus;
use App\Exceptions\MetaApiException;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\CloudApiClient;
use App\Support\AuditLogger;

/** Re-checks the stored token against Meta. An auth failure flips the account to reauthorization_required. */
class ValidateWhatsAppAccount
{
    public function __construct(private CloudApiClient $client) {}

    public function __invoke(WhatsAppAccount $account): bool
    {
        if ($account->status === WhatsAppAccountStatus::Disconnected || blank($account->access_token)) {
            return false;
        }

        try {
            $this->client->phoneNumber($account->phone_number_id, (string) $account->access_token);
        } catch (MetaApiException $e) {
            self::recordFailure($account, $e);

            return false;
        }

        $account->update(['status' => WhatsAppAccountStatus::Active, 'last_validated_at' => now(), 'last_error_code' => null, 'last_error_summary' => null]);

        return true;
    }

    /** Shared by the send job: an auth error pauses the account until an admin reconnects. */
    public static function recordFailure(WhatsAppAccount $account, MetaApiException $e): void
    {
        $updates = ['last_error_code' => $e->code(), 'last_error_summary' => mb_substr($e->category === MetaApiException::AUTH
            ? 'Meta rejected the saved access token. Reconnect WhatsApp with a new token.'
            : 'Meta returned an error ('.$e->code().').', 0, 255)];

        if ($e->requiresReauthorization() && $account->status === WhatsAppAccountStatus::Active) {
            $updates['status'] = WhatsAppAccountStatus::ReauthorizationRequired;
            AuditLogger::record('whatsapp.account.reauthorization_required', $account, ['code' => $e->code()]);
        }

        $account->update($updates);
    }
}
