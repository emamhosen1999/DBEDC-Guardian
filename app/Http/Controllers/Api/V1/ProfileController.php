<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Requests\Api\V1\UploadProfileImageRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $user = $this->loadProfileRelations($request);

        return $this->successResponse(new UserResource($user));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('update', $user);

        $user->update($request->validated());

        $user = $this->loadProfileRelations($request);

        return $this->successResponse(new UserResource($user), 'Profile updated successfully.');
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

        return $this->successResponse(new UserResource($user), 'Profile image uploaded successfully.');
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

        return $this->successResponse(
            new UserResource($user),
            $hadProfileImage ? 'Profile image removed successfully.' : 'No profile image to remove.'
        );
    }

    private function loadProfileRelations(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user->loadMissing([
            'department',
            'designation',
            'attendanceType',
            'roles',
            'currentDevice',
            'reportsTo',
        ]);
    }
}
