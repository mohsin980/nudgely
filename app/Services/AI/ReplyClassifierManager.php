<?php

namespace App\Services\AI;

use App\Contracts\AI\CustomerReplyClassifierInterface;
use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\Providers\ModelChainReplyClassifier;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves the configured reply classifier (QUOTE_FLOW_AI_PROVIDER, normally the model chain).
 *
 * @method CustomerReplyClassifierInterface driver(?string $driver = null)
 */
class ReplyClassifierManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = (string) $this->config->get('ai.provider', 'chain');

        // "openai" was the only option before the chain; the chain includes OpenAI when its key is set.
        return $driver === 'openai' ? 'chain' : $driver;
    }

    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            throw ClassificationFailedException::notConfigured();
        }
    }

    /**
     * The model chain (config/ai.php "chain"): free models first, the next one when a model is limited.
     */
    protected function createChainDriver(): CustomerReplyClassifierInterface
    {
        return $this->container->make(ModelChainReplyClassifier::class);
    }
}
