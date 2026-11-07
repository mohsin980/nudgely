<?php

namespace App\Services\AI\Chat;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * The models to try, in order, and which of them are resting after a problem. Rest periods are shared by
 * every worker through the cache, so once one request finds Gemini's free quota used up, the others skip
 * straight to the next model until Gemini's rest is over.
 */
class ChatModelChain
{
    public function __construct(private readonly Cache $cache, private readonly AiModelSettings $settings) {}

    /**
     * Every model that is switched on and set up, in order (resting or not). See AiModelSettings.
     *
     * @return list<ChatModel>
     */
    public function configured(): array
    {
        $models = [];

        foreach ($this->settings->chain() as $name => $definition) {
            if (($model = ChatModel::fromConfig($name, $definition)) !== null) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * When the model may be used again, or null if it is not resting.
     */
    public function restingUntil(ChatModel $model): ?Carbon
    {
        $until = $this->cache->get($this->key($model, 'until'));

        return is_int($until) && $until > now()->getTimestamp() ? Carbon::createFromTimestamp($until) : null;
    }

    /**
     * Rest the model. A rate limit doubles its rest each time it repeats within an hour (60s, 2m, 4m…),
     * so a free tier's exhausted daily quota soon stops being asked every request.
     */
    public function rest(ChatModel $model, string $kind, ?int $retryAfter = null): int
    {
        $config = (array) config('ai.cooldown', []);
        $max = (int) ($config['max'] ?? 3600);
        $seconds = (int) ($config[$kind] ?? 60);

        if ($kind === 'rate_limited') {
            $strikes = (int) $this->cache->get($this->key($model, 'strikes'), 0) + 1;
            $this->cache->put($this->key($model, 'strikes'), $strikes, 3600);
            $seconds = $seconds * 2 ** min($strikes - 1, 10);
        }

        $seconds = min($max, max($seconds, (int) $retryAfter));
        $this->cache->put($this->key($model, 'until'), now()->getTimestamp() + $seconds, $seconds);

        return $seconds;
    }

    /**
     * A good answer: the model's rate-limit streak is over.
     */
    public function succeeded(ChatModel $model): void
    {
        $this->cache->forget($this->key($model, 'strikes'));
    }

    private function key(ChatModel $model, string $what): string
    {
        return 'ai-model:'.$model->name.':'.md5($model->model).':'.$what;
    }
}
