<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Anthropic Messages API with a forced tool call → reliable structured JSON. */
class AnthropicClient implements AiClient
{
    public function __construct(protected string $apiKey, protected string $model) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function structured(string $system, string $prompt, string $toolName, array $schema): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(90)->acceptJson()->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 2500,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'tools' => [[
                    'name' => $toolName,
                    'description' => 'Record the structured analysis of the WhatsApp conversation.',
                    'input_schema' => $schema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            ]);
        } catch (ConnectionException $e) {
            throw new AiException('AI provider not reachable: '.$e->getMessage(), temporary: true);
        }

        if ($response->failed()) {
            $msg = $response->json('error.message') ?? ('HTTP '.$response->status());
            throw new AiException('AI request failed: '.$msg, temporary: $response->serverError() || in_array($response->status(), [429, 529], true));
        }

        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new AiException('AI response did not contain structured output.');
    }
}
