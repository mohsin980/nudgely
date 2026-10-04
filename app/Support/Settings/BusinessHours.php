<?php

namespace App\Support\Settings;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Weekly opening hours in the organization's timezone (no holidays).
 *
 * Stored now so scheduling can respect them: nextOpening() is the extension point for moving
 * an automated send that falls outside business hours. The automation engine does not use it yet.
 */
final class BusinessHours
{
    public const DEFAULT = [
        'mon' => ['open' => true, 'start' => '08:00', 'end' => '17:00'],
        'tue' => ['open' => true, 'start' => '08:00', 'end' => '17:00'],
        'wed' => ['open' => true, 'start' => '08:00', 'end' => '17:00'],
        'thu' => ['open' => true, 'start' => '08:00', 'end' => '17:00'],
        'fri' => ['open' => true, 'start' => '08:00', 'end' => '17:00'],
        'sat' => ['open' => false, 'start' => '08:00', 'end' => '17:00'],
        'sun' => ['open' => false, 'start' => '08:00', 'end' => '17:00'],
    ];

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param  array<string, array{open: bool, start: string, end: string}>  $days
     */
    private function __construct(private readonly array $days) {}

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function fromArray(?array $value): self
    {
        $days = [];

        foreach (self::DEFAULT as $day => $default) {
            $given = is_array($value[$day] ?? null) ? $value[$day] : [];
            $start = is_string($given['start'] ?? null) && preg_match(self::TIME, $given['start']) ? $given['start'] : $default['start'];
            $end = is_string($given['end'] ?? null) && preg_match(self::TIME, $given['end']) ? $given['end'] : $default['end'];
            $days[$day] = ['open' => is_bool($given['open'] ?? null) ? $given['open'] : $default['open'], 'start' => $start, 'end' => $end];
        }

        return new self($days);
    }

    /**
     * Validate builder input; returns the normalized week or error messages keyed by day.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, array{open: bool, start: string, end: string}>, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $days = [];
        $errors = [];

        foreach (array_keys(self::DEFAULT) as $day) {
            $row = is_array($input[$day] ?? null) ? $input[$day] : [];
            $open = filter_var($row['open'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $start = (string) ($row['start'] ?? '');
            $end = (string) ($row['end'] ?? '');

            if ($open && (! preg_match(self::TIME, $start) || ! preg_match(self::TIME, $end) || $start >= $end)) {
                $errors[$day] = 'Enter opening and closing times, closing after opening.';
            }

            $days[$day] = ['open' => $open, 'start' => preg_match(self::TIME, $start) ? $start : self::DEFAULT[$day]['start'], 'end' => preg_match(self::TIME, $end) ? $end : self::DEFAULT[$day]['end']];
        }

        return [$days, $errors];
    }

    /**
     * @return array<string, array{open: bool, start: string, end: string}>
     */
    public function toArray(): array
    {
        return $this->days;
    }

    public function isOpenAt(DateTimeInterface $time, string $timezone): bool
    {
        $local = CarbonImmutable::instance($time)->setTimezone($timezone);
        $day = $this->days[strtolower($local->format('D'))];
        $clock = $local->format('H:i');

        return $day['open'] && $clock >= $day['start'] && $clock < $day['end'];
    }

    /**
     * The time itself when open, otherwise the start of the next open period (null when never open).
     */
    public function nextOpening(DateTimeInterface $time, string $timezone): ?CarbonImmutable
    {
        $local = CarbonImmutable::instance($time)->setTimezone($timezone);

        if ($this->isOpenAt($local, $timezone)) {
            return $local;
        }

        for ($i = 0; $i <= 7; $i++) {
            $date = $local->startOfDay()->addDays($i);
            $day = $this->days[strtolower($date->format('D'))];
            $opening = $date->setTimeFromTimeString($day['start']);

            if ($day['open'] && $opening->greaterThan($local)) {
                return $opening;
            }
        }

        return null;
    }
}
