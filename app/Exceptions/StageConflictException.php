<?php

namespace App\Exceptions;

use RuntimeException;

/** The lead was moved by someone else since the client last loaded it (optimistic concurrency). */
class StageConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This lead was moved by someone else. The board has been refreshed.');
    }
}
