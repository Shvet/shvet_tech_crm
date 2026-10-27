<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/** Logging "tap": attaches RedactingProcessor to every handler of a channel. */
class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (method_exists($monolog, 'pushProcessor')) {
            $monolog->pushProcessor(new RedactingProcessor);
        }
    }
}
