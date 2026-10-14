<?php

namespace App\Http\Requests\Jurisdiction;

use App\Enums\Carriageway;
use App\Enums\FacilitySubtype;
use App\Enums\JurisdictionKind;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\GeofenceSource;
use App\Services\Jurisdiction\JurisdictionPalette;
use App\Support\Corridor\Chainage;
use App\Support\Corridor\GeoPolygon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update a jurisdiction. The server is the source of truth for the rules:
 * 0 <= start_m < end_m <= 48,999 (the corridor), a unique code and name; overlap with another jurisdiction on the effective
 * date is refused by the version service. A change may carry an effective_date (past = correction: permission + reason).
 * Older callers may still send start_chainage / end_chainage ("K0+000"): they are read into metres here.
 */
abstract class SaveJurisdictionRequest extends FormRequest
{
    abstract protected function ignoreId(): ?int;

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['start' => 'start_chainage', 'end' => 'end_chainage'] as $side => $text) {
            if (! $this->filled("{$side}_m") && $this->filled($text)) {
                $m = Chainage::token((string) $this->input($text));
                if ($m !== null) {
                    $merge["{$side}_m"] = $m;
                }
            }
        }
        if (! $this->filled('name') && $this->filled('location')) {
            $merge['name'] = $this->input('location');
        }
        if (is_string($this->input('code'))) {
            $merge['code'] = strtoupper(trim($this->input('code')));
        }
        // An area's polygon: an existing geofence, or uploaded GeoJSON (validated and simplified).
        try {
            if ($this->filled('geofence_id')) {
                $merge['geometry'] = app(GeofenceSource::class)->find((string) $this->input('geofence_id')) ?? $this->input('geometry');
            } elseif ($this->filled('geojson') || ($this->filled('geometry') && $this->kindValue() === 'area')) {
                $merge['geometry'] = GeoPolygon::normalize($this->input('geojson', $this->input('geometry')));
            }
        } catch (\InvalidArgumentException $e) {
            $this->geometryError = $e->getMessage();
            unset($merge['geometry']);
        }
        $this->merge($merge);
    }

    private ?string $geometryError = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $ignore = $this->ignoreId();
        $kind = $this->kindValue();
        $chain = $kind !== 'area';

        return [
            'kind' => ['sometimes', Rule::in(JurisdictionKind::values())],
            'subtype' => [$kind === 'facility' ? 'required' : 'nullable', Rule::in(FacilitySubtype::values())],
            'carriageway' => ['nullable', Rule::in(Carriageway::values())],
            'geometry' => [$kind === 'area' ? 'required' : 'nullable', 'array'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,31}$/', Rule::unique('jurisdictions', 'code')->ignore($ignore)],
            'name' => ['required', 'string', 'max:120', Rule::unique('jurisdictions', 'location')->whereNull('deleted_at')->ignore($ignore)],
            'start_m' => [$chain ? 'required' : 'nullable', 'integer', 'min:'.ChainageResolver::CORRIDOR_START_M, 'max:'.ChainageResolver::CORRIDOR_END_M],
            'end_m' => [$chain ? 'required' : 'nullable', 'integer', 'min:'.ChainageResolver::CORRIDOR_START_M, 'max:'.ChainageResolver::CORRIDOR_END_M],
            'description' => ['nullable', 'string', 'max:1000'],
            'colour_token' => ['nullable', 'string', Rule::in(JurisdictionPalette::tokens())],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** The kind being saved: the request's own on create, the jurisdiction's on update (a kind never changes). */
    protected function kindValue(): string
    {
        return (string) $this->input('kind', 'section');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'A jurisdiction code is required, for example PH-1.',
            'code.regex' => 'Use capital letters, digits, hyphen or underscore (2 to 32 characters), for example PH-1.',
            'code.unique' => 'This code is already used by another jurisdiction.',
            'name.required' => 'Jurisdiction name is required.',
            'name.unique' => 'A jurisdiction with this name already exists.',
            'start_m.required' => 'Start chainage is required.',
            'end_m.required' => 'End chainage is required.',
            'start_m.max' => 'Start chainage must be inside the corridor (K0+000 to K48+999).',
            'end_m.max' => 'End chainage must be inside the corridor (K0+000 to K48+999).',
            'colour_token.in' => 'Choose a colour from the map palette.',
            'effective_date.date_format' => 'Enter the effective date as a calendar date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->geometryError !== null) {
                $v->errors()->add('geometry', $this->geometryError);
            }
            if ($this->kindValue() === 'area' || $v->errors()->hasAny(['start_m', 'end_m'])) {
                return;
            }
            $start = (int) $this->input('start_m');
            $end = (int) $this->input('end_m');
            if ($start >= $end && ($this->kindValue() === 'section' || $end < $start)) {
                $v->errors()->add('end_m', 'End chainage must be after the start chainage ('.Chainage::format($start).').');

                return;
            }
            // Overlap in space is time-aware (a boundary may move on a date): JurisdictionVersionService checks it and throws a 422.
        });
    }
}
