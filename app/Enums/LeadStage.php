<?php

namespace App\Enums;

/**
 * Where a customer is in the sales journey. Moves forward on its own (first email or reply,
 * estimate sent, accepted or declined) and can always be set by hand.
 */
enum LeadStage: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Quoted = 'quoted';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function description(): string
    {
        return match ($this) {
            self::New => 'Not contacted yet.',
            self::Contacted => 'You have been in touch.',
            self::Quoted => 'An estimate was sent.',
            self::Won => 'Accepted an estimate.',
            self::Lost => 'Declined, or not going ahead.',
        };
    }

    /**
     * Badge colours (Tailwind classes).
     */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::Contacted => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            self::Quoted => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::Won => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Lost => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        };
    }

    /**
     * Won and lost are outcomes: automatic changes never move a customer out of them.
     */
    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /**
     * Position in the journey, for "only ever move forward".
     */
    public function rank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Contacted => 1,
            self::Quoted => 2,
            self::Won, self::Lost => 3,
        };
    }
}
