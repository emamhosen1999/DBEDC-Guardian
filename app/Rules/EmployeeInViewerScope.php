<?php

namespace App\Rules;

use App\Services\Access\DepartmentScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An employee id the acting user may see (DepartmentScope::visibleEmployeeIds). The pickers only offer those people; this
 * is the same limit enforced on the server so a crafted request cannot reach past it. An unchanged existing value is allowed.
 */
class EmployeeInViewerScope implements ValidationRule
{
    public function __construct(private readonly ?object $actor, private readonly ?string $unchanged = null, private readonly string $what = 'employee') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $this->actor === null) {
            return;
        }
        if ($this->unchanged !== null && (string) $value === $this->unchanged) {
            return;
        }
        $visible = app(DepartmentScope::class)->visibleEmployeeIds($this->actor);
        if ($visible !== null && ! in_array((string) $value, $visible, true)) {
            $fail('The '.$this->what.' must be an employee within your scope.');
        }
    }
}
