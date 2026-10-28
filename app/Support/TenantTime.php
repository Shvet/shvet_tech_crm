<?php

namespace App\Support;

use App\Support\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Dates are stored in UTC; users think in the tenant's timezone. All conversions go through here. */
class TenantTime
{
    public static function timezone(): string
    {
        return Tenancy::current()?->timezone ?? config('app.timezone');
    }

    /** Parse a user-entered local date-time ("2026-10-30T14:00" or "2026-10-30 14:00") in the tenant timezone → UTC. */
    public static function parseLocal(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::timezone())->utc();
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** UTC boundaries of "today" in the tenant timezone. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function todayBounds(?CarbonInterface $at = null): array
    {
        $local = ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now())->setTimezone(self::timezone());

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }
}
