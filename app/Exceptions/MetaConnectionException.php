<?php

namespace App\Exceptions;

use RuntimeException;

/** A connection/OAuth problem with a message that is safe to show to the tenant administrator. */
class MetaConnectionException extends RuntimeException {}
