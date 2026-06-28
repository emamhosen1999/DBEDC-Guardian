<?php
// app/Http/Controllers/NotificationPreferenceController.php
namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/NotificationPreferences');
    }

    public function list(Request $request): JsonResponse
    {
        $categories = NotificationType::where('is_active', true)
            ->get(['category', 'locked_channels'])
            ->groupBy('category')
            ->map(fn ($rows) => ['locked_channels' => array_values(array_unique($rows->flatMap->locked_channels->all()))]);

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'preferences' => $request->user()->notificationPreferences()->get(['category', 'channel', 'enabled']),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.category' => ['required', 'string'],
            'preferences.*.channel' => ['required', 'in:push,mail,database'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        // Build the set of locked (category, channel) pairs to ignore.
        $locked = NotificationType::all()->flatMap(function ($t) {
            return collect($t->locked_channels ?? [])->map(fn ($c) => "{$t->category}|{$c}");
        })->unique()->all();

        foreach ($validated['preferences'] as $pref) {
            if (in_array("{$pref['category']}|{$pref['channel']}", $locked, true)) {
                continue; // can't override a locked channel
            }
            NotificationPreference::updateOrCreate(
                ['user_id' => $request->user()->id, 'category' => $pref['category'], 'channel' => $pref['channel']],
                ['enabled' => $pref['enabled']],
            );
        }

        return response()->json(['success' => true, 'data' => ['saved' => true]]);
    }
}
