<?php

namespace App\Services\AI\Chat;

use App\Exceptions\AI\AiRequestFailedException;
use App\Exceptions\AI\InvalidModelOutputException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks the model chain: the first model (and key) that is set up and not resting answers. When one is rate-limited,
 * down, rejects the request or gives an unusable answer, the next one is asked. Models speak the OpenAI Chat
 * Completions format (Gemini, Groq, OpenRouter, Ollama and OpenAI all do).
 *
 * Prompts, customer text, answers and keys are never logged: only which model failed and why.
 */
class ChatClient
{
    /** Why the last model asked couldn't answer, for the admin's "Test" button. */
    private ?string $lastProblem = null;

    public function __construct(
        private readonly ChatModelChain $chain,
        private readonly AiModelSettings $settings,
    ) {}

    /**
     * Ask each of a provider's keys a tiny question, whatever its place in the chain, to check the keys and model name.
     *
     * @return list<array{ok: bool, message: string}>
     */
    public function test(string $name): array
    {
        $definition = $this->settings->all()[$name] ?? null;
        $models = is_array($definition) ? ChatModel::forEachKey($name, $definition) : [];

        if ($models === []) {
            return [['ok' => false, 'message' => 'Not set up: add a key'.($name === 'ollama' ? ' (Ollama: its URL)' : '').' first.']];
        }

        $results = [];

        foreach ($models as $model) {
            $started = microtime(true);
            $reply = $this->ask($model, new ChatRequest([
                ['role' => 'system', 'content' => 'You are a connection test. Reply with the single word OK.'],
                ['role' => 'user', 'content' => 'Test'],
            ], maxTokens: 10));

            if ($reply === null) {
                $results[] = ['ok' => false, 'message' => $model->describe().': '.($this->lastProblem ?? 'no answer')];

                continue;
            }

            $this->chain->succeeded($model);
            $results[] = ['ok' => true, 'message' => $model->describe().': answered in '.number_format(microtime(true) - $started, 1).'s ('.$reply->model.')'];
        }

        return $results;
    }

    /**
     * @template T
     *
     * @param  (Closure(ChatReply): T)|null  $accept  turns the answer into a result, or throws InvalidModelOutputException to ask the next model
     * @return ($accept is null ? ChatReply : T)
     *
     * @throws AiRequestFailedException
     */
    public function complete(ChatRequest $request, ?Closure $accept = null): mixed
    {
        $models = $this->chain->configured();

        if ($models === []) {
            throw new AiRequestFailedException(AiRequestFailedException::NOT_CONFIGURED, 'No AI model is set up. Add GEMINI_API_KEY (free) or another key to .env.');
        }

        $unusable = 0;

        foreach ($models as $model) {
            if ($this->chain->restingUntil($model) !== null) {
                continue;
            }

            $reply = $this->ask($model, $request);

            if ($reply === null) {
                continue;
            }

            try {
                $result = $accept === null ? $reply : $accept($reply);
            } catch (InvalidModelOutputException $e) {
                $unusable++;
                $this->log($model, 'unusable answer', reason: $e->getMessage());

                continue;
            }

            $this->chain->succeeded($model);

            return $result;
        }

        throw $unusable > 0 && $unusable === count($models)
            ? new AiRequestFailedException(AiRequestFailedException::INVALID_OUTPUT, 'Every AI model gave an unusable answer.')
            : new AiRequestFailedException(AiRequestFailedException::UNAVAILABLE, 'Every AI model is busy, limited or unavailable right now.');
    }

