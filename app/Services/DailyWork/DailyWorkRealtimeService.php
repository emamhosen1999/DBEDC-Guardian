<?php

namespace App\Services\DailyWork;

use App\Events\DailyWorkUpdated;
use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class DailyWorkRealtimeService
{
    /**
     * Broadcast daily work update using Laravel's built-in event system
     */
    public function broadcastUpdate(DailyWork $dailyWork, string $action): void
    {
        try {
            // Create and dispatch the event using Laravel's built-in event system
            $event = new DailyWorkUpdated($dailyWork, $action, Auth::id(), $this->getAffectedUsers($dailyWork));
            
            // Dispatch the event - listeners can handle notifications, logging, etc.
            Event::dispatch($event);
            
            Log::info("Daily work {$action} event dispatched for work #{$dailyWork->id}");
        } catch (\Exception $e) {
            Log::error("Failed to dispatch daily work event: " . $e->getMessage());
        }
    }

    /**
     * Get users who should be notified about a daily work change
     */
    private function getAffectedUsers(DailyWork $dailyWork): array
    {
        $affectedUsers = [];
        
        // Add the incharge user
        if ($dailyWork->incharge) {
            $affectedUsers[] = $dailyWork->incharge;
        }
        
        // Add the assigned user
        if ($dailyWork->assigned && $dailyWork->assigned !== $dailyWork->incharge) {
            $affectedUsers[] = $dailyWork->assigned;
        }
        
        // Add admin users (they see all data)
        $adminUsers = User::role(['Super Administratoristrator', 'Administrator'])
            ->pluck('id')
            ->toArray();
        
        $affectedUsers = array_merge($affectedUsers, $adminUsers);
        
        // Remove duplicates and current user
        $affectedUsers = array_unique($affectedUsers);
        $affectedUsers = array_diff($affectedUsers, [Auth::id()]);
        
        return array_values($affectedUsers);
    }

    /**
     * Get channel names for a user to subscribe to
     */
    public function getUserChannels(int $userId): array
    {
        $user = User::with(['roles', 'designation'])->find($userId);
        
        $channels = [];
        
        // User's private channel
        $channels[] = "daily-works.user.{$userId}";
        
        // Admin channel if user is admin
        if ($user && $user->hasRole(['Super Administratoristrator', 'Administrator'])) {
            $channels[] = 'daily-works.admin';
        }
        
        return $channels;
    }

    /**
     * Check if user can access a specific channel
     */
    public function canAccessChannel(int $userId, string $channel): bool
    {
        $user = User::with(['roles', 'designation'])->find($userId);
        
        if (!$user) {
            return false;
        }
        
        // Check user's private channel
        if ($channel === "daily-works.user.{$userId}") {
            return true;
        }
        
        // Check admin channel
        if ($channel === 'daily-works.admin') {
            return $user->hasRole(['Super Administratoristrator', 'Administrator']);
        }
        
        // Check other user channels (only if they're related)
        if (str_starts_with($channel, 'daily-works.user.')) {
            $targetUserId = (int) str_replace('daily-works.user.', '', $channel);
            
            // Allow access if they share daily works
            $sharedWorks = DailyWork::where(function ($query) use ($userId, $targetUserId) {
                $query->where('incharge', $userId)
                      ->orWhere('assigned', $userId)
                      ->orWhere('incharge', $targetUserId)
                      ->orWhere('assigned', $targetUserId);
            })->exists();
            
            return $sharedWorks;
        }
        
        return false;
    }

    /**
     * Get real-time statistics for dashboard
     */
    public function getRealtimeStats(): array
    {
        $userId = Auth::id();
        $user = User::with(['roles', 'designation'])->find($userId);
        
        if (!$user) {
            return [];
        }
        
        $query = DailyWork::query();
        
        // Apply user role filters
        if ($user->hasRole(['Super Administratoristrator', 'Administrator'])) {
            // Admin sees all data
        } elseif ($user->designation?->title === 'Supervision Engineer') {
            $query->where('incharge', $userId);
        } else {
            $query->where(function ($q) use ($userId) {
                $q->where('assigned', $userId)
                  ->orWhere('incharge', $userId);
            });
        }
        
        $today = now()->format('Y-m-d');
        
        return [
            'today_total' => $query->whereDate('date', $today)->count(),
            'today_completed' => $query->whereDate('date', $today)->where('status', 'completed')->count(),
            'pending_review' => $query->whereIn('status', ['new', 'pending', 'in-progress'])->count(),
            'recent_updates' => $query->orderBy('updated_at', 'desc')->limit(5)->get(['id', 'number', 'status', 'updated_at']),
        ];
    }

    /**
     * Trigger bulk update notification
     */
    public function broadcastBulkUpdate(array $dailyWorkIds, string $action, string $message = ''): void
    {
        $userId = Auth::id();
        $allAffectedUsers = [];
        
        foreach ($dailyWorkIds as $dailyWorkId) {
            $dailyWork = DailyWork::find($dailyWorkId);
            if ($dailyWork) {
                $affectedUsers = $this->getAffectedUsers($dailyWork);
                $allAffectedUsers = array_merge($allAffectedUsers, $affectedUsers);
            }
        }
        
        $allAffectedUsers = array_unique($allAffectedUsers);
        $allAffectedUsers = array_diff($allAffectedUsers, [$userId]);
        
        // Create a summary event for bulk operations
        $bulkData = [
            'action' => $action,
            'daily_work_ids' => $dailyWorkIds,
            'user_id' => $userId,
            'message' => $message,
            'timestamp' => now()->toISOString(),
            'affected_users' => array_values($allAffectedUsers),
        ];
        
        // Broadcast to all affected channels
        foreach ($allAffectedUsers as $affectedUserId) {
            broadcast(new DailyWorkUpdated(
                new DailyWork(['id' => 0]), // Dummy daily work for bulk events
                "bulk_{$action}",
                $userId,
                [$affectedUserId]
            ));
        }
    }
}
