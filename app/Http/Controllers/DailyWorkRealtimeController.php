<?php

namespace App\Http\Controllers;

use App\Events\DailyWorkUpdated;
use App\Models\DailyWork;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;

class DailyWorkRealtimeController extends Controller
{
    /**
     * Server-Sent Events endpoint for real-time updates
     */
    public function stream(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->stream(function () {
                echo "event: error\n";
                echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
                ob_flush();
                flush();
            }, 1);
        }

        return response()->stream(function () use ($user) {
            $lastEventId = request()->header('Last-Event-ID', 0);
            
            // Set SSE headers
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Cache-Control');
            
            // Send initial connection event
            $this->sendSSEMessage('connected', [
                'user_id' => $user->id,
                'timestamp' => now()->toISOString(),
                'message' => 'Real-time updates connected'
            ]);

            // Listen for daily work updates
            Event::listen(DailyWorkUpdated::class, function (DailyWorkUpdated $event) use ($user) {
                // Check if this user should receive this update
                if ($this->shouldUserReceiveUpdate($user, $event)) {
                    $this->sendSSEMessage('daily-work-updated', $event->getEventData());
                }
            });

            // Keep the connection alive with periodic heartbeats
            $heartbeatCount = 0;
            while (true) {
                $heartbeatCount++;
                
                // Send heartbeat every 30 seconds
                if ($heartbeatCount >= 30) {
                    $this->sendSSEMessage('heartbeat', [
                        'timestamp' => now()->toISOString()
                    ]);
                    $heartbeatCount = 0;
                }
                
                // Check for any cached updates
                $updates = $this->getPendingUpdates($user->id, $lastEventId);
                foreach ($updates as $update) {
                    $this->sendSSEMessage('cached-update', $update);
                }
                
                // Sleep for 1 second
                sleep(1);
                
                // Break if client disconnects (basic check)
                if (connection_aborted()) {
                    break;
                }
            }
        }, 200);
    }

    /**
     * Get recent updates via polling (fallback for mobile)
     */
    public function getUpdates(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::unauthorized('Unauthorized');
        }

        $lastUpdateId = $request->get('last_update_id', 0);
        $limit = min($request->get('limit', 50), 100);

        $updates = $this->getPendingUpdates($user->id, $lastUpdateId, $limit);

        return ApiResponseService::success($updates, 'Updates retrieved successfully');
    }

    /**
     * Check if user should receive the update
     */
    private function shouldUserReceiveUpdate($user, DailyWorkUpdated $event): bool
    {
        // Admin users receive all updates
        if ($user->hasRole(['Super Administratoristrator', 'Administrator'])) {
            return true;
        }

        // User who performed the action receives it
        if ($event->userId === $user->id) {
            return true;
        }

        // Affected users receive it
        if (in_array($user->id, $event->affectedUsers)) {
            return true;
        }

        // Users with assigned/incharge daily work receive it
        if ($event->dailyWork && 
            ($event->dailyWork->incharge === $user->id || $event->dailyWork->assigned === $user->id)) {
            return true;
        }

        return false;
    }

    /**
     * Send SSE message
     */
    private function sendSSEMessage(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    /**
     * Get pending updates from cache
     */
    private function getPendingUpdates(int $userId, int $lastUpdateId = 0, int $limit = 50): array
    {
        $cacheKey = "daily_work_updates_{$userId}";
        $allUpdates = Cache::get($cacheKey, []);
        
        // Filter updates after lastUpdateId
        $pendingUpdates = array_filter($allUpdates, function ($update) use ($lastUpdateId) {
            return $update['id'] > $lastUpdateId;
        });

        // Sort by ID and limit
        usort($pendingUpdates, function ($a, $b) {
            return $a['id'] - $b['id'];
        });

        return array_slice($pendingUpdates, 0, $limit);
    }

    /**
     * Store update in cache for polling
     */
    public static function storeUpdate(int $userId, array $data): void
    {
        $cacheKey = "daily_work_updates_{$userId}";
        $updates = Cache::get($cacheKey, []);
        
        $updateData = array_merge($data, [
            'id' => time() + rand(1000, 9999), // Unique ID for ordering
            'timestamp' => now()->toISOString(),
        ]);
        
        $updates[] = $updateData;
        
        // Keep only last 100 updates per user
        if (count($updates) > 100) {
            $updates = array_slice($updates, -100);
        }
        
        Cache::put($cacheKey, $updates, 3600); // 1 hour
    }

    /**
     * Test endpoint for real-time functionality
     */
    public function test(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::unauthorized('Unauthorized');
        }

        // Create a test event
        $dailyWork = DailyWork::first();
        if ($dailyWork) {
            $event = new DailyWorkUpdated($dailyWork, 'test', $user->id, [$user->id]);
            Event::dispatch($event);
            
            // Store for polling
            self::storeUpdate($user->id, $event->getEventData());
        }

        return ApiResponseService::success(['message' => 'Test event sent'], 'Test event dispatched');
    }
}