    /**
     * One model's answer, or null (after resting it if needed) when it can't give one.
     */
    private function ask(ChatModel $model, ChatRequest $request): ?ChatReply
    {
        try {
            $response = Http::baseUrl($model->baseUrl)
                ->when($model->apiKey !== null, fn ($http) => $http->withToken($model->apiKey))
                ->withHeaders(array_filter(['OpenAI-Organization' => $model->organization]))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout($model->timeout)
                ->post('/chat/completions', $this->body($model, $request));
        } catch (ConnectionException) {
            $this->log($model, 'timeout', rested: $this->chain->rest($model, 'unavailable'));

            return null;
        }

        if (! $response->successful()) {
            $this->failed($model, $response);

            return null;
        }

        $choice = $response->json('choices.0');
        $content = is_array($choice) ? ($choice['message']['content'] ?? null) : null;
        $finish = strtolower((string) (is_array($choice) ? ($choice['finish_reason'] ?? 'stop') : ''));

        if (! is_string($content) || trim($content) === '' || filled($choice['message']['refusal'] ?? null) || $finish !== 'stop') {
            $this->log($model, 'unusable answer', reason: $finish === 'stop' ? 'empty or refused' : 'stopped early ('.mb_substr($finish, 0, 30).')');

            return null;
        }

        $reported = $response->json('model');

        return new ChatReply(
            content: $request->wantsJson() ? $this->withoutCodeFence($content) : trim($content),
            provider: $model->name,
            model: is_string($reported) && $reported !== '' ? mb_substr($reported, 0, 100) : $model->model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ChatModel $model, ChatRequest $request): array
    {
        $messages = $request->messages;

        if ($request->wantsJson() && ! $model->enforcesSchema()) {
            // This model only promises valid JSON, so it is told the shape in words (the answer is validated anyway).
            $messages[0]['content'] .= "\n\nAnswer with only one JSON object, no other text, matching this JSON schema:\n"
                .json_encode($request->schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $body = ['model' => $model->model, 'temperature' => $request->temperature, 'messages' => $messages];

        if ($model->openAiDialect) {
            $body['max_completion_tokens'] = $request->maxTokens;
            $body['store'] = false;
        } else {
            $body['max_tokens'] = $request->maxTokens;
        }

        if ($request->wantsJson()) {
            $format = match ($model->jsonMode) {
                'schema' => ['type' => 'json_schema', 'json_schema' => ['name' => $request->schemaName ?? 'result', 'strict' => true, 'schema' => $request->schema]],
                'object' => ['type' => 'json_object'],
                default => null, // "prompt": the instructions alone ask for JSON
            };

            if ($format !== null) {
                $body['response_format'] = $format;
            }
        }

        return $body;
    }

    private function failed(ChatModel $model, Response $response): void
    {
        $status = $response->status();
        $kind = match (true) {
            $status === 429 => 'rate_limited',
            $status >= 500 => 'unavailable',
            in_array($status, [401, 403, 404], true) => 'rejected', // wrong key, no access, or no such model
            default => null, // a 400 is about this request, not the model: just try the next one
        };

        $rested = $kind === null ? null : $this->chain->rest($model, $kind, $this->retryAfter($response));
        $code = $response->json('error.code') ?? $response->json('error.status') ?? $response->json('0.error.status');

        $this->log($model, $kind ?? 'request refused', status: $status, rested: $rested, reason: is_scalar($code) ? mb_substr((string) $code, 0, 60) : null);
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Some models wrap JSON in a Markdown code block; the JSON inside is what counts.
     */
    private function withoutCodeFence(string $content): string
    {
        $content = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $content, $match) === 1) {
            return $match[1];
        }

        return $content;
    }

    private function log(ChatModel $model, string $problem, ?int $status = null, ?int $rested = null, ?string $reason = null): void
    {
        $this->lastProblem = $problem.($status !== null ? " (HTTP {$status})" : '').($reason !== null ? ': '.$reason : '')
            .($rested !== null ? ", resting {$rested}s" : '');

        Log::warning('AI model skipped: '.$problem.'.', array_filter([
            'provider' => $model->name,
            'key' => $model->keyHint,
            'model' => $model->model,
            'status' => $status,
            'resting_seconds' => $rested,
            'reason' => $reason,
        ], fn ($value) => $value !== null));
    }
}
