import React from 'react';
import { useDepartmentScope } from '@/Hooks/useDepartmentScope';
import { Badge } from '@/Components/Cyber';
import { Select } from '@/Components/Cyber';

/*
 * The attendance department filter in Cyber's form-select look. Same rules as Components/Access/DepartmentFilter (the
 * Radix one other pages still use): a company-wide actor gets every department, one department shows a read-only badge
 * (a filter with a single value is noise; the server already confines every list to it), several get a limited list.
 * `value` is 'all' or a department id; onChange receives the raw value ('all' included).
 */
export default function DepartmentSelect({ value, onChange, departments = null, allLabel = null, label = 'Department' }) {
    const scope = useDepartmentScope(departments, { attendance: true });

    if (scope.isSingle) {
        return <Badge color="theme" data-testid="department-scope-badge">Department: {scope.single.name}</Badge>;
    }
    if (scope.departments.length === 0) return null;

    const all = allLabel ?? (scope.isMulti ? 'All my departments' : 'All departments');
    const current = value === undefined || value === null || value === '' ? 'all' : String(value);
    return (
        <Select
            label={label}
            value={current}
            onChange={onChange}
            options={[{ value: 'all', label: all }, ...scope.departments.map((d) => ({ value: String(d.id), label: d.name }))]}
        />
    );
}
