<?php

namespace App\Services\Meta;

use App\Support\Redactor;
use Illuminate\Support\Facades\Log;

/**
 * Structured, sanitized logging for the Meta integration. Context is limited to identifiers and codes;
 * everything still passes through Redactor as a second line of defence. Never pass lead field values or tokens.
 */
class MetaLog
{
    /** @param  array<string, mixed>  $context */
    public static function info(string $event, array $context = []): void
    {
        Log::info('meta.'.$event, Redactor::redactArray($context));
    }

    /** @param  array<string, mixed>  $context */
    public static function warning(string $event, array $context = []): void
    {
        Log::warning('meta.'.$event, Redactor::redactArray($context));
    }

    /** @param  array<string, mixed>  $context */
    public static function error(string $event, array $context = []): void
    {
        Log::error('meta.'.$event, Redactor::redactArray($context));
    }
}
