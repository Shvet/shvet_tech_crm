<?php

namespace App\Enums;

enum MetaEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Imported = 'imported';
    case NeedsReview = 'needs_review';
    case Dismissed = 'dismissed';
    /** No enabled mapping for the form; becomes replayable once an admin maps it. */
    case Ignored = 'ignored';
    case Failed = 'failed';
    /** Page unknown to every tenant: stored for operators, never attributed to a tenant. */
    case Quarantined = 'quarantined';

    /** Statuses an admin may send back through the importer. */
    public function isReplayable(): bool
    {
        return in_array($this, [self::Failed, self::Ignored], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Imported, self::Dismissed, self::Quarantined], true);
    }
}
