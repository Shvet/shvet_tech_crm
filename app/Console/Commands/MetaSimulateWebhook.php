<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('meta:simulate-webhook {page_id} {leadgen_id} {--form_id=} {--url= : Webhook URL (default: APP_URL/webhooks/meta)}')]
#[Description('DEV/STAGING ONLY. Sends a correctly signed, MOCK leadgen webhook to this app. Not a Meta test: it only exercises our endpoint.')]
class MetaSimulateWebhook extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }
        if ((string) config('meta.app_secret') === '') {
            $this->error('META_APP_SECRET is not set.');

            return self::FAILURE;
        }

        $body = json_encode(['object' => 'page', 'entry' => [['id' => (string) $this->argument('page_id'), 'time' => time(), 'changes' => [[
            'field' => 'leadgen',
            'value' => array_filter([
                'page_id' => (string) $this->argument('page_id'),
                'leadgen_id' => (string) $this->argument('leadgen_id'),
                'form_id' => $this->option('form_id'),
                'created_time' => time(),
            ]),
        ]]]]]);

        $response = Http::withBody($body, 'application/json')
            ->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) config('meta.app_secret'))])
            ->post($this->option('url') ?: MetaConfig::webhookUrl());

        $this->line('HTTP '.$response->status());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
