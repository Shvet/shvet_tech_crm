<?php

namespace App\Enums;

enum MetaConnectionStatus: string
{
    case PendingSelection = 'pending_selection';
    case Active = 'active';
    case ReauthorizationRequired = 'reauthorization_required';
    case Disconnected = 'disconnected';
}
