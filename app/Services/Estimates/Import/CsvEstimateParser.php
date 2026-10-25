<?php

namespace App\Services\Estimates\Import;

use App\Exceptions\Estimates\EstimateImportException;
use Generator;

/**
 * Reads an estimate CSV safely: UTF-8 only, comma separated, bounded in size and rows, and never evaluated.
 * Cell values are plain text. Nothing in the file is executed or fetched.
 */
final class CsvEstimateParser
{
    /**
     * @return array{headers: list<string>, rows: int}
     *
     * @throws EstimateImportException
     */
    public function inspect(string $path): array
    {
        $headers = null;
        $rows = 0;

        foreach ($this->lines($path) as $line) {
            if ($headers === null) {
                $headers = $this->headers($line);

                continue;
            }

            if ($this->isBlank($line)) {
                continue;
            }

            $rows++;

            if ($rows > $this->maxRows()) {
                throw new EstimateImportException('This file has more than '.number_format($this->maxRows()).' rows. Split it into smaller files and import each one.');
            }
        }

        if ($headers === null) {
            throw new EstimateImportException('The file is empty. Export your estimates as a CSV file and try again.');
        }

        if ($rows === 0) {
            throw new EstimateImportException('The file has a header row but no estimates under it.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Each data row with its position (1 is the first row under the header). Blank rows are skipped but keep their number.
     *
     * @return Generator<int, list<string>>
     *
     * @throws EstimateImportException
     */
    public function rows(string $path): Generator
    {
        $headers = null;
        $number = 0;

        foreach ($this->lines($path) as $line) {
            if ($headers === null) {
                $headers = $this->headers($line);

                continue;
            }

            $number++;

            if ($this->isBlank($line)) {
                continue;
            }

            yield $number => $line;
        }
    }

    /**
     * @return Generator<int, list<string>> one line of cells per CSV row, already checked for encoding and size
     *
     * @throws EstimateImportException
     */
    private function lines(string $path): Generator
    {
        if (filesize($path) > $this->maxBytes()) {
            throw new EstimateImportException('The file is larger than '.intdiv($this->maxBytes(), 1024 * 1024).' MB. Split it into smaller files.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new EstimateImportException('The file could not be read. Upload it again.');
        }

        try {
            $first = true;

            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $cells = array_map(fn ($cell) => (string) $cell, $cells ?? []);

                if ($first) {
                    // Excel adds a byte-order mark to UTF-8 files; it is not part of the first header.
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0] ?? '') ?? '';
                    $first = false;
                }

                foreach ($cells as $cell) {
                    if (! mb_check_encoding($cell, 'UTF-8')) {
                        throw new EstimateImportException('The file is not UTF-8 encoded. In Excel choose "CSV UTF-8" when you save it, then try again.');
                    }
                }

                yield array_map(fn (string $cell) => trim($cell), $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $cells
     * @return list<string> the header row exactly as it appears, so column positions match the data rows
     *
     * @throws EstimateImportException
     */
    private function headers(array $cells): array
    {
        $named = array_values(array_filter(array_map(ImportColumns::normalize(...), $cells), fn (string $name) => $name !== ''));

        if ($named === []) {
            throw new EstimateImportException('The first row must contain the column names.');
        }

        if (count(array_unique($named)) !== count($named)) {
            throw new EstimateImportException('Two columns have the same name. Rename one of them so each column is unique.');
        }

        return $cells;
    }

    /** @param  list<string>  $cells */
    private function isBlank(array $cells): bool
    {
        return array_filter($cells, fn (string $cell) => $cell !== '') === [];
    }

    private function maxRows(): int
    {
        return (int) config('estimates.import.max_rows', 5000);
    }

    private function maxBytes(): int
    {
        return (int) config('estimates.import.max_bytes', 5 * 1024 * 1024);
    }
}
