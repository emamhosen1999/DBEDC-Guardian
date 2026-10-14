<?php

namespace App\Http\Resources\Jurisdiction;

use App\Models\Jurisdiction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * /jurisdictions/json and the write responses. Keeps every field the old endpoint had (id, location, start_chainage,
 * end_chainage, incharge, incharge_user, created_at, updated_at) and adds the new ones.
 *
 * @mixin Jurisdiction
 */
class JurisdictionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name ?? $this->location,
            'location' => $this->location,
            'start_chainage' => $this->start_chainage,
            'end_chainage' => $this->end_chainage,
            'start_m' => $this->start_m,
            'end_m' => $this->end_m,
            'length_m' => $this->lengthM(),
            'incharge' => $this->incharge,
            'incharge_user' => $this->whenLoaded('inchargeUser', fn () => $this->inchargeUser === null ? null : ['employee_id' => $this->inchargeUser->employee_id, 'name' => $this->inchargeUser->name]),
            'description' => $this->description,
            'colour_token' => $this->colour_token,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
