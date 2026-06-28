<?php
// app/Http/Controllers/NotificationController.php
namespace App\Http\Controllers;

use App\Models\NotificationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()->notifications()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true, 'data' => ['id' => $id]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'data' => ['marked' => true]]);
    }

    public function storeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required_without:token', 'string', 'max:2048'],
            'token' => ['required_without:fcm_token', 'string', 'max:2048'],
            'provider' => ['nullable', 'in:fcm,expo'],
            'platform' => ['nullable', 'in:web,android,ios'],
        ]);

        $token = $validated['token'] ?? $validated['fcm_token'];
        $provider = $validated['provider'] ?? 'fcm';
        $platform = $validated['platform'] ?? 'web';

        NotificationToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $request->user()->id, 'provider' => $provider, 'platform' => $platform, 'last_used_at' => now()],
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification token updated successfully.',
            'fcm_token' => $token, // backward-compatible field for existing mobile callers
        ]);
    }
}
