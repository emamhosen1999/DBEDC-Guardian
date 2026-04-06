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
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email is required to sign in.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Password is required to sign in.',
            'device_name.max' => 'Device name may not be greater than 120 characters.',
        ];
    }
}
