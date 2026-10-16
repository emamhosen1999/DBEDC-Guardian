<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A day-based overtime claim (web and mobile share it). The date rules (worked, holiday or rest day, credit) and the clarity of the
 * document are decided by the service; here only the shape is checked, and a supporting document is always required.
 */
class StoreOvertimeClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'compensation' => ['required', 'in:payment,replacement'],
            'declared_end_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
            'attachment_tokens' => ['required', 'array', 'min:1', 'max:'.config('attachments.limits.max_files')],
            'attachment_tokens.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'attachment_tokens.required' => __('attachments.errors.required'),
            'attachment_tokens.min' => __('attachments.errors.required'),
        ];
    }
}
