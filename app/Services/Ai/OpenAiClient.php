<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI Chat Completions in JSON mode. */
class OpenAiClient implements AiClient
{
    public function __construct(protected string $apiKey, protected string $model) {}

    public function provider(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function structured(string $system, string $prompt, string $toolName, array $schema): array
    {
        try {
            $response = Http::withToken($this->apiKey)->timeout(90)->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system."\n\nReturn ONLY a JSON object that follows this JSON Schema:\n".json_encode($schema)],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException('AI provider not reachable: '.$e->getMessage(), temporary: true);
        }

        if ($response->failed()) {
            $msg = $response->json('error.message') ?? ('HTTP '.$response->status());
            throw new AiException('AI request failed: '.$msg, temporary: $response->serverError() || $response->status() === 429);
        }

        $decoded = json_decode((string) $response->json('choices.0.message.content'), true);
        if (! is_array($decoded)) {
            throw new AiException('AI returned invalid JSON.');
        }

        return $decoded;
    }
}
