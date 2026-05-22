<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
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
            'date' => $this->date?->format('Y-m-d'),
            'punchin_time' => $this->punchin?->format('H:i:s'),
            'punchout_time' => $this->punchout?->format('H:i:s'),
            'punchin_location' => $this->punchin_location_array,
            'punchout_location' => $this->punchout_location_array,
            'is_late' => (bool) $this->is_late,
            'duration' => $this->when($this->punchin && $this->punchout, function () {
                $duration = $this->punchin->diffInSeconds($this->punchout);
                return gmdate('H:i:s', $duration);
            }),
            'punchin_photo_url' => $this->punchin_photo_url,
            'punchout_photo_url' => $this->punchout_photo_url,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            // Conditional relationship loading
            'user' => $this->whenLoaded('user', function () {
                return new UserResource($this->user);
            }),
        ];
    }
}
