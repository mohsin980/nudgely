<?php

namespace App\Services\Customers\Import;

use App\Exceptions\Conversations\DuplicateCustomerException;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Support\CsvFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports customers from a CSV file.
 *
 * The file is read safely (see CsvFile) and every row
 * goes through CustomerService, so the usual rules apply: required fields, unique emails and the plan's
 * customer limit. Existing customers are skipped, never changed.
 */
class CustomerImportService
{
    public const MAX_ROWS = 1000;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, array{label: string, aliases: list<string>}> */
    public const FIELDS = [
        'first_name' => ['label' => 'First name', 'aliases' => ['first_name', 'firstname', 'first', 'given_name']],
        'last_name' => ['label' => 'Last name', 'aliases' => ['last_name', 'lastname', 'last', 'surname', 'family_name']],
        'name' => ['label' => 'Full name', 'aliases' => ['name', 'full_name', 'customer_name', 'customer', 'client', 'client_name', 'contact', 'contact_name']],
        'email' => ['label' => 'Email', 'aliases' => ['email', 'email_address', 'e_mail', 'customer_email']],
        'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'phone_number', 'mobile', 'cell', 'telephone', 'tel']],
        'company' => ['label' => 'Company', 'aliases' => ['company', 'company_name', 'business', 'organization', 'organisation']],
        'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'note', 'comments', 'comment']],
        'whatsapp_consent' => ['label' => 'WhatsApp consent (yes/no)', 'aliases' => ['whatsapp_consent', 'whatsapp_opt_in', 'whatsapp_optin', 'whatsapp', 'opt_in']],
    ];

    public function __construct(private readonly CustomerService $customers) {}

    /**
     * @return array{headers: list<string>, rows: array<int, list<string>>} rows keyed by their row number (1 = first under the header)
     *
     * @throws RuntimeException with a message for the person importing
     */
    public function read(string $path): array
    {
        return CsvFile::read($path, self::MAX_BYTES, self::MAX_ROWS, 'customers');
    }

    /**
     * The most likely column for each field, or null. Headers like "E-mail Address" and "email_address" match.
     *
     * @param  list<string>  $headers
     * @return array<string, ?int>
     */
    public function suggest(array $headers): array
    {
        $key = CsvFile::key(...);
        $normalized = array_map($key, $headers);
        $map = [];

        foreach (self::FIELDS as $field => $definition) {
            $map[$field] = null;

            foreach ($definition['aliases'] as $alias) {
                if (($index = array_search($key($alias), $normalized, true)) !== false && ! in_array($index, $map, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * One row as customer input. A full name fills first and last name when those columns aren't used.
     *
     * @param  list<string>  $cells
     * @param  array<string, ?int>  $map
     * @return array{first_name: string, last_name: string, email: string, phone: string, company: string, notes: string, whatsapp_consent?: bool}
     */
    public function input(array $cells, array $map): array
    {
        $cell = fn (string $field) => $map[$field] === null ? '' : ($cells[$map[$field]] ?? '');
        $first = $cell('first_name');
        $last = $cell('last_name');

        if ($first === '' && $last === '' && ($full = preg_replace('/\s+/', ' ', $cell('name'))) !== '') {
            [$first, $last] = array_pad(explode(' ', $full, 2), 2, '');
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $cell('email'),
            'phone' => $cell('phone'),
            'company' => $cell('company'),
            'notes' => $cell('notes'),
        ] + ($map['whatsapp_consent'] === null ? [] : [
            // Only "yes"-like values count as consent; anything else (blank, "no") does not.
            'whatsapp_consent' => in_array(strtolower(trim($cell('whatsapp_consent'))), ['yes', 'y', 'true', '1', 'x', 'agreed'], true),
        ]);
    }

    /**
     * @param  array<int, list<string>>  $rows
     * @param  array<string, ?int>  $map
     * @return array{created: int, duplicates: int, failed: list<array{row: int, message: string}>}
     */
    public function import(User $actor, array $rows, array $map, bool $runAutomations): array
    {
        $result = ['created' => 0, 'duplicates' => 0, 'failed' => []];

        foreach ($rows as $number => $cells) {
            try {
                $this->customers->create($actor, $this->input($cells, $map), $runAutomations);
                $result['created']++;
            } catch (DuplicateCustomerException) {
                $result['duplicates']++;
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first() ?? 'This row could not be imported.';
                $result['failed'][] = ['row' => $number + 1, 'message' => $message]; // +1: the header is line 1 in a spreadsheet

                if (str_contains($message, 'plan allows up to')) { // PlanLimitException, passed on by CustomerService
                    // The plan's customer limit was reached: the remaining rows would fail the same way.
                    $remaining = count($rows) - $result['created'] - $result['duplicates'] - count($result['failed']);

                    if ($remaining > 0) {
                        $result['failed'][] = ['row' => 0, 'message' => trans_choice(':count more row was not imported because of the plan limit.|:count more rows were not imported because of the plan limit.', $remaining)];
                    }

                    break;
                }
            }
        }

        return $result;
    }
}
