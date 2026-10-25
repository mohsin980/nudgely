<?php

namespace App\Services\Estimates\Import;

use App\Enums\EstimateStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Checks one row of an estimate file. Errors reject the row; warnings import it and tell the business what to know.
 *
 * Imported rows never carry a "sent" status forward as fact: the file's status is kept for reference, and the
 * estimate is always imported as a draft.
 */
final class EstimateImportRowValidator
{
    /** The largest amount accepted, in minor units ($1,000,000.00). */
    public const MAX_AMOUNT = 100_000_000;

    private const DATE_FORMATS = ['!Y-m-d', '!n/j/Y', '!n/j/y'];

    /**
     * @param  array<string, string>  $values  mapped values, keyed by ImportColumns field
     * @return array{data: ?array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    public function validate(array $values, string $currency): array
    {
        $errors = [];
        $warnings = [];

        $name = $this->text($values, ImportColumns::CUSTOMER_NAME);

        if ($name === '') {
            $errors[] = 'Customer name is missing.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'Customer name is longer than 200 characters.';
        }

        $email = strtolower($this->text($values, ImportColumns::CUSTOMER_EMAIL));

        if ($email === '') {
            $warnings[] = 'No email address. The customer is imported, but cannot receive email follow-ups.';
        } elseif (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "Customer email \"{$email}\" is not a valid email address.";
        }

        $amount = $this->amount($values[ImportColumns::AMOUNT] ?? '');

        if ($amount === null) {
            $errors[] = 'Estimate amount must be a number from 0.01 to 1,000,000.00, for example 2750 or 2,750.00.';
        }

        $fileCurrency = strtoupper($this->text($values, ImportColumns::CURRENCY));

        if ($fileCurrency !== '' && $fileCurrency !== $currency) {
            $errors[] = "Currency {$fileCurrency} does not match this business's currency ({$currency}).";
        }

        $issued = $this->date($values, ImportColumns::ESTIMATE_DATE, $errors, 'Estimate date');
        $expires = $this->date($values, ImportColumns::EXPIRATION_DATE, $errors, 'Expiration date');

        if ($issued !== null && $expires !== null && $expires < $issued) {
            $errors[] = 'The expiration date is before the estimate date.';
        }

        if ($expires !== null && $expires < CarbonImmutable::today()->toDateString()) {
            $warnings[] = 'The expiration date has passed. It is imported, but it must be changed before the estimate can be sent.';
        }

        $importedStatus = strtolower($this->text($values, ImportColumns::STATUS));

        if ($importedStatus !== '' && ! in_array($importedStatus, array_column(EstimateStatus::cases(), 'value'), true)) {
            $errors[] = "Status \"{$importedStatus}\" is not one we recognise. Use one of: ".implode(', ', array_column(EstimateStatus::cases(), 'value')).'.';
        }

        if ($importedStatus !== '' && $importedStatus !== EstimateStatus::Draft->value && $importedStatus !== 'sent') {
            $warnings[] = "Imported as a draft. The file says \"{$importedStatus}\", which is kept for reference.";
        } elseif ($importedStatus === 'sent') {
            $warnings[] = 'Imported as a draft. The file says "sent", but nothing is recorded as sent. Send it from the estimate page when you are ready.';
        }

        $reference = $this->text($values, ImportColumns::EXTERNAL_REFERENCE);

        if ($reference === '') {
            $warnings[] = 'No estimate ID. Duplicates are checked by customer, amount and title instead.';
        } elseif (mb_strlen($reference) > 120) {
            $errors[] = 'Estimate ID is longer than 120 characters.';
        }

        $description = $this->text($values, ImportColumns::DESCRIPTION);

        if (mb_strlen($description) > 200) {
            $errors[] = 'Description is longer than 200 characters.';
        }

        if ($errors !== []) {
            return ['data' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        return [
            'data' => [
                'name' => $name,
                'email' => $email === '' ? null : $email,
                'amount' => $amount,
                'currency' => $currency,
                'estimate_date' => $issued,
                'expiration_date' => $expires,
                'imported_status' => $importedStatus === '' ? null : $importedStatus,
                'description' => $description === '' ? 'Imported estimate' : $description,
                'external_reference' => $reference === '' ? null : $reference,
            ],
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * The amount in minor units, or null when it is not a usable money amount.
     */
    public function amount(string $raw): ?int
    {
        $cleaned = str_replace(['$', ',', ' '], '', trim($raw));
        $minor = Money::tryParse($cleaned);

        return $minor !== null && $minor > 0 && $minor <= self::MAX_AMOUNT ? $minor : null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function text(array $values, string $field): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) ($values[$field] ?? '')));
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function date(array $values, string $field, array &$errors, string $label): ?string
    {
        $raw = $this->text($values, $field);

        if ($raw === '') {
            return null;
        }

        // The native parser returns false on a mismatch (Carbon's throws), so each format is simply tried in turn.
        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);

            if ($date !== false && $date->format('Y') >= '2000' && $date->format('Y') <= '2100') {
                return $date->format('Y-m-d');
            }
        }

        $errors[] = "{$label} \"{$raw}\" is not a date. Use 2026-10-25 or 10/25/2026.";

        return null;
    }
}
