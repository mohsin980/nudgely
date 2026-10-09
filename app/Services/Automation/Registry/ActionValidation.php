<?php

namespace App\Services\Automation\Registry;

use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\CustomerTag;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automation\EmailTemplateRenderer;
use InvalidArgumentException;

/**
 * Helpers the action rules use to validate settings. Every failure throws an
 * InvalidArgumentException whose message is safe to show.
 */
final class ActionValidation
{
    public function __construct(
        public readonly ?TriggerDefinition $trigger,
        public readonly ?Organization $organization,
        private readonly EmailTemplateRenderer $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function text(array $config, string $key, int $max, string $missing = '', bool $required = true): ?string
    {
        $value = is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';

        if ($value === '') {
            return $required ? throw new InvalidArgumentException($missing) : null;
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key))." may be up to {$max} characters.");
        }

        return $value;
    }

    /**
     * Text with {{variables}}: only registered variables available for the trigger.
     *
     * @param  array<string, mixed>  $config
     */
    public function template(array $config, string $key, int $max, string $missing, bool $required = true): ?string
    {
        $value = $this->text($config, $key, $max, $missing, $required);

        if ($value !== null) {
            try {
                $this->templates->validateFor($value, $this->trigger);
            } catch (InvalidEmailTemplateException $e) {
                throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)).': '.$e->getMessage());
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $allowed
     */
    public function choice(array $config, string $key, array $allowed, ?string $default = null): string
    {
        $value = (string) ($config[$key] ?? $default ?? '');

        return in_array($value, $allowed, true) ? $value : throw new InvalidArgumentException('Choose a valid '.str_replace('_', ' ', $key).'.');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function integer(array $config, string $key, int $min, int $max, bool $required = true, ?string $label = null): ?int
    {
        $value = $config[$key] ?? null;
        $label ??= ucfirst(str_replace('_', ' ', $key));

        if ($value === null || $value === '') {
            return $required ? throw new InvalidArgumentException("Enter {$label}.") : null;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,5}$/', $value))) {
            throw new InvalidArgumentException("{$label} must be a whole number from {$min} to {$max}.");
        }

        $value = (int) $value;

        return $value >= $min && $value <= $max ? $value : throw new InvalidArgumentException("{$label} must be a whole number from {$min} to {$max}.");
    }

    /**
     * "owner" (the automation's owner), a member's ID, or null (nobody / everyone).
     *
     * @param  array<string, mixed>  $config
     */
    public function user(array $config, string $key): string|int|null
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if ($value === 'owner') {
            return 'owner';
        }

        if (! ctype_digit((string) $value) || ($this->organization !== null
            && ! User::query()->activeIn($this->organization->id)->whereKey((int) $value)->exists())) {
            throw new InvalidArgumentException('Choose an active person in your organization.');
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function tag(array $config, bool $mustExist = false): string
    {
        $tag = $this->text($config, 'tag', 50, 'Enter a tag.');

        if (($slug = CustomerTag::slugFor($tag)) === null) {
            throw new InvalidArgumentException('The tag must contain letters or numbers.');
        }

        if ($mustExist && $this->organization !== null && ! $this->organization->customerTags()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("No customer has the tag “{$tag}” yet.");
        }

        return $tag;
    }
}
