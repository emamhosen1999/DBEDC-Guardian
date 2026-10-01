import React from 'react';
import { Badge, Box, Select, Text } from '@radix-ui/themes';
import { useDepartmentScope } from '@/Hooks/useDepartmentScope';

/*
 * THE department filter. Every "All Departments" dropdown in the app renders this one
 * component, so a department-scoped operator sees the same thing everywhere:
 *
 *   global actor          -> the full list, with an "All Departments" option
 *   one department        -> no dropdown at all, only a read-only "Department: X" badge
 *                            (a filter with a single possible value is noise — the server
 *                            already confines every list to it)
 *   several departments   -> a limited list of exactly those, "All my departments" as the
 *                            all-option
 *
 * `value` is the filter's own value ('all' or a department id). A single-scope actor's
 * value stays 'all' — the backend scope does the narrowing — so callers that make a second
 * filter depend on "a department is chosen" should read `useDepartmentScope().single` too.
 */
export default function DepartmentFilter({
    value,
    onChange,
    departments = null,
    label = null,
    allLabel = null,
    size = '2',
    width = '100%',
    minWidth = undefined,
    placeholder = null,
    disabled = false,
    allValue = 'all',
    includeAll = true,
    badgeSize = '2',
    attendance = false,
    ...rest
}) {
    const scope = useDepartmentScope(departments, { attendance });

    if (scope.isSingle) {
        return (
            <Box {...rest}>
                {label && <Text size="2" color="gray" mb="1" as="div">{label}</Text>}
                <Badge color="blue" variant="soft" size={badgeSize} data-testid="department-scope-badge" style={{ whiteSpace: 'nowrap' }}>
                    Department: {scope.single.name}
                </Badge>
            </Box>
        );
    }

    if (scope.departments.length === 0) {
        return null;
    }

    const resolvedAllLabel = allLabel ?? (scope.isMulti ? 'All my departments' : 'All Departments');
    const current = value === undefined || value === null || value === '' ? (includeAll ? allValue : undefined) : String(value);

    return (
        <Box {...rest}>
            {label && <Text size="2" color="gray" mb="1" as="div">{label}</Text>}
            <Select.Root size={size} value={current} onValueChange={onChange} disabled={disabled}>
                <Select.Trigger
                    placeholder={placeholder ?? resolvedAllLabel}
                    aria-label={label || 'Department'}
                    style={{ width, minWidth }}
                />
                <Select.Content>
                    {includeAll && <Select.Item value={allValue}>{resolvedAllLabel}</Select.Item>}
                    {scope.departments.map((department) => (
                        <Select.Item key={department.id} value={String(department.id)}>{department.name}</Select.Item>
                    ))}
                </Select.Content>
            </Select.Root>
        </Box>
    );
}
