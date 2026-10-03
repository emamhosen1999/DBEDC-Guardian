<?php

namespace App\Rules;

use App\Services\Access\DepartmentScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A reporting manager may be anyone except the employee themself or someone who already reports to
 * them (directly or indirectly): either loops the reporting tree, and approval chains built from it
 * would route a request back to its own requester.
 */
class ReportingManager implements ValidationRule
{
    /** @param  string|null  $employeeId  the employee whose manager is being set (null while creating one) */
    public function __construct(private readonly ?string $employeeId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $this->employeeId === null || $this->employeeId === '') {
            return;
        }

        if ((string) $value === $this->employeeId) {
            $fail('An employee cannot report to themselves.');

            return;
        }

        $reports = array_map('strval', app(DepartmentScope::class)->descendantIds($this->employeeId));
        if (in_array((string) $value, $reports, true)) {
            $fail('The selected manager already reports to this employee, so this would create a reporting loop.');
        }
    }
}
