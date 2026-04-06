<?php

namespace App\Http\Requests\Api\V1;

use App\Models\RfiObjection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDailyWorkObjectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(RfiObjection::$categories)],
            'type' => ['nullable', 'string', Rule::in(RfiObjection::$types)],
            'chainage_from' => ['nullable', 'string', 'max:50'],
            'chainage_to' => ['nullable', 'string', 'max:50'],
            'specific_chainages' => ['nullable', 'string', 'max:10000'],
            'chainage_range_from' => ['nullable', 'string', 'max:50'],
            'chainage_range_to' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:5000'],
            'status' => ['nullable', 'string', Rule::in([RfiObjection::STATUS_DRAFT, RfiObjection::STATUS_SUBMITTED])],
            'attachment_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
