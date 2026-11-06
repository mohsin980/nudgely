<?php

namespace App\Support;

/**
 * A short, human name for a browser's user agent, e.g. "Chrome on Windows". Best effort: anything
 * unrecognised falls back to "Unknown browser" / "unknown device".
 */
final class UserAgent
{
    /**
     * @return array{browser: string, os: string, label: string}
     */
    public static function describe(?string $agent): array
    {
        $agent = (string) $agent;

        // Order matters: Edge and Opera also say "Chrome", Chrome also says "Safari".
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return ['browser' => $browser, 'os' => $os, 'label' => "{$browser} on {$os}"];
    }
}
