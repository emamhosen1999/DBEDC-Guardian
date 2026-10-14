<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

/**
 * Provider-agnostic AI model interface for DBEDC Guardian.
 * Supports Gemini, OpenAI, DeepSeek, and local Ollama/LM Studio endpoints.
 *
 * Canonical message format:
 *   ['role' => 'system'|'user'|'assistant'|'tool', 'content' => string]
 *   assistant turns may carry: 'tool_calls' => [['name' => string, 'args' => array, 'sig' => ?string], ...]
 *   tool turns carry: 'results' => [['name' => string, 'response' => array], ...]
 */
interface AiProvider
{
    /**
     * Send chat messages and neutral tool declarations to the AI model.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $tools = [], array $options = []): AiChatResult;

    /**
     * Generate vector embeddings for text chunks.
     * Options: `task` => 'document' | 'query' (retrieval task type, for providers that support one).
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $options
     * @return array<int, array<int, float>> One vector per text
     */
    public function embed(array $texts, array $options = []): array;

    /**
     * Check if the AI provider is configured and available.
     */
    public function isAvailable(): bool;
}
