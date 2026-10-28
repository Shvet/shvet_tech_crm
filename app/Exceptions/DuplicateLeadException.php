<?php

namespace App\Exceptions;

use App\Models\Lead;
use Illuminate\Support\Collection;
use RuntimeException;

/** Thrown when a submission matches existing leads and the user has not confirmed it is intentional. */
class DuplicateLeadException extends RuntimeException
{
    /** @param Collection<int, Lead> $matches */
    public function __construct(public readonly Collection $matches)
    {
        parent::__construct('A lead with the same phone number or e-mail already exists.');
    }
}
