<?php

namespace App\Enums;

enum ReplySentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
