<?php

namespace App\Services\Ai;

use App\Models\Setting;

class AiClientFactory
{
    public const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
        'local' => 'Offline rules (no API key — for testing)',
    ];

    public const DEFAULT_MODELS = [
        'anthropic' => 'claude-sonnet-5-5',
        'openai' => 'gpt-4o-mini',
        'local' => 'offline-rules-v1',
    ];

    public static function provider(): string
    {
        $p = Setting::get('ai_provider', config('crm.ai.provider', 'anthropic'));

        return array_key_exists($p, self::PROVIDERS) ? $p : 'anthropic';
    }

    public static function apiKey(): ?string
    {
        return Setting::get('ai_api_key') ?: config('crm.ai.api_key');
    }

    public static function model(): string
    {
        return Setting::get('ai_model') ?: (config('crm.ai.model') ?: self::DEFAULT_MODELS[self::provider()]);
    }

    public static function isConfigured(): bool
    {
        return self::provider() === 'local' || filled(self::apiKey());
    }

    public function make(): AiClient
    {
        if (self::provider() === 'local') {
            return new LocalRuleClient();
        }

        $key = self::apiKey();
        if (! $key) {
            throw new AiException('AI is not configured. Add an API key in Settings → AI Analysis.');
        }

        return match (self::provider()) {
            'openai' => new OpenAiClient($key, self::model()),
            default => new AnthropicClient($key, self::model()),
        };
    }
}
