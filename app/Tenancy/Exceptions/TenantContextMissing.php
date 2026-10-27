<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/** Thrown instead of silently returning unscoped data when no tenant is active. */
class TenantContextMissing extends RuntimeException
{
    public function __construct(string $message = 'No tenant context is active. Tenant-owned data cannot be accessed.')
    {
        parent::__construct($message);
    }
}
