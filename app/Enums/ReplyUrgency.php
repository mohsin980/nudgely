<?php

namespace App\Enums;

enum ReplyUrgency: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
