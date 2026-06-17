<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ObjectionResource extends JsonResource
{
    /**
     * Transform the resource into an array
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'daily_work_id' => $this->daily_work_id,
            'category' => $this->category,
            'type' => $this->type,
            'status' => $this->status,
            'description' => $this->description,
            'raised_by' => $this->raised_by,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            // Conditional relationship loading
            'raised_by_user' => $this->whenLoaded('raisedBy', function () {
                return new UserResource($this->raisedBy);
            }),
            'resolved_by_user' => $this->whenLoaded('resolvedBy', function () {
                return new UserResource($this->resolvedBy);
            }),
            'files' => $this->whenLoaded('media', function () {
                return $this->media->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'file_name' => $media->file_name,
                        'url' => $media->getUrl(),
                        'size' => $media->size,
                    ];
                });
            }),
        ];
    }
}
