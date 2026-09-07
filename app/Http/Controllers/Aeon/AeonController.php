<?php

declare(strict_types=1);

namespace App\Http\Controllers\Aeon;

use App\Http\Controllers\Controller;
use App\Http\Requests\AeonMessageRequest;
use App\Models\Aeon\Conversation;
use App\Models\Aeon\Message;
use App\Services\Aeon\AeonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AeonController extends Controller
{
    public function __construct(private AeonService $aeon) {}

    /**
     * Send a message turn and receive JSON response.
     */
    public function message(AeonMessageRequest $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();
        $message = (string) $request->input('message');
        $conversationId = $request->input('conversation_id') ? (int) $request->input('conversation_id') : null;
        $context = (array) $request->input('context', []);

        $result = $this->aeon->chat($message, $conversationId, $userId, $context);

        return response()->json($result);
    }

    /**
     * Send a message and stream live agent reasoning stages via Server-Sent Events (SSE).
     */
    public function stream(AeonMessageRequest $request): StreamedResponse
    {
        $userId = (string) $request->user()->getKey();
        $message = (string) $request->input('message');
        $conversationId = $request->input('conversation_id') ? (int) $request->input('conversation_id') : null;
        $context = (array) $request->input('context', []);

        return response()->stream(function () use ($message, $conversationId, $userId, $context) {
            // Disable output buffering
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            $sendEvent = static function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            };

            $onStage = static function (string $label) use ($sendEvent): void {
                $sendEvent('stage', ['label' => $label]);
            };

            try {
                $result = $this->aeon->chat($message, $conversationId, $userId, $context, $onStage);
                $sendEvent('done', $result);
            } catch (\Throwable $e) {
                $sendEvent('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * List current user's active conversations.
     */
    public function conversations(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();
        $conversations = Conversation::where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        return response()->json($conversations);
    }

    /**
     * Get a specific conversation with all message history.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();
        $conversation = Conversation::where('id', $id)
            ->where('user_id', $userId)
            ->with(['messages' => fn ($q) => $q->orderBy('id')])
            ->firstOrFail();

        return response()->json($conversation);
    }

    /**
     * Record helpful / unhelpful feedback on an assistant message.
     */
    public function feedback(int $id, Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();
        $value = $request->input('value'); // 1, -1, or 0 (clear)

        $message = Message::where('id', $id)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $userId))
            ->firstOrFail();

        $message->update(['feedback' => $value === 0 ? null : (int) $value]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Archive or delete a conversation.
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $userId = (string) $request->user()->getKey();
        $conversation = Conversation::where('id', $id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $conversation->update(['archived_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
