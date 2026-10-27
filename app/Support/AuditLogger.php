<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Support\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata  Redacted before storing; never pass secrets anyway.
     */
    public static function record(string $action, ?Model $subject = null, array $metadata = [], ?int $tenantId = null): void
    {
        $tenantId ??= Tenancy::id();

        Tenancy::withoutScope(fn () => AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : Redactor::redactArray($metadata),
            'ip_address' => request()?->ip(),
            'user_agent' => request() ? substr((string) request()->userAgent(), 0, 255) : null,
        ]));
    }
}
