<?php

namespace App\Logging;

use App\Support\Facades\Tenancy;
use App\Support\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/** Redacts secrets/PII from message and context, and stamps the current tenant id. */
class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        if (($tenantId = Tenancy::id()) !== null) {
            $extra['tenant_id'] = $tenantId;
        }

        return $record->with(
            message: Redactor::redactString($record->message),
            context: Redactor::redactArray($record->context),
            extra: $extra,
        );
    }
}
