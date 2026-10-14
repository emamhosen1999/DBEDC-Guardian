<?php

declare(strict_types=1);

namespace App\Services\Aeon\Providers;

use App\Contracts\Ai\AiChatResult;
use App\Contracts\Ai\AiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements AiProvider
{
    private string $key;
    private string $model;
    private string $endpoint;
    private int $timeout;

    public function __construct()
    {
        $cfg = (array) config('aeon.providers.gemini', []);
        $this->key = (string) ($cfg['api_key'] ?? '');
        $this->model = (string) ($cfg['model'] ?? 'gemini-2.5-flash');
        $this->endpoint = rtrim((string) ($cfg['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->timeout = (int) ($cfg['timeout'] ?? 45);
    }

    public function chat(array $messages, array $tools = [], array $options = []): AiChatResult
    {
        if (empty($this->key)) {
            return AiChatResult::failed('Gemini API key is not configured in .env (GEMINI_API_KEY).', $this->model);
        }

        $system = null;
        $contents = [];

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                $system = (string) ($m['content'] ?? '');
                continue;
            }

            if ($role === 'tool') {
                $parts = [];
                foreach ((array) ($m['results'] ?? []) as $r) {
                    $parts[] = [
                        'functionResponse' => [
                            'name' => (string) ($r['name'] ?? ''),
                            'response' => (object) ($r['response'] ?? []),
                        ],
                    ];
                }
                if (! empty($parts)) {
                    $contents[] = ['role' => 'user', 'parts' => $parts];
                }
                continue;
            }

            $parts = [];
            $text = (string) ($m['content'] ?? '');
            if ($text !== '') {
                $parts[] = ['text' => $text];
            }

            if ($role === 'assistant') {
                foreach ((array) ($m['tool_calls'] ?? []) as $call) {
                    $part = [
                        'functionCall' => [
                            'name' => (string) ($call['name'] ?? ''),
                            'args' => (object) ($call['args'] ?? []),
                        ],
                    ];
                    // Keep thought signatures for Gemini reasoning models
                    if (! empty($call['sig'])) {
                        $part['thoughtSignature'] = (string) $call['sig'];
                    }
                    $parts[] = $part;
                }
            }

            if (empty($parts)) {
                $parts[] = ['text' => ''];
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? config('aeon.providers.gemini.temperature', 0.3)),
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? config('aeon.providers.gemini.max_tokens', 4096)),
            ],
        ];

        if ($system !== null && $system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if (! empty($tools)) {
            $payload['tools'] = [
                [
                    'functionDeclarations' => array_map(static fn ($t) => [
                        'name' => $t['name'],
                        'description' => $t['description'] ?? '',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => (object) ($t['parameters'] ?? []),
                            'required' => [],
                        ],
                    ], $tools),
                ],
            ];
        }

        $attempts = (int) config('aeon.providers.gemini.retries', 3);
        $baseMs = (int) config('aeon.providers.gemini.retry_base_ms', 500);
        $lastStatus = null;

        foreach ($this->modelChain() as $model) {
            try {
                $res = null;
                for ($i = 0; $i <= $attempts; $i++) {
                    $res = Http::withHeaders(['x-goog-api-key' => $this->key])
                        ->timeout($this->timeout)
                        ->post("{$this->endpoint}/models/{$model}:generateContent", $payload);

                    if (! in_array($res->status(), [429, 503], true)) {
                        break;
                    }
                    if ($i < $attempts) {
                        usleep($baseMs * 1000 * ($i + 1));
                    }
                }

                $lastStatus = $res?->status();

                if (in_array($lastStatus, [429, 503], true)) {
                    continue; // Model busy/rate-limited, try next in chain
                }

                if ($res === null || $res->failed()) {
                    $err = (string) data_get($res?->json(), 'error.message', 'HTTP '.$lastStatus);
                    Log::warning('Aeon Gemini chat call failed', ['model' => $model, 'error' => $err]);
                    continue;
                }

                $json = $res->json();
                $parts = (array) data_get($json, 'candidates.0.content.parts', []);
                $text = '';
                $toolCalls = [];

                foreach ($parts as $p) {
                    if (isset($p['text'])) {
                        $text .= $p['text'];
                    }
                    if (isset($p['functionCall'])) {
                        $call = [
                            'name' => (string) ($p['functionCall']['name'] ?? ''),
                            'args' => (array) ($p['functionCall']['args'] ?? []),
                        ];
                        if (! empty($p['thoughtSignature'])) {
                            $call['sig'] = (string) $p['thoughtSignature'];
                        }
                        $toolCalls[] = $call;
                    }
                }

                $tokens = (int) data_get($json, 'usageMetadata.totalTokenCount', 0);

                return new AiChatResult(
                    content: $text,
                    toolCalls: $toolCalls,
                    tokensUsed: $tokens,
                    model: $model
                );
            } catch (\Throwable $e) {
                Log::error('Aeon Gemini chat exception', ['model' => $model, 'error' => $e->getMessage()]);
            }
        }

        return AiChatResult::failed('Gemini is currently unavailable (Status: '.($lastStatus ?? 'Offline').')', $this->model);
    }

    /**
     * Options: `task` is 'document' (text being indexed) or 'query' (text being searched for), sent as Gemini's
     * retrieval task type so query and document vectors are optimised for each other.
     */
    public function embed(array $texts, array $options = []): array
    {
        if (empty($this->key) || empty($texts)) {
            return array_fill(0, count($texts), []);
        }

        $cfg = (array) config('aeon.providers.gemini', []);
        $embedModel = (string) ($cfg['embed_model'] ?? 'gemini-embedding-001');
        $dims = (int) ($cfg['embed_dims'] ?? 0);
        $task = match ($options['task'] ?? null) {
            'document' => 'RETRIEVAL_DOCUMENT',
            'query' => 'RETRIEVAL_QUERY',
            default => null,
        };
        $out = [];

        foreach ($texts as $text) {
            try {
                $res = Http::withHeaders(['x-goog-api-key' => $this->key])
                    ->timeout($this->timeout)
                    ->post("{$this->endpoint}/models/{$embedModel}:embedContent", array_filter([
                        'content' => ['parts' => [['text' => (string) $text]]],
                        'taskType' => $task,
                        'outputDimensionality' => $dims > 0 ? $dims : null,
                    ]));

                if ($res->failed()) {
                    // A retired model answers 404 here; say so instead of handing back an empty vector silently.
                    Log::warning('Aeon Gemini embed call failed', [
                        'model' => $embedModel,
                        'status' => $res->status(),
                        'error' => mb_substr((string) data_get($res->json(), 'error.message', ''), 0, 300),
                    ]);
                }

                $out[] = (array) data_get($res->json(), 'embedding.values', []);
            } catch (\Throwable $e) {
                Log::error('Aeon Gemini embed failed', ['error' => $e->getMessage()]);
                $out[] = [];
            }
        }

        return $out;
    }

    public function isAvailable(): bool
    {
        if (empty($this->key)) {
            return false;
        }

        try {
            return Http::withHeaders(['x-goog-api-key' => $this->key])
                ->timeout(5)
                ->get("{$this->endpoint}/models")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function modelChain(): array
    {
        $fallbacks = config('aeon.providers.gemini.fallback_models', []);
        if (is_string($fallbacks)) {
            $fallbacks = array_filter(array_map('trim', explode(',', $fallbacks)));
        }

        return array_values(array_unique(array_merge([$this->model], (array) $fallbacks)));
    }
}
