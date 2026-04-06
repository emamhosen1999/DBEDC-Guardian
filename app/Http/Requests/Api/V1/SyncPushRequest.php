<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'mutations' => ['required', 'array', 'min:1', 'max:100'],
            'mutations.*.idempotency_key' => ['required', 'string', 'max:191', 'distinct'],
            'mutations.*.module' => ['required', 'string', Rule::in(['attendance', 'leaves', 'daily_works'])],
            'mutations.*.action' => ['required', 'string', Rule::in([
                'punch',
                'apply',
                'cancel',
                'update_status',
                'submit_objection',
                'review_objection',
                'resolve_objection',
                'reject_objection',
            ])],
            'mutations.*.payload' => ['present', 'array'],
        ];
    }
}
