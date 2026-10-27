<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/** The tenant a queued job belongs to no longer exists or is not active. */
class TenantUnavailable extends RuntimeException {}
