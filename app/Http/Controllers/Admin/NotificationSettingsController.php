<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/NotificationSettings');
    }

    public function list(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => NotificationType::orderBy('category')->orderBy('label')->get(),
        ]);
    }

    public function update(Request $request, NotificationType $type): JsonResponse
    {
        $validated = $request->validate([
            'default_channels'   => ['required', 'array'],
            'default_channels.*' => ['in:database,push,mail'],
            'locked_channels'    => ['nullable', 'array'],
            'locked_channels.*'  => ['in:database,push,mail'],
            'recipient_roles'    => ['nullable', 'array'],
            'is_active'          => ['required', 'boolean'],
        ]);

        $type->update($validated);

        return response()->json(['success' => true, 'data' => $type->fresh()]);
    }
}
