<?php

namespace App\Services\AI\Chat;

use App\Enums\Platform\PlatformPermission;
use App\Models\AiModelSetting;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The AI models as they are actually used: config/ai.php (and .env) with the platform administrator's settings on
 * top. An administrator may set each model's key, model name and address, switch it off, and change the order.
 * A field left empty in the admin falls back to .env.
 */
class AiModelSettings
{
    /**
     * Each model's settings, in the order they are tried. Switched-off models are left out.
     *
     * @return array<string, array<string, mixed>> name => config
     */
    public function chain(): array
    {
        $chain = [];

        foreach ($this->all() as $name => $model) {
            if ($model['enabled']) {
                $chain[$name] = $model;
            }
        }

        return $chain;
    }

    /**
     * Every model, in order, with where its key comes from ("admin", "env" or null).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $definitions = (array) config('ai.models', []);
        $stored = AiModelSetting::query()->get()->keyBy('provider');
        $envOrder = array_values(array_intersect((array) config('ai.chain', []), array_keys($definitions)));
        $models = [];

        foreach ($definitions as $name => $definition) {
            $setting = $stored->get($name);
            $envPosition = array_search($name, $envOrder, true);
            $adminKey = filled($setting?->api_key) ? (string) $setting->api_key : null;

            $models[$name] = [
                ...$definition,
                'api_key' => $adminKey ?? ($definition['api_key'] ?? null),
                'model' => filled($setting?->model) ? $setting->model : ($definition['model'] ?? null),
                'base_url' => filled($setting?->base_url) ? $setting->base_url : ($definition['base_url'] ?? null),
                // Not in AI_MODEL_CHAIN and never switched on by an admin: off.
                'enabled' => $setting !== null ? $setting->enabled : $envPosition !== false,
                'position' => $setting?->position ?? (($envPosition === false ? 100 : $envPosition) * 10),
                'key_source' => $adminKey !== null ? 'admin' : (filled($definition['api_key'] ?? null) ? 'env' : null),
                'key_hint' => ($key = $adminKey ?? ($definition['api_key'] ?? null)) ? '••••'.mb_substr((string) $key, -4) : null,
            ];
        }

        uasort($models, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $models;
    }

    /**
     * @param  array{enabled?: bool, api_key?: ?string, clear_key?: bool, model?: ?string, base_url?: ?string}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(string $name, array $input, User $actor): void
    {
        $this->authorize($actor);

        if (! array_key_exists($name, (array) config('ai.models', []))) {
            throw ValidationException::withMessages(['provider' => 'Unknown AI model.']);
        }

        $data = Validator::make($input, [
            'enabled' => ['boolean'],
            'api_key' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
            'clear_key' => ['boolean'],
            'model' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9._:\/@-]+$/'],
            'base_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ], [
            'api_key.regex' => 'The key cannot contain spaces.',
            'model.regex' => 'Use the model\'s ID exactly as the provider writes it, e.g. gemini-2.5-flash.',
        ])->validate();

        DB::transaction(function () use ($name, $data, $actor) {
            $setting = $this->setting($name, lock: true);
            $isNew = ! $setting->exists;
            $changed = [];

            if (array_key_exists('enabled', $data)) {
                $setting->enabled = (bool) $data['enabled'];
                $changed[] = 'enabled';
            }

            if (($data['clear_key'] ?? false) === true) {
                $setting->api_key = null;
                $changed[] = 'api_key';
            } elseif (filled($data['api_key'] ?? null)) { // empty: keep the saved key
                $setting->api_key = trim($data['api_key']);
                $changed[] = 'api_key';
            }

            foreach (['model', 'base_url'] as $field) {
                if (array_key_exists($field, $data)) {
                    $setting->{$field} = filled($data[$field]) ? trim($data[$field]) : null;
                    $changed[] = $field;
                }
            }

            if ($isNew) {
                $current = $this->all()[$name];
                $setting->position = $current['position'];
                $setting->enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : $current['enabled'];
            }

            $setting->updated_by = $actor->id;
            $setting->save();

            // Which fields changed, never their values (keys stay secret).
            PlatformAuditLog::record('ai_model_updated', $actor, changes: ['provider' => $name, 'fields' => $changed], targetType: 'ai_model', targetId: $name);
        });
    }

    /**
     * Move a model one place earlier (-1) or later (+1) in the order.
     *
     * @throws AuthorizationException
     */
    public function move(string $name, int $direction, User $actor): void
    {
        $this->authorize($actor);

        $names = array_keys($this->all());
        $from = array_search($name, $names, true);
        $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

        if ($from === false || $to < 0 || $to >= count($names)) {
            return;
        }

        [$names[$from], $names[$to]] = [$names[$to], $names[$from]];

        DB::transaction(function () use ($names, $actor, $name) {
            $current = $this->all();

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
     * @throws AuthorizationException
     */
    private function authorize(User $actor): void
    {
        if (! $actor->can(PlatformPermission::ManageSystemSettings->value)) {
            throw new AuthorizationException('Only a super admin can change the AI models.');
        }
    }
}
