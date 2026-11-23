<?php

namespace App\Services\WhatsApp;

use App\Support\Redactor;
use Illuminate\Support\Facades\Log;

/** Structured, sanitized logging for WhatsApp. Identifiers and codes only: never message text or phone numbers. */
class WhatsAppLog
{
    /** @param  array<string, mixed>  $context */
    public static function info(string $event, array $context = []): void
    {
        Log::info('whatsapp.'.$event, Redactor::redactArray($context));
    }

    /** @param  array<string, mixed>  $context */
    public static function warning(string $event, array $context = []): void
    {
        Log::warning('whatsapp.'.$event, Redactor::redactArray($context));
    }

    /** @param  array<string, mixed>  $context */
    public static function error(string $event, array $context = []): void
    {
        Log::error('whatsapp.'.$event, Redactor::redactArray($context));
    }
}
