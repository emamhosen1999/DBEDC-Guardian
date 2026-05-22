<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaveResource extends JsonResource
{
    /**
     * Transform the resource into an array
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'leave_type' => $this->leave_type,
            'from_date' => $this->from_date?->format('Y-m-d'),
            'to_date' => $this->to_date?->format('Y-m-d'),
            'no_of_days' => $this->no_of_days,
            'reason' => $this->reason,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'rejected_by' => $this->rejected_by,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'current_approval_level' => $this->current_approval_level,
            'approval_chain' => $this->approval_chain,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            // Conditional relationship loading
            'user' => $this->whenLoaded('user', function () {
                return new UserResource($this->user);
            }),
            'approved_by_user' => $this->whenLoaded('approvedBy', function () {
                return new UserResource($this->approvedBy);
            }),
            'rejected_by_user' => $this->whenLoaded('rejectedBy', function () {
                return new UserResource($this->rejectedBy);
            }),
            'leave_setting' => $this->whenLoaded('leaveSetting', function () {
                return [
                    'id' => $this->leaveSetting->id,
                    'name' => $this->leaveSetting->type,
                ];
            }),
        ];
    }
}
