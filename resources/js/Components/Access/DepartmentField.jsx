import React, { useEffect } from 'react';
import { Box, Select, Text, TextField } from '@radix-ui/themes';
import { LockClosedIcon } from '@radix-ui/react-icons';
import { useDepartmentScope } from '@/Hooks/useDepartmentScope';

/*
 * The department field of a FORM (employee, designation, shift, rotation pattern, ...),
 * scope-aware the same way DepartmentFilter is:
 *
 *   global actor          -> the full list (plus an optional "none" entry via `noneLabel`)
 *   one department        -> a read-only field locked to it; the value is set for them
 *   several departments   -> a limited list of exactly those (never a "none" entry: a
 *                            scoped actor can only create things owned by their departments)
 *
 * `value` / `onChange` carry a department id as a string ('' = none).
 */
export default function DepartmentField({
    value,
    onChange,
    departments = null,
    label = 'Department',
    required = false,
    noneLabel = null,
    placeholder = 'Select department',
    error = null,
    disabled = false,
    size = '2',
    helper = null,
    attendance = false,
}) {
    const scope = useDepartmentScope(departments, { attendance });
    const lockedId = scope.isSingle ? String(scope.single.id) : null;

    // A single-scope actor's department is not a choice: fill it in so every submit carries it.
    useEffect(() => {
        if (lockedId !== null && String(value ?? '') !== lockedId) {
            onChange(lockedId);
        }
    }, [lockedId, value, onChange]);

    const heading = label && (
        <Text as="label" size="2" weight="medium" mb="1" display="block">
            {label} {required && <Text color="red">*</Text>}
        </Text>
    );

    if (scope.isSingle) {
        return (
            <Box>
                {heading}
                <TextField.Root
                    size={size}
                    readOnly
                    value={scope.single.name}
                    aria-label={`${label} (locked to your department)`}
                    data-testid="department-field-locked"
                >
                    <TextField.Slot><LockClosedIcon /></TextField.Slot>
                </TextField.Root>
                <Text size="1" color="gray" mt="1" as="div">You manage this department only.</Text>
            </Box>
        );
    }

    const allowNone = Boolean(noneLabel) && scope.isGlobal;
    const current = value === undefined || value === null || value === '' ? (allowNone ? 'none' : undefined) : String(value);

    return (
        <Box>
            {heading}
            <Select.Root
                size={size}
                value={current}
                onValueChange={(next) => onChange(next === 'none' ? '' : next)}
                disabled={disabled}
            >
                <Select.Trigger placeholder={placeholder} aria-label={label} style={{ width: '100%' }} />
                <Select.Content>
                    {allowNone && <Select.Item value="none">{noneLabel}</Select.Item>}
                    {scope.departments.map((department) => (
                        <Select.Item key={department.id} value={String(department.id)}>{department.name}</Select.Item>
                    ))}
                </Select.Content>
            </Select.Root>
            {helper && <Text size="1" color="gray" mt="1" as="div">{helper}</Text>}
            {error && <Text color="red" size="1" mt="1" display="block">{error}</Text>}
        </Box>
    );
}
