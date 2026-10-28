<?php

namespace App\Exceptions;

use App\Support\Redactor;
use RuntimeException;

/**
 * A failed call to the Meta Graph API, reduced to a category plus a SANITIZED summary.
 * It never carries a URL, token, request body or Meta's free-text message (which can echo user data),
 * so it is safe to log, store on an event row and show to an administrator.
 */
class MetaApiException extends RuntimeException
{
    public const AUTH = 'auth';              // token invalid / expired / revoked / app removed  -> re-authorize

    public const PERMISSION = 'permission';  // permission or lead-access missing                 -> fix grants, then replay

    public const RATE_LIMIT = 'rate_limit';  // back off and retry (honor Retry-After)

    public const TRANSIENT = 'transient';    // timeouts, 5xx, temporary errors                   -> retry with backoff

    public const NOT_FOUND = 'not_found';    // object gone / not accessible (e.g. lead older than Meta's retention)

    public const INVALID = 'invalid_request';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $category,
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $traceId = null,
    ) {
        parent::__construct($this->buildSummary());
    }

    public function isRetryable(): bool
    {
        return in_array($this->category, [self::RATE_LIMIT, self::TRANSIENT, self::UNKNOWN], true);
    }

    public function requiresReauthorization(): bool
    {
        return $this->category === self::AUTH;
    }

    /** Stable machine code stored on events/connections, e.g. "meta_auth_190". */
    public function code(): string
    {
        return 'meta_'.$this->category.($this->metaCode ? '_'.$this->metaCode : '');
    }

    /** Administrator-facing, actionable wording. */
    public function adminMessage(): string
    {
        return match ($this->category) {
            self::AUTH => 'Meta rejected the saved authorization (expired, revoked or the user lost access). Reconnect the integration.',
            self::PERMISSION => 'Meta denied access. Check that the connected user still manages the Page and that the app has lead access (Business Settings → Leads Access).',
            self::RATE_LIMIT => 'Meta is rate limiting requests. The import will be retried automatically.',
            self::TRANSIENT => 'Meta was temporarily unavailable. The import will be retried automatically.',
            self::NOT_FOUND => 'Meta could not find or would not return this lead (it may be deleted, too old, or not accessible to the app).',
            self::INVALID => 'Meta rejected the request as invalid. This usually means a configuration problem; see the diagnostics.',
            default => 'Meta returned an unexpected error. The import will be retried a limited number of times.',
        };
    }

    private function buildSummary(): string
    {
        $parts = array_filter([
            'category='.$this->category,
            $this->httpStatus ? 'http='.$this->httpStatus : null,
            $this->metaCode ? 'code='.$this->metaCode : null,
            $this->metaSubcode ? 'subcode='.$this->metaSubcode : null,
            $this->traceId ? 'trace='.preg_replace('/[^A-Za-z0-9_\-]/', '', $this->traceId) : null,
        ]);

        return Redactor::redactString(implode(' ', $parts));
    }
}
