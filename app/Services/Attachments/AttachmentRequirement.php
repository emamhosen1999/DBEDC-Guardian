<?php

namespace App\Services\Attachments;

use App\Models\HRM\LeaveSetting;

/** When a supporting document is mandatory. Overtime claims and replacement-leave requests always need one; leave follows its type's rule. */
class AttachmentRequirement
{
    /** @return string|null the message to show when a document is required but missing; null when none is needed */
    public function leaveMessage(?LeaveSetting $type, float $days): ?string
    {
        if (! $type) {
            return null;
        }
        $rule = $type->attachment_rule ?: ($type->requires_attachment_days !== null ? 'beyond_days' : 'optional');

        return match ($rule) {
            'always' => __('attachments.errors.required_leave_always', ['type' => $type->type]),
            'beyond_days' => $type->requires_attachment_days !== null && $days > (float) $type->requires_attachment_days
                ? __('attachments.errors.required_leave_days', ['type' => $type->type, 'days' => rtrim(rtrim((string) $type->requires_attachment_days, '0'), '.')])
                : null,
            default => null,
        };
    }

    public function isRequiredForLeave(?LeaveSetting $type, float $days): bool
    {
        return $this->leaveMessage($type, $days) !== null;
    }
}
