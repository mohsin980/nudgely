<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads an uploaded CSV file safely: UTF-8 only (Excel's byte-order mark is removed), bounded in size and rows,
 * every cell trimmed plain text, blank rows skipped.
 */
final class CsvFile
{
    /**
     * @param  string  $noun  what each row is, plural ("customers"), for the messages
     * @return array{headers: list<string>, rows: array<int, list<string>>} rows keyed by their row number (1 = first under the header)
     *
     * @throws RuntimeException with a message for the person uploading
     */
    public static function read(string $path, int $maxBytes, int $maxRows, string $noun): array
    {
        if (filesize($path) > $maxBytes) {
            throw new RuntimeException('The file is larger than '.intdiv($maxBytes, 1024 * 1024).' MB. Split it into smaller files.');
        }

        $handle = fopen($path, 'rb') ?: throw new RuntimeException('The file could not be read. Upload it again.');
        $headers = null;
        $rows = [];
        $number = 0;

        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $cells = array_map(fn ($cell) => trim((string) $cell), $cells ?? []);

                foreach ($cells as $cell) {
                    if (! mb_check_encoding($cell, 'UTF-8')) {
                        throw new RuntimeException('The file is not UTF-8 encoded. In Excel choose "CSV UTF-8" when you save it, then try again.');
                    }
                }

                if ($headers === null) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '') ?? ''; // Excel's byte-order mark
                    $headers = $cells;

                    continue;
                }

                $number++;

                if (array_filter($cells, fn (string $cell) => $cell !== '') === []) {
                    continue;
                }

                if (count($rows) >= $maxRows) {
                    throw new RuntimeException('This file has more than '.number_format($maxRows).' '.$noun.'. Split it into smaller files and import each one.');
                }

                $rows[$number] = $cells;
            }
        } finally {
            fclose($handle);
        }

        if ($headers === null || array_filter($headers, fn (string $h) => $h !== '') === []) {
            throw new RuntimeException('The file is empty. The first row must contain the column names.');
        }

        if ($rows === []) {
            throw new RuntimeException('The file has a header row but no '.$noun.' under it.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Header text reduced to letters and digits, so "E-mail Address", "email_address" and "EmailAddress" all match.
     */
    public static function key(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($header));
    }
}
