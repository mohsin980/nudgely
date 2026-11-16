<?php

namespace App\Services\Outreach;

use App\Enums\Platform\PlatformPermission;
use App\Models\OutreachCategory;
use App\Models\OutreachContact;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bulk import of contacts from rows (already read from a CSV). A row is imported only with a consent source, and
 * only if it has a valid email, a valid phone number (E.164), or both. Duplicates are skipped, never merged.
 */
class OutreachContactImporter
{
    public const PHONE_PATTERN = '/^\+[1-9]\d{6,14}$/';

    /**
     * @param  list<array<string, ?string>>  $rows  keys: name, email, phone, consent_source, consent_date
     * @return array{created: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function import(User $actor, OutreachCategory $category, array $rows): array
    {
        if (! AdminAccess::allows($actor, PlatformPermission::ManageOutreachContacts)) {
            throw new AuthorizationException('You are not allowed to import contacts.');
        }

        $max = (int) config('outreach.import_max_rows');

        if (count($rows) > $max) {
            throw ValidationException::withMessages(['file' => "A file can hold at most {$max} rows. Split it and import again."]);
        }

        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // row 1 is the header
            $error = $this->problem($row);

            if ($error !== null) {
                $result['errors'][] = ['row' => $line, 'message' => $error];

                continue;
            }

            $email = filled($row['email'] ?? null) ? mb_strtolower(trim($row['email'])) : null;
            $phone = filled($row['phone'] ?? null) ? trim($row['phone']) : null;

            if (($email && OutreachContact::query()->where('email', $email)->exists())
                || ($phone && OutreachContact::query()->where('phone', $phone)->exists())) {
                $result['skipped']++;

                continue;
            }

            OutreachContact::query()->create([
                'outreach_category_id' => $category->id,
                'name' => filled($row['name'] ?? null) ? trim($row['name']) : null,
                'email' => $email,
                'phone' => $phone,
                'consent_source' => trim((string) $row['consent_source']),
                'consent_at' => $this->consentDate($row['consent_date'] ?? null),
            ]);

            $result['created']++;
        }

        return $result;
    }

    /** @param  array<string, ?string>  $row  */
    private function problem(array $row): ?string
    {
        if (blank($row['consent_source'] ?? null)) {
            return 'Missing consent source. Say where this person agreed to hear from you.';
        }

        if (blank($row['email'] ?? null) && blank($row['phone'] ?? null)) {
            return 'Needs an email or a phone number.';
        }

        $validator = Validator::make($row, [
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'regex:'.self::PHONE_PATTERN],
            'consent_source' => ['required', 'string', 'max:190'],
            'consent_date' => ['nullable', 'date'],
        ], [
            'phone.regex' => 'Phone must be in international format, for example +923001234567.',
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    private function consentDate(?string $date): Carbon
    {
        return filled($date) ? Carbon::parse($date) : now();
    }
}
