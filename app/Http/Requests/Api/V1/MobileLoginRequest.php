<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MobileLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'device_id' => ['required', 'string', 'size:36', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'],
            'device_signature' => ['required', 'array'],
            'device_signature.signature' => ['nullable', 'string', 'max:1000'],
            'device_signature.platform' => ['required', 'string', 'max:60'],
            'device_signature.os_version' => ['required', 'string', 'max:60'],
            'device_signature.model' => ['nullable', 'string', 'max:120'],
            'device_signature.manufacturer' => ['nullable', 'string', 'max:120'],
            'device_signature.brand' => ['nullable', 'string', 'max:120'],
            'device_signature.hardware_id' => ['nullable', 'string', 'max:191'],
            'device_signature.app_version' => ['nullable', 'string', 'max:40'],
            'device_signature.build_version' => ['nullable', 'string', 'max:40'],
            'device_signature.mac_address' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required to sign in.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Password is required to sign in.',
            'device_name.max' => 'Device name may not be greater than 120 characters.',
            'device_id.required' => 'A device identifier is required for secure login.',
            'device_id.regex' => 'Device identifier format is invalid.',
            'device_signature.required' => 'Device signature data is required for secure login.',
            'device_signature.platform.required' => 'Device platform is required.',
            'device_signature.os_version.required' => 'Device OS version is required.',
        ];
    }
}
