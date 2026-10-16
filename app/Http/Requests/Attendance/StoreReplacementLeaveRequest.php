<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/** A request for the replacement day off. Always carries a clear application image. */
class StoreReplacementLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'days' => ['required', 'in:0.5,1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attachment_tokens' => ['required', 'array', 'min:1', 'max:'.config('attachments.limits.max_files')],
            'attachment_tokens.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return ['attachment_tokens.required' => __('attachments.errors.required'), 'attachment_tokens.min' => __('attachments.errors.required')];
    }
}
