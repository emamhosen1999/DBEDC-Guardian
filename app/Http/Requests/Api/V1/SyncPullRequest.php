<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncPullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            // Opaque continuation token. Optional (absent ⇒ sync from the beginning).
            // Accepts either a single scalar watermark applied to every module, or a
            // per-module object: cursor[attendance]=<token>&cursor[leaves]=<token>.
            'cursor' => ['nullable'],
            'cursor.*' => ['sometimes', 'nullable', 'string', 'max:191'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(['attendance', 'leaves', 'daily_works'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
