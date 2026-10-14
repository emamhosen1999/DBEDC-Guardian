import React, { useEffect, useId } from 'react';
import { useDepartmentScope } from '@/Hooks/useDepartmentScope';
import { FormField, Input, SelectInput } from '@/Components/Cyber';

/*
 * DepartmentField in Cyber form controls (same props, same scope rules): a global actor gets the full list (plus an
 * optional "none" entry via `noneLabel`), one department is a read-only locked field whose value is filled in, several
 * departments get a limited list. `value` / `onChange` carry a department id as a string ('' = none).
 */
export default function DepartmentFieldCyber({
    value,
    onChange,
    departments = null,
    label = 'Department',
    required = false,
    noneLabel = null,
    placeholder = 'Select department',
    error = null,
    disabled = false,
    helper = null,
    attendance = false,
}) {
    const id = useId();
    const scope = useDepartmentScope(departments, { attendance });
    const lockedId = scope.isSingle ? String(scope.single.id) : null;

    // A single-scope actor's department is not a choice: fill it in so every submit carries it.
    useEffect(() => {
        if (lockedId !== null && String(value ?? '') !== lockedId) {
            onChange(lockedId);
        }
    }, [lockedId, value, onChange]);

    if (scope.isSingle) {
        return (
            <FormField label={label} htmlFor={id} required={required} hint="You manage this department only.">
                <Input id={id} readOnly value={scope.single.name} aria-label={`${label} (locked to your department)`} data-testid="department-field-locked" />
            </FormField>
        );
    }

    const allowNone = Boolean(noneLabel) && scope.isGlobal;
    const current = value === undefined || value === null || value === '' ? (allowNone ? 'none' : '') : String(value);
    const options = [
        ...(allowNone ? [{ value: 'none', label: noneLabel }] : (current === '' ? [{ value: '', label: placeholder, disabled: true }] : [])),
        ...scope.departments.map((department) => ({ value: String(department.id), label: department.name })),
    ];

    return (
        <FormField label={label} htmlFor={id} required={required} hint={helper} error={error}>
            <SelectInput id={id} value={current} disabled={disabled} invalid={Boolean(error)} onChange={(next) => onChange(next === 'none' ? '' : next)} options={options} />
        </FormField>
    );
}
