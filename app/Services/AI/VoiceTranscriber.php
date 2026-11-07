<?php

namespace App\Services\AI;

use App\Services\AI\Chat\ChatModel;
use App\Services\AI\Chat\ChatModelChain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns a voice note into text, with the same keys as the AI model chain: Gemini (understands Urdu well), then
 * Groq's free Whisper, then OpenAI Whisper. Each key that is rate-limited rests, as in the chat chain, and the next
 * one is tried. The audio and the transcript are never logged.
 */
class VoiceTranscriber
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly ChatModelChain $chain) {}

    /**
     * @return array{text: string, provider: string}|null null when nobody could transcribe it
     */
    public function transcribe(string $audio, string $mime): ?array
    {
        if ($audio === '' || strlen($audio) > self::MAX_BYTES) {
            return null;
        }

        $providers = (array) config('ai.transcription.providers', ['gemini', 'groq', 'openai']);

        foreach ($this->chain->configured() as $model) {
            if (! in_array($model->name, $providers, true) || $this->chain->restingUntil($model) !== null) {
                continue;
            }

            try {
                $response = $model->name === 'gemini' ? $this->gemini($model, $audio, $mime) : $this->whisper($model, $audio, $mime);
            } catch (ConnectionException) {
                $this->chain->rest($model, 'unavailable');

                continue;
            }

            if (! $response->successful()) {
                $this->failed($model, $response);

                continue;
            }

            $text = $model->name === 'gemini' ? $response->json('candidates.0.content.parts.0.text') : $response->json('text');

            if (is_string($text) && trim($text) !== '') {
                $this->chain->succeeded($model);

                return ['text' => mb_substr(trim($text), 0, 10000), 'provider' => $model->name];
            }
        }

        return null;
    }

    private function gemini(ChatModel $model, string $audio, string $mime): Response
    {
        // Gemini's own API (not its OpenAI-compatible one) takes audio directly.
        $base = preg_replace('#/openai/?$#', '', $model->baseUrl);

        return Http::withHeaders(['x-goog-api-key' => (string) $model->apiKey])->acceptJson()->connectTimeout(5)->timeout(90)
            ->post($base.'/models/'.rawurlencode($model->model).':generateContent', [
                'contents' => [['parts' => [
                    ['text' => 'Transcribe this WhatsApp voice note exactly as spoken, in its own language. Write Urdu in Urdu script, or in Roman Urdu if it is mostly English words. Reply with only the transcript, nothing else.'],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($audio)]],
                ]]],
                'generationConfig' => ['temperature' => 0],
            ]);
    }

    private function whisper(ChatModel $model, string $audio, string $mime): Response
    {
        $name = 'voice-note.'.match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a',
            'audio/amr' => 'amr',
            default => 'ogg',
        };

        return Http::when($model->apiKey !== null, fn ($http) => $http->withToken((string) $model->apiKey))
            ->acceptJson()->connectTimeout(5)->timeout(90)
            ->attach('file', $audio, $name, ['Content-Type' => $mime])
            ->post($model->baseUrl.'/audio/transcriptions', [
                'model' => (string) config('ai.transcription.'.$model->name.'_model', 'whisper-1'),
                'response_format' => 'json',
            ]);
    }

    private function failed(ChatModel $model, Response $response): void
    {
        $status = $response->status();
        $kind = match (true) {
            $status === 429 => 'rate_limited',
            $status >= 500 => 'unavailable',
            in_array($status, [401, 403, 404], true) => 'rejected',
            default => null,
        };

        if ($kind !== null) {
            $this->chain->rest($model, $kind, is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null);
        }

        Log::warning('Voice note not transcribed by this model.', ['provider' => $model->name, 'key' => $model->keyHint, 'status' => $status]);
    }
}
