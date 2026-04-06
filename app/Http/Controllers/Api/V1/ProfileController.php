<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadProfileImageRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->loadProfileRelations($request);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('update', $user);

        $user->update($request->validated());

        $user = $this->loadProfileRelations($request);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => new UserResource($user),
        ]);
    }

    public function uploadImage(UploadProfileImageRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('update', $user);

        if ($user->hasMedia('profile_images')) {
            $user->clearMediaCollection('profile_images');
        }

        $extension = $request->file('profile_image')->getClientOriginalExtension();

        $user->addMediaFromRequest('profile_image')
            ->usingName($user->name.' Profile Image')
            ->usingFileName(time().'_profile.'.$extension)
            ->toMediaCollection('profile_images');

        $user = $this->loadProfileRelations($request);

        return response()->json([
            'success' => true,
            'message' => 'Profile image uploaded successfully.',
            'data' => new UserResource($user),
        ]);
    }

    public function removeImage(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('update', $user);

        $hadProfileImage = $user->hasMedia('profile_images');

        if ($hadProfileImage) {
            $user->clearMediaCollection('profile_images');
        }

        $user = $this->loadProfileRelations($request);

        return response()->json([
            'success' => true,
            'message' => $hadProfileImage ? 'Profile image removed successfully.' : 'No profile image to remove.',
            'data' => new UserResource($user),
        ]);
    }

    private function loadProfileRelations(Request $request)
    {
        return $request->user()->loadMissing([
            'department',
            'designation',
            'attendanceType',
            'roles',
            'currentDevice',
            'reportsTo',
        ]);
    }
}
