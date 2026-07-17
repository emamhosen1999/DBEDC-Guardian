<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\GuardsServerAuthoritativePunchTime;
use Illuminate\Foundation\Http\FormRequest;

class PunchAttendanceRequest extends FormRequest
{
    use GuardsServerAuthoritativePunchTime;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:1000'],
            'qr_code' => ['nullable', 'string', 'max:512'],
            'photo' => ['nullable', 'string'],
            // Client-reported mock-provider (fake GPS) signal — Android only, and
            // absent on older clients. Nullable so omitting it is always valid; the
            // punch is only rejected when it is explicitly true and the
            // attendance.reject_mock_location flag is on.
            'is_mocked' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->stripDeviceTrustedPunchFields();
    }
}
