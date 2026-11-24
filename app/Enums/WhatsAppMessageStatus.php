<?php

namespace App\Enums;

/** Outbound statuses only ever move forward (see rank()); "failed" is terminal; inbound messages are "received". */
enum WhatsAppMessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Received = 'received';

    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
            self::Received => 0,
        };
    }
}
