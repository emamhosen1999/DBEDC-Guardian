<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListLeavesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // Canonical {pending,approved,rejected,cancelled} plus legacy values kept for
            // backward-compat with already-stored/mobile-sent rows during the transition.
            'status' => ['nullable', 'string', 'in:New,Pending,Approved,Declined,Rejected,Cancelled,new,pending,approved,declined,rejected,cancelled'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
