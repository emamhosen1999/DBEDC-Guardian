import React, { useMemo, useState } from 'react';

import { FormField, Input, SelectInput } from '@/Components/Cyber';

/**
 * Pick an employee: a search box filters a native select (keyboard and screen-reader friendly). Options come from the
 * page's employee list; `exclude` hides people already on the team. Employees only - never "users".
 */
export default function EmployeePicker({ employees, value, onChange, label = 'Employee', error, exclude = [], suggestedIds = [], id = 'jr-employee' }) {
    const [q, setQ] = useState('');
    const options = useMemo(() => {
        const skip = new Set(exclude.map(String));
        const needle = q.trim().toLowerCase();
        const rows = employees
            .filter((e) => !skip.has(String(e.employee_id)))
            .filter((e) => !needle || `${e.name} ${e.employee_id} ${e.designation_title ?? ''}`.toLowerCase().includes(needle))
            .sort((a, b) => (suggestedIds.includes(String(b.employee_id)) ? 1 : 0) - (suggestedIds.includes(String(a.employee_id)) ? 1 : 0) || String(a.name).localeCompare(String(b.name)))
            .slice(0, 60)
            .map((e) => ({ value: String(e.employee_id), label: `${e.name}${e.designation_title ? `, ${e.designation_title}` : ''} (${e.employee_id})` }));
        return [{ value: '', label: rows.length ? 'Choose an employee' : 'No employee matches', disabled: true }, ...rows];
    }, [employees, exclude, q, suggestedIds]);

    return (
        <FormField label={label} htmlFor={id} error={error} required>
            {({ describedBy }) => (
                <div className="jr-pick">
                    <Input type="search" size="sm" aria-label={`Search ${label.toLowerCase()}`} placeholder="Search by name, designation or ID" value={q} onChange={(e) => setQ(e.target.value)} />
                    <SelectInput id={id} aria-describedby={describedBy} invalid={!!error} value={value ?? ''} onChange={onChange} options={options} />
                </div>
            )}
        </FormField>
    );
}
