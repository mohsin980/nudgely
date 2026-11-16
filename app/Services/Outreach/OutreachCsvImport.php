<?php

namespace App\Services\Outreach;

use App\Support\CsvFile;

/**
 * Reads an outreach contact CSV into rows keyed by field. Column names are matched loosely (case, spaces and dashes
 * don't matter), so "E-mail", "email" and "Email address" all work.
 */
class OutreachCsvImport
{
    public const FIELDS = ['name', 'email', 'phone', 'consent_source', 'consent_date'];

    private const ALIASES = [
        'name' => ['name', 'full_name', 'fullname', 'contact', 'contact_name'],
        'email' => ['email', 'email_address', 'e_mail', 'mail'],
        'phone' => ['phone', 'phone_number', 'mobile', 'whatsapp', 'whatsapp_number', 'number'],
        'consent_source' => ['consent_source', 'consent', 'source', 'opt_in_source'],
        'consent_date' => ['consent_date', 'consented_at', 'opt_in_date', 'date'],
    ];

    /**
     * @return array{rows: list<array<string, ?string>>, missing: list<string>}
     */
    public function parse(string $path): array
    {
        $file = CsvFile::read($path, (int) 5 * 1024 * 1024, (int) config('outreach.import_max_rows'), 'contacts');

        $index = [];

        foreach (self::FIELDS as $field) {
            $index[$field] = null;

            foreach ($file['headers'] as $position => $header) {
                if (in_array($this->normalize($header), self::ALIASES[$field], true)) {
                    $index[$field] = $position;
                    break;
                }
            }
        }

        $missing = array_values(array_filter(['email', 'consent_source'], fn (string $field) => $index[$field] === null));

        if ($missing !== []) {
            return ['rows' => [], 'missing' => $missing];
        }

        $rows = [];

        foreach ($file['rows'] as $cells) {
            $row = [];

            foreach (self::FIELDS as $field) {
                $value = $index[$field] === null ? null : ($cells[$index[$field]] ?? null);
                $row[$field] = filled($value) ? trim((string) $value) : null;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'missing' => []];
    }

    private function normalize(string $header): string
    {
        return trim(str_replace([' ', '-'], '_', mb_strtolower($header)));
    }
}
