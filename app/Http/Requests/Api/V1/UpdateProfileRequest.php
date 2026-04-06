<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'user_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'about' => ['nullable', 'string', 'max:2000'],
            'birthday' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'emergency_contact_primary_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_primary_phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
