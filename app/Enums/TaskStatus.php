<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
}
