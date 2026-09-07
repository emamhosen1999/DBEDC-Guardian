<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTeamMembers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    // Manager-ness is derived from the SAME server-authoritative relationship/
    // permission logic the API controllers gate on, so login/me/profile all
    // carry a trustworthy is_manager flag. This resource is only ever used for
    // a single user (never in a collection), so the extra lookups are cheap.
    use ResolvesTeamMembers;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolvedAt = $this->resolvedAttendanceType();
        $resolvedTypes = $this->resolvedAttendanceTypes();

        return [
            'id' => (string) ($this->employee_id ?? $this->getKey()),
            'name' => $this->name,
            'is_manager' => $this->isManagerUser($this->resource),
            'email' => $this->email,
            'phone' => $this->phone,
            'employee_id' => $this->employee_id,
            'profile_image_url' => $this->profile_image_url,

            // Basic Information
            'user_name' => $this->user_name,
            'birthday' => $this->birthday,
            'gender' => $this->gender,
            'address' => $this->address,
            'about' => $this->about,

            // Work Information
            'date_of_joining' => $this->date_of_joining,
            'work_location_id' => $this->work_location_id,
            'report_to' => $this->report_to,
            'reports_to' => $this->when($this->relationLoaded('reportsTo') && $this->reportsTo, function () {
                return [
                    'id' => (string) ($this->reportsTo->employee_id ?? $this->reportsTo->getKey()),
                    'name' => $this->reportsTo->name,
                    'profile_image_url' => $this->reportsTo->profile_image_url,
                ];
            }),
            'salary_amount' => $this->when(
                $request->user()?->can('view-salary', $this->resource),
                $this->salary_amount
            ),

            // Relationships
            'department' => $this->relationLoaded('department') ? [
                'id' => $this->department_id,
                'name' => $this->department?->name,
            ] : [
                'id' => $this->department_id,
                'name' => null,
            ],
            'designation' => $this->relationLoaded('designation') ? [
                'id' => $this->designation_id,
                'title' => $this->designation?->title,
            ] : [
                'id' => $this->designation_id,
                'title' => null,
            ],
            'attendance_type' => $resolvedAt ? [
                'id' => $resolvedAt->id,
                'name' => $resolvedAt->name,
                'slug' => $resolvedAt->slug,
            ] : null,
            'attendance_types' => $resolvedTypes->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
            ])->values(),
            'roles' => $this->relationLoaded('roles') ? $this->roles->pluck('name')->toArray() : [],
            'permissions' => $this->when(
                $request->user()
                    && (string) $request->user()->getKey() === (string) $this->resource->getKey(),
                fn () => $request->user()->hasRole('Super Administrator')
                    ? \Spatie\Permission\Models\Permission::query()->pluck('name')->unique()->values()->all()
                    : $request->user()->getAllPermissions()->pluck('name')->values()->all()
            ),

            // Device information
            'single_device_login' => $this->single_device_login_enabled,
            'single_device_login_enabled' => $this->single_device_login_enabled,
            'active_device' => $this->when($this->relationLoaded('currentDevice') && $this->currentDevice, function () {
                return [
                    'id' => $this->currentDevice->id,
                    'device_name' => $this->currentDevice->device_name,
                    'device_type' => $this->currentDevice->device_type,
                    'last_seen_at' => $this->currentDevice->last_used_at,
                ];
            }),

            // Timestamps
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), $this->deleted_at),

            // Authorization flags (only when user is authenticated)
            'can' => $this->when($request->user(), function () use ($request) {
                return [
                    'view' => $request->user()->can('view', $this->resource),
                    'update' => $request->user()->can('update', $this->resource),
                    'delete' => $request->user()->can('delete', $this->resource),
                    'update_roles' => $request->user()->can('updateRoles', $this->resource),
                    'manage_devices' => $request->user()->can('manageDevices', $this->resource),
                ];
            }),
        ];
    }
}
