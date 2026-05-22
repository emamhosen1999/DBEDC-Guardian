<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class DailyWorkResource extends JsonResource
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
            'incharge_id' => $this->incharge_id,
            'assigned_id' => $this->assigned_id,
            'date' => $this->date?->format('Y-m-d'),
            'type' => $this->type,
            'chainage_from' => $this->chainage_from,
            'chainage_to' => $this->chainage_to,
            'status' => $this->status,
            'description' => $this->description,
            'remarks' => $this->remarks,
            'inspection_result' => $this->inspection_result,
            'rfi_response_status' => $this->rfi_response_status,
            'project_id' => $this->project_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            // Conditional relationship loading
            'user' => $this->whenLoaded('user', function () {
                return new UserResource($this->user);
            }),
            'incharge' => $this->whenLoaded('incharge', function () {
                return new UserResource($this->incharge);
            }),
            'assigned' => $this->whenLoaded('assigned', function () {
                return new UserResource($this->assigned);
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                ];
            }),
            'objections' => $this->whenLoaded('objections', function () {
                return ObjectionResource::collection($this->objections);
            }),
            'has_objection' => $this->when($this->relationLoaded('objections'), function () {
                return $this->objections->isNotEmpty();
            }),
        ];
    }
}
