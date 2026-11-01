<?php

namespace App\Services\Ai;

interface AiClient
{
    /**
     * Ask the model for a structured result matching $schema (JSON Schema).
     *
     * @return array decoded JSON object (still UNTRUSTED — validate before use)
     *
     * @throws AiException
     */
    public function structured(string $system, string $prompt, string $toolName, array $schema): array;

    public function provider(): string;

    public function model(): string;
}
