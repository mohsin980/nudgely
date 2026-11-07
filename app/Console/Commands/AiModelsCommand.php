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
    protected $signature = 'ai:models {--test : Ask each model that is set up a tiny question}';

    protected $description = 'Show the AI models in the order they are tried, and optionally test them';

    public function handle(AiModelSettings $settings, ChatModelChain $chain, ChatClient $client): int
    {
        $rows = [];
        $order = 0;

        foreach ($settings->all() as $name => $model) {
            $ready = ChatModel::fromConfig($name, $model);
            $resting = $ready === null ? null : $chain->restingUntil($ready);
            $status = match (true) {
                ! $model['enabled'] => 'off',
                $ready === null => 'not set up',
                $resting !== null => 'resting until '.$resting->format('H:i:s'),
                default => 'ready',
            };

            if ($this->option('test') && $model['enabled'] && $ready !== null) {
                $status = $client->test($name)['message'];
            }

            $rows[] = [++$order, $model['label'] ?? $name, $model['model'] ?? '—', $model['key_source'] ?? (($model['keyless'] ?? false) ? 'none needed' : 'missing'), $status];
        }

        $this->table(['#', 'Provider', 'Model', 'Key', 'Status'], $rows);

        return self::SUCCESS;
    }
}
