<?php

declare(strict_types=1);

namespace App\Services\Aeon;

use App\Contracts\Ai\AiProvider;
use App\Models\Aeon\Conversation;
use App\Models\Aeon\Message;
use App\Services\Aeon\Data\SchemaCatalog;
use App\Services\Aeon\Tools\ToolRegistry;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Master Agentic Execution Engine for Aeon in DBEDC Guardian.
 * Runs an unbounded multi-hop reasoning loop with RAG grounding,
 * dynamic tool routing, live stage streaming, and Generative UI block composition.
 */
class AeonService
{
    public function __construct(
        private AiProvider $provider,
        private RagService $rag,
        private ToolRegistry $tools,
        private SchemaCatalog $schema
    ) {}

    /**
     * Process one chat turn.
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(string): void|null  $onStage
     * @return array{conversation_id: int, reply: array<string, mixed>, usage: array<string, mixed>}
     */
    public function chat(string $prompt, ?int $conversationId, int|string|null $userId, array $context = [], ?Closure $onStage = null): array
    {
        $conversation = $this->resolveConversation($conversationId, $userId, $prompt);

        // Record User Turn
        $userMsg = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $prompt,
            'blocks' => [['type' => 'text', 'text' => $prompt]],
        ]);

        $emit = static function (string $label) use ($onStage): void {
            if ($onStage !== null) {
                try {
                    $onStage($label);
                } catch (\Throwable) {
                    // Ignore stage emit errors
                }
            }
        };

        $emit('Analyzing your request…');

        // Check Daily Token Safety
        if ($this->isOverDailyBudget($userId)) {
            $reply = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => 'Daily AI allowance reached for today. Please try again tomorrow.',
                'blocks' => [['type' => 'text', 'text' => 'Daily AI token allowance reached for your account. Please try again tomorrow.']],
            ]);

            return [
                'conversation_id' => $conversation->id,
                'reply' => $reply->toArray(),
                'usage' => $this->getUsageStatus($userId),
            ];
        }

        // RAG Grounding Search
        $chunks = [];
        if ((bool) config('aeon.rag.enabled', true)) {
            $emit('Consulting Guardian knowledge base…');
            $chunks = $this->rag->search($prompt, (int) config('aeon.rag.max_chunks', 6));
        }

        $transcript = $this->buildHistory($conversation, $context, $chunks);
        $declarations = $this->tools->declarations();
        $maxLoops = max(1, (int) config('aeon.agent.max_loops', 25));

        $blocks = [];
        $toolLog = [];
        $totalTokens = 0;
        $content = '';
        $model = '';
        $failed = false;
        $terminal = null;
        $seenToolCalls = [];

        // Unbounded agent reasoning loop
        for ($loop = 0; $loop < $maxLoops; $loop++) {
            $emit($loop === 0 ? 'Thinking…' : 'Reasoning over live results…');

            $result = $this->provider->chat($transcript, $declarations);
            $totalTokens += $result->tokensUsed;
            if (! empty($result->model)) {
                $model = $result->model;
            }

            if (! $result->success) {
                $failed = true;
                break;
            }

            $content = trim($result->content);

            if (empty($result->toolCalls)) {
                break; // Model reached final natural language conclusion
            }

            $transcript[] = [
                'role' => 'assistant',
                'content' => $result->content,
                'tool_calls' => $result->toolCalls,
            ];

            $responses = [];
            $cycleDetected = false;

            foreach ($result->toolCalls as $call) {
                $name = (string) ($call['name'] ?? '');
                $args = (array) ($call['args'] ?? []);

                // Cycle Guard: prevent calling the exact same tool with identical args in a loop
                $callHash = md5($name.serialize($args));
                if (isset($seenToolCalls[$callHash]) && (bool) config('aeon.agent.cycle_guard', true)) {
                    $cycleDetected = true;
                    $responses[] = [
                        'name' => $name,
                        'response' => ['note' => 'Duplicate query detected. Please synthesize final answer.'],
                    ];

                    continue;
                }
                $seenToolCalls[$callHash] = true;

                $out = $this->executeToolCall($name, $args, $userId, $emit);
                $responses[] = ['name' => $name, 'response' => $out['response']];
                $toolLog[] = ['name' => $name, 'args' => $args, 'summary' => Str::limit((string) $out['summary'], 200)];

                foreach ($out['blocks'] as $b) {
                    if (! in_array($b, $blocks, true)) {
                        $blocks[] = $b;
                    }
                }

                if (! empty($out['terminal'])) {
                    $terminal = ['kind' => $name, 'text' => (string) $out['summary']];
                }
            }

            if ($terminal !== null || $cycleDetected) {
                break; // Form/Navigation presented on screen or cycle detected
            }

            $transcript[] = ['role' => 'tool', 'results' => $responses];
        }

        // Add model text block if present
        $finalBlocks = [];
        if ($content !== '') {
            $finalBlocks[] = ['type' => 'text', 'text' => $content];
        }
        foreach ($blocks as $b) {
            $finalBlocks[] = $b;
        }

        if (empty($finalBlocks)) {
            $finalBlocks[] = ['type' => 'text', 'text' => $failed ? 'Sorry, Aeon encountered an unexpected error.' : 'Done.'];
        }

        $reply = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'blocks' => $finalBlocks,
            'tool_calls' => $toolLog,
            'tokens' => $totalTokens,
            'provider' => config('aeon.provider', 'gemini'),
            'model' => $model,
        ]);

        return [
            'conversation_id' => $conversation->id,
            'reply' => $reply->toArray(),
            'usage' => $this->getUsageStatus($userId),
        ];
    }

    /**
     * @param  Closure(string): void  $emit
     * @return array{response: array<string, mixed>, blocks: array<int, array<string, mixed>>, summary: string, terminal: bool}
     */
    private function executeToolCall(string $name, array $args, int|string|null $userId, Closure $emit): array
    {
        $tool = $this->tools->find($name);
        if (! $tool) {
            return [
                'response' => ['error' => "Unknown tool: {$name}"],
                'blocks' => [],
                'summary' => "tool error: {$name} not registered",
                'terminal' => false,
            ];
        }

        $emit(match ($name) {
            'query_data' => 'Querying DBEDC database ('.($args['entity'] ?? 'records').')…',
            'prepare_operation' => 'Generating interactive form for '.($args['entity'] ?? 'action').'…',
            'navigate' => 'Locating module route ('.($args['destination'] ?? '').')…',
            default => "Running {$name}…",
        });

        try {
            $out = $tool->run($args, $userId);

            return [
                'response' => $out['data'] ?? ['status' => 'ok', 'summary' => $out['text'] ?? ''],
                'blocks' => $out['blocks'] ?? [],
                'summary' => $out['text'] ?? '',
                'terminal' => $out['terminal'] ?? false,
            ];
        } catch (\Throwable $e) {
            Log::error('Aeon tool execution failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return [
                'response' => ['error' => $e->getMessage()],
                'blocks' => [],
                'summary' => "tool error: {$e->getMessage()}",
                'terminal' => false,
            ];
        }
    }

    /**
     * Assemble history transcript with system prompt and RAG grounding.
     *
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<int, array<string, mixed>>
     */
    private function buildHistory(Conversation $conversation, array $context, array $chunks): array
    {
        $system = (string) config('aeon.system_prompt');

        if (! empty($context['page'])) {
            $system .= "\nUser's Current Page: ".(string) $context['page'];
        }

        if (! empty($chunks)) {
            $system .= "\n\nRELEVANT GUARDIAN KNOWLEDGE BASE CONTEXT:\n";
            foreach ($chunks as $c) {
                $system .= "--- [{$c['source_type']}: {$c['title']}] ---\n{$c['chunk_text']}\n";
            }
        }

        $history = [['role' => 'system', 'content' => $system]];

        $messages = $conversation->messages()->orderBy('id')->limit(30)->get();
        foreach ($messages as $m) {
            if ($m->role === 'user') {
                $history[] = ['role' => 'user', 'content' => (string) ($m->content ?? '')];
            } elseif ($m->role === 'assistant') {
                $history[] = ['role' => 'assistant', 'content' => (string) ($m->content ?? '')];
            }
        }

        return $history;
    }

    private function resolveConversation(?int $conversationId, int|string|null $userId, string $firstPrompt): Conversation
    {
        if ($conversationId !== null) {
            $c = Conversation::where('id', $conversationId)->where('user_id', $userId)->first();
            if ($c) {
                return $c;
            }
        }

        return Conversation::create([
            'user_id' => (string) $userId,
            'title' => Str::limit($firstPrompt, 40),
        ]);
    }

    private function isOverDailyBudget(int|string|null $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $limit = (int) config('aeon.budget.daily_tokens_per_user', 500000);
        $used = (int) Message::whereHas('conversation', fn ($q) => $q->where('user_id', $userId))
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('tokens');

        return $used >= $limit;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsageStatus(int|string|null $userId): array
    {
        $limit = (int) config('aeon.budget.daily_tokens_per_user', 500000);
        $used = 0;

        if ($userId !== null) {
            $used = (int) Message::whereHas('conversation', fn ($q) => $q->where('user_id', $userId))
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('tokens');
        }

        return [
            'unlimited' => false,
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'model' => config('aeon.provider', 'gemini'),
        ];
    }
}
