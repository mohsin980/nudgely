<?php

namespace App\Services\Estimates\Import;

/**
 * The fields an estimate file can provide, and the header names that usually mean each of them.
 */
final class ImportColumns
{
    public const EXTERNAL_REFERENCE = 'external_reference';

    public const CUSTOMER_NAME = 'customer_name';

    public const CUSTOMER_EMAIL = 'customer_email';

    public const AMOUNT = 'amount';

    public const CURRENCY = 'currency';

    public const ESTIMATE_DATE = 'estimate_date';

    public const EXPIRATION_DATE = 'expiration_date';

    public const STATUS = 'status';

    public const DESCRIPTION = 'description';

    /** @var list<string> Fields the file must provide, one way or another, for a row to be imported. */
    public const REQUIRED = [self::CUSTOMER_NAME, self::AMOUNT];

    /**
     * @return array<string, array{label: string, aliases: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::EXTERNAL_REFERENCE => ['label' => 'Estimate ID or number', 'aliases' => ['estimate_id', 'estimate_number', 'estimate_no', 'quote_id', 'quote_number', 'external_id', 'reference', 'number']],
            self::CUSTOMER_NAME => ['label' => 'Customer name', 'aliases' => ['customer_name', 'customer', 'name', 'client', 'client_name', 'full_name']],
            self::CUSTOMER_EMAIL => ['label' => 'Customer email', 'aliases' => ['customer_email', 'email', 'email_address']],
            self::AMOUNT => ['label' => 'Estimate amount', 'aliases' => ['amount', 'total', 'estimate_amount', 'price', 'grand_total']],
            self::CURRENCY => ['label' => 'Currency', 'aliases' => ['currency']],
            self::ESTIMATE_DATE => ['label' => 'Estimate date', 'aliases' => ['estimate_date', 'date', 'created', 'created_date', 'issued_date']],
            self::EXPIRATION_DATE => ['label' => 'Expiration date', 'aliases' => ['expiration_date', 'expires', 'expiry_date', 'expires_on', 'valid_until']],
            self::STATUS => ['label' => 'Estimate status', 'aliases' => ['status', 'estimate_status']],
            self::DESCRIPTION => ['label' => 'Description', 'aliases' => ['description', 'title', 'job', 'job_description', 'work', 'notes']],
        ];
    }

    /**
     * Header names as people write them ("Customer Email", "customer-email") compare equal.
     */
    public static function normalize(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))), '_');
    }

    /**
     * The best header for each field, or null. Only exact (normalized) matches are suggested; the user confirms them.
     *
     * @param  list<string>  $headers
     * @return array<string, ?int> field => column index
     */
    public static function suggest(array $headers): array
    {
        $normalized = array_map(self::normalize(...), $headers);
        $map = [];

        foreach (self::all() as $field => $definition) {
            $map[$field] = null;

            foreach ($definition['aliases'] as $alias) {
                $index = array_search($alias, $normalized, true);

                if ($index !== false) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }
}
