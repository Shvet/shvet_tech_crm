<?php

namespace App\Enums;

enum WhatsAppAccountStatus: string
{
    case Active = 'active';
    case ReauthorizationRequired = 'reauthorization_required';
    case Disconnected = 'disconnected';
}
