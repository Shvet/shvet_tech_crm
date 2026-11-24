<?php

namespace App\Actions\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\CloudApiClient;
use Illuminate\Support\Facades\DB;

/** Replaces the stored template list with Meta's current one (all statuses; only APPROVED can be sent). */
class SyncWhatsAppTemplates
{
    public function __construct(private CloudApiClient $client) {}

    /** @return int number of templates stored */
    public function __invoke(WhatsAppAccount $account): int
    {
        $rows = $this->client->templates($account->waba_id, (string) $account->access_token);
        $now = now();

        DB::transaction(function () use ($account, $rows, $now) {
            $keep = [];
            foreach ($rows as $row) {
                $template = WhatsAppTemplate::query()->updateOrCreate(
                    ['whatsapp_account_id' => $account->getKey(), 'name' => mb_substr($row['name'], 0, 512), 'language' => mb_substr($row['language'], 0, 16)],
                    [
                        'category' => $row['category'] ? mb_substr($row['category'], 0, 32) : null,
                        'status' => mb_substr($row['status'], 0, 32),
                        'components' => $row['components'],
                        'body_param_count' => self::bodyParamCount($row['components']),
                        'synced_at' => $now,
                    ],
                );
                $keep[] = $template->getKey();
            }

            WhatsAppTemplate::query()->where('whatsapp_account_id', $account->getKey())->whereNotIn('id', $keep ?: [0])->delete();
            $account->update(['templates_synced_at' => $now]);
        });

        return count($rows);
    }

    /** Number of distinct {{n}} placeholders in the BODY component. @param  array<int, mixed>  $components */
    public static function bodyParamCount(array $components): int
    {
        foreach ($components as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) ($component['text'] ?? ''), $m);

                return count(array_unique($m[1]));
            }
        }

        return 0;
    }
}
