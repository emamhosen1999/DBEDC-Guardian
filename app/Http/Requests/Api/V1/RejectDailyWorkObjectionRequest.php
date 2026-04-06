<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RejectDailyWorkObjectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required_without:resolution_notes', 'string', 'max:5000'],
            'resolution_notes' => ['required_without:rejection_reason', 'string', 'max:5000'],
        ];
    }
}
