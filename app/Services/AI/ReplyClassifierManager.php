<?php

namespace App\Services\AI;

use App\Contracts\AI\CustomerReplyClassifierInterface;
use App\Exceptions\AI\ClassificationFailedException;
use App\Services\AI\Providers\OpenAIReplyClassifier;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves the configured reply classifier (QUOTE_FLOW_AI_PROVIDER).
 *
 * @method CustomerReplyClassifierInterface driver(?string $driver = null)
 */
class ReplyClassifierManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('ai.provider', 'openai');
    }

    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            throw ClassificationFailedException::notConfigured();
        }
    }

    protected function createOpenaiDriver(): CustomerReplyClassifierInterface
    {
        return new OpenAIReplyClassifier(
            $this->config->get('ai.providers.openai', []),
            $this->container->make(ClassificationOutputValidator::class),
        );
    }
}
