<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Note = 'note';
    case Email = 'email';
    case Other = 'other';

    /** Types that mean "we actually spoke to / met the lead" and therefore update last_contacted_at. */
    public function countsAsContact(): bool
    {
        return in_array($this, [self::Call, self::Meeting, self::Email], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
