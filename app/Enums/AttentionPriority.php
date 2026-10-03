<?php

namespace App\Enums;

enum AttentionPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value).' priority';
    }

    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Medium => 1,
            self::Low => 2,
        };
    }

    public static function max(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }
}
