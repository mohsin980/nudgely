<?php

namespace App\Console\Commands;

use App\Services\AI\Chat\AiModelSettings;
use App\Services\AI\Chat\ChatClient;
use App\Services\AI\Chat\ChatModel;
use App\Services\AI\Chat\ChatModelChain;
use Illuminate\Console\Command;

/**
 * Shows the AI model chain (order, model, where the key comes from, status) and, with --test, asks each model
 * a tiny question to check its key and model name.
 */
class AiModelsCommand extends Command
{
    protected $signature = 'ai:models {--test : Ask each key of each model that is set up a tiny question}';

    protected $description = 'Show the AI models in the order they are tried, and optionally test them';

    public function handle(AiModelSettings $settings, ChatModelChain $chain, ChatClient $client): int
    {
        $rows = [];
        $order = 0;

        foreach ($settings->all() as $name => $model) {
            $keys = ChatModel::forEachKey($name, $model);
            $resting = count(array_filter($keys, fn (ChatModel $key) => $chain->restingUntil($key) !== null));
            $status = match (true) {
                ! $model['enabled'] => 'off',
                $keys === [] => 'not set up',
                $resting === count($keys) => 'all keys resting',
                $resting > 0 => "ready ({$resting} of ".count($keys).' keys resting)',
                default => 'ready',
            };

            if ($this->option('test') && $model['enabled'] && $keys !== []) {
                $status = implode("\n", array_column($client->test($name), 'message'));
            }

            $keyText = ($model['keyless'] ?? false) ? 'none needed' : (count($model['keys']) === 0 ? 'missing' : implode(', ', array_map(fn (array $key) => $key['hint'].' ('.$key['source'].')', $model['keys'])));
            $rows[] = [++$order, $model['label'] ?? $name, $model['model'] ?? '—', $keyText, $status];
        }

        $this->table(['#', 'Provider', 'Model', 'Key', 'Status'], $rows);

        return self::SUCCESS;
    }
}
