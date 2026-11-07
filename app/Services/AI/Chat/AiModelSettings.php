<?php

namespace App\Services\AI\Chat;

use App\Enums\Platform\PlatformPermission;
use App\Models\AiModelKey;
use App\Models\AiModelSetting;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The AI models as they are actually used: config/ai.php (and .env) with the platform administrator's settings on
 * top. An administrator may set each model's name and address, switch it off, change the order, and give it
 * several API keys. Keys are tried in order: the admin's (oldest first), then .env's (GEMINI_API_KEY may hold
 * several, separated by commas). A field left empty in the admin falls back to .env.
 */
class AiModelSettings
{
    public const MAX_KEYS = 20;

    /**
     * Each switched-on model's settings, in the order they are tried.
     *
     * @return array<string, array<string, mixed>> name => config
     */
    public function chain(): array
    {
        return array_filter($this->all(), fn (array $model) => $model['enabled']);
    }

    /**
     * Every model, in order, with its keys.
     *
     * @return array<string, array<string, mixed>> name => config + enabled, position, keys: list<array{id: string, api_key: string, hint: string, label: ?string, source: string}>
     */
    public function all(): array
    {
        $definitions = (array) config('ai.models', []);
        $stored = AiModelSetting::query()->get()->keyBy('provider');
        $adminKeys = AiModelKey::query()->orderBy('id')->get()->groupBy('provider');
        $envOrder = array_values(array_intersect((array) config('ai.chain', []), array_keys($definitions)));
        $models = [];

        foreach ($definitions as $name => $definition) {
            $setting = $stored->get($name);
            $envPosition = array_search($name, $envOrder, true);

            $models[$name] = [
                ...$definition,
                'model' => filled($setting?->model) ? $setting->model : ($definition['model'] ?? null),
                'base_url' => filled($setting?->base_url) ? $setting->base_url : ($definition['base_url'] ?? null),
                // Not in AI_MODEL_CHAIN and never switched on by an admin: off.
                'enabled' => $setting !== null ? $setting->enabled : $envPosition !== false,
                'position' => $setting?->position ?? (($envPosition === false ? 100 : $envPosition) * 10),
                'keys' => $this->keys($adminKeys->get($name, collect())->all(), $definition['api_key'] ?? null),
            ];
        }

        uasort($models, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $models;
    }

    /**
     * @param  array{enabled?: bool, model?: ?string, base_url?: ?string}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(string $name, array $input, User $actor): void
    {
        $this->authorize($actor);
        $this->assertKnown($name);

        $data = Validator::make($input, [
            'enabled' => ['boolean'],
            'model' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9._:\/@-]+$/'],
            'base_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ], [
            'model.regex' => 'Use the model\'s ID exactly as the provider writes it, e.g. gemini-2.5-flash.',
        ])->validate();

        DB::transaction(function () use ($name, $data, $actor) {
            $setting = $this->setting($name, lock: true);
            $current = $this->all()[$name];

            if (! $setting->exists) {
                $setting->position = $current['position'];
                $setting->enabled = $current['enabled'];
            }

            foreach (['enabled', 'model', 'base_url'] as $field) {
                if (array_key_exists($field, $data)) {
                    $setting->{$field} = $field === 'enabled' ? (bool) $data[$field] : (filled($data[$field]) ? trim($data[$field]) : null);
                }
            }

            $setting->updated_by = $actor->id;
            $setting->save();

            PlatformAuditLog::record('ai_model_updated', $actor, changes: ['provider' => $name, 'fields' => array_keys($data)], targetType: 'ai_model', targetId: $name);
        });
    }

    /**
     * Add an API key to a provider. It is used after the provider's other keys.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function addKey(string $name, string $key, ?string $label, User $actor): AiModelKey
    {
        $this->authorize($actor);
        $this->assertKnown($name);

        $data = Validator::make(['api_key' => trim($key), 'label' => trim((string) $label)], [
            'api_key' => ['required', 'string', 'min:8', 'max:500', 'regex:/^\S+$/'],
            'label' => ['nullable', 'string', 'max:60'],
        ], ['api_key.regex' => 'The key cannot contain spaces.'])->validate();

        if (AiModelKey::query()->where('provider', $name)->count() >= self::MAX_KEYS) {
            throw ValidationException::withMessages(['api_key' => 'A provider can have up to '.self::MAX_KEYS.' keys.']);
        }

        $record = new AiModelKey;
        $record->provider = $name;
        $record->label = filled($data['label']) ? $data['label'] : null;
        $record->api_key = $data['api_key'];
        $record->fingerprint = hash('sha256', $data['api_key']);
        $record->created_by = $actor->id;

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['api_key' => 'This key is already saved for this provider.']);
        }

        // Which key, never the key itself.
        PlatformAuditLog::record('ai_model_key_added', $actor, changes: ['provider' => $name, 'key' => $record->hint(), 'label' => $record->label], targetType: 'ai_model', targetId: $name);

        return $record;
    }

    /**
     * @throws AuthorizationException
     */
    public function removeKey(string $name, int $keyId, User $actor): void
    {
        $this->authorize($actor);

        $record = AiModelKey::query()->where('provider', $name)->whereKey($keyId)->first();

        if ($record === null) {
            return;
        }

        $hint = $record->hint();
        $record->delete();

        PlatformAuditLog::record('ai_model_key_removed', $actor, changes: ['provider' => $name, 'key' => $hint], targetType: 'ai_model', targetId: $name);
    }

    /**
     * Move a model one place earlier (-1) or later (+1) in the order.
     *
     * @throws AuthorizationException
     */
    public function move(string $name, int $direction, User $actor): void
    {
        $this->authorize($actor);

        $current = $this->all();
        $names = array_keys($current);
        $from = array_search($name, $names, true);
        $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

        if ($from === false || $to < 0 || $to >= count($names)) {
            return;
        }

        [$names[$from], $names[$to]] = [$names[$to], $names[$from]];

        DB::transaction(function () use ($names, $current, $actor, $name) {
            foreach ($names as $index => $provider) {
                $setting = $this->setting($provider);

                if (! $setting->exists) {
                    $setting->enabled = $current[$provider]['enabled'];
                }

                $setting->position = $index * 10;
                $setting->save();
            }

            PlatformAuditLog::record('ai_model_reordered', $actor, changes: ['provider' => $name, 'order' => $names], targetType: 'ai_model', targetId: $name);
        });
    }

    /**
     * The admin's keys, then .env's (comma-separated), without repeats.
     *
     * @param  list<AiModelKey>  $adminKeys
     * @return list<array{id: string, api_key: string, hint: string, label: ?string, source: string, key_id: ?int}>
     */
    private function keys(array $adminKeys, ?string $env): array
    {
        $keys = [];
        $seen = [];

        foreach ($adminKeys as $record) {
            $seen[(string) $record->api_key] = true;
            $keys[] = ['id' => 'admin-'.$record->id, 'api_key' => (string) $record->api_key, 'hint' => $record->hint(), 'label' => $record->label, 'source' => 'admin', 'key_id' => $record->id];
        }

        foreach (array_values(array_filter(array_map('trim', explode(',', (string) $env)))) as $index => $key) {
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $keys[] = ['id' => 'env-'.$index, 'api_key' => $key, 'hint' => '••••'.mb_substr($key, -4), 'label' => null, 'source' => 'env', 'key_id' => null];
            }
        }

        return $keys;
    }

    private function setting(string $provider, bool $lock = false): AiModelSetting
    {
        $setting = AiModelSetting::query()->where('provider', $provider)->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if ($setting === null) {
            $setting = new AiModelSetting;
            $setting->provider = $provider;
        }

        return $setting;
    }

    /**
     * @throws ValidationException
     */
    private function assertKnown(string $name): void
    {
        if (! array_key_exists($name, (array) config('ai.models', []))) {
            throw ValidationException::withMessages(['provider' => 'Unknown AI model.']);
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor): void
    {
        if (! $actor->can(PlatformPermission::ManageSystemSettings->value)) {
            throw new AuthorizationException('Only a super admin can change the AI models.');
        }
    }
}
