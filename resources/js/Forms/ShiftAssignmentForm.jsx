import React, { useState, useMemo, useEffect } from 'react';
import { Dialog, Flex, Box, Select, TextField, Button, Text, Callout, SegmentedControl } from '@radix-ui/themes';
import { InfoCircledIcon, ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import DateTimePicker from '@/Components/DateTimePicker';
import SearchableMultiSelect from '@/Components/SearchableMultiSelect';
import { violationsFromResult, groupViolationsByEmployee, keyEmployeesById } from '@/Pages/Attendance/complianceViolations';

export default function ShiftAssignmentForm({ open, onOpenChange, onSaved, assignment = null, employees = [], departments = [], designations = [] }) {
    const isEdit = !!assignment;
    const empty = {
        scope_type: 'user',
        scope_ids: [],
        shift_id: '',
        rotation_pattern_id: '',
        anchor_date: '',
        effective_from: '',
        effective_to: '',
        priority: 0,
    };
    const [form, setForm] = useState(empty);
    const [assignmentType, setAssignmentType] = useState('shift'); // 'shift' or 'pattern'
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    // Compliance warnings returned by the last successful save — shown under the
    // form (non-blocking); the dialog stays open until the user dismisses them.
    const [savedViolations, setSavedViolations] = useState([]);
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const employeesById = useMemo(() => keyEmployeesById(employees), [employees]);

    // Read-only scope label shown when editing an existing assignment.
    const scopeText = useMemo(() => {
        if (!assignment) return '';
        if (assignment.scope_type === 'org') return 'Whole Organization';
        const list = assignment.scope_type === 'department' ? departments
            : assignment.scope_type === 'designation' ? designations : employees;
        const found = list.find(x => Number(x.id) === Number(assignment.scope_id));
        const name = found ? (found.name || found.title) : `#${assignment.scope_id}`;
        const lbl = assignment.scope_type.charAt(0).toUpperCase() + assignment.scope_type.slice(1);
        return `${lbl}: ${name}`;
    }, [assignment, departments, designations, employees]);

    // Reset (create) or prefill (edit) the form when the dialog opens.
    useEffect(() => {
        if (!open) return;
        if (assignment) {
            setForm({
                scope_type: assignment.scope_type,
                scope_ids: assignment.scope_id != null ? [assignment.scope_id] : [],
                shift_id: assignment.shift?.id ? String(assignment.shift.id) : '',
                rotation_pattern_id: assignment.rotation_pattern?.id ? String(assignment.rotation_pattern.id) : '',
                anchor_date: assignment.anchor_date || '',
                effective_from: assignment.effective_from || '',
                effective_to: assignment.effective_to || '',
                priority: assignment.priority ?? 0,
            });
            setAssignmentType(assignment.rotation_pattern?.id ? 'pattern' : 'shift');
        } else {
            setForm(empty);
            setAssignmentType('shift');
        }
        setError('');
        setSavedViolations([]);
    }, [open, assignment]);

    const { data: shiftsData } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: open,
    });
    const shifts = shiftsData?.shifts || [];

    const { data: patternsData } = useQuery({
        queryKey: ['rotation-patterns'],
        queryFn: () => requestJson('get', '/attendance/rotation-patterns'),
        enabled: open,
    });
    const patterns = patternsData?.patterns || [];

    const scopeOptions = useMemo(() => {
        if (form.scope_type === 'department') return departments.map(d => ({ value: d.id, label: d.name }));
        if (form.scope_type === 'designation') return designations.map(d => ({ value: d.id, label: d.title || d.name }));
        if (form.scope_type === 'user') return employees.map(e => ({ value: e.id, label: e.name }));
        return [];
    }, [form.scope_type, departments, designations, employees]);

    const scopeLabel = form.scope_type === 'department' ? 'Departments'
        : form.scope_type === 'designation' ? 'Designations'
        : form.scope_type === 'user' ? 'Employees' : '';

    const save = async () => {
        setError('');
        setSaving(true);
        try {
            let res;
            if (isEdit) {
                // Editing supersedes in place (e.g. set an end-date). Scope is fixed.
                const payload = {
                    shift_id: assignmentType === 'shift' ? Number(form.shift_id) || null : null,
                    rotation_pattern_id: assignmentType === 'pattern' ? Number(form.rotation_pattern_id) || null : null,
                    priority: Number(form.priority) || 0,
                    anchor_date: form.anchor_date,
                    effective_from: form.effective_from,
                    effective_to: form.effective_to || null,
                };
                res = await requestJson('put', `/attendance/shift-assignments/${assignment.id}`, { data: payload });
                showToast.success('Assignment updated.');
            } else if (form.scope_type === 'org') {
                // Org-wide = single assignment, use original endpoint
                const payload = {
                    scope_type: 'org',
                    scope_id: null,
                    shift_id: assignmentType === 'shift' ? Number(form.shift_id) || null : null,
                    rotation_pattern_id: assignmentType === 'pattern' ? Number(form.rotation_pattern_id) || null : null,
                    priority: Number(form.priority) || 0,
                    anchor_date: form.anchor_date,
                    effective_from: form.effective_from,
                    effective_to: form.effective_to || null,
                };
                res = await requestJson('post', '/attendance/shift-assignments', { data: payload });
                showToast.success('Organization-wide shift assignment saved.');
            } else {
                // Multi-select: use bulk endpoint
                const payload = {
                    scope_type: form.scope_type,
                    scope_ids: form.scope_ids.map(Number),
                    shift_id: assignmentType === 'shift' ? Number(form.shift_id) || null : null,
                    rotation_pattern_id: assignmentType === 'pattern' ? Number(form.rotation_pattern_id) || null : null,
                    priority: Number(form.priority) || 0,
                    anchor_date: form.anchor_date,
                    effective_from: form.effective_from,
                    effective_to: form.effective_to || null,
                };
                res = await requestJson('post', '/attendance/shift-assignments/bulk', { data: payload });
                const msg = res?.message || `${form.scope_ids.length} assignment(s) created.`;
                showToast.success(msg);
            }

            onSaved?.();

            // Working-time compliance is informational only here (never blocks the
            // save): if the response carries warnings, keep the dialog open and
            // show them under the form instead of closing immediately.
            const violations = violationsFromResult(res);
            if (violations.length > 0) {
                setSavedViolations(groupViolationsByEmployee(violations, employeesById));
            } else {
                onOpenChange(false);
            }
        } catch (e) {
            const msg = e?.message || 'Failed to save assignment.';
            setError(msg);
            showToast.error(msg);
        } finally {
            setSaving(false);
        }
    };

    const canSave = (assignmentType === 'shift' ? form.shift_id : form.rotation_pattern_id)
        && form.anchor_date
        && form.effective_from
        && (form.scope_type === 'org' || form.scope_ids.length > 0);

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v) { setError(''); setForm(empty); } onOpenChange(v); }}>
            <Dialog.Content maxWidth="540px">
                <Dialog.Title>{isEdit ? 'Edit Assignment' : 'Assign Shift'}</Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="3">
                    {isEdit
                        ? 'Update this assignment — e.g. set an end date to supersede it from a future date.'
                        : 'Assign a shift to one or more employees, departments, or designations.'}
                </Dialog.Description>

                <Flex direction="column" gap="3">
                    {isEdit && (
                        <Callout.Root color="blue" size="1">
                            <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                            <Callout.Text>Editing assignment — {scopeText}</Callout.Text>
                        </Callout.Root>
                    )}
                    {!isEdit && (<>
                    {/* Scope type selector */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1" weight="medium">Assign To</Text>
                        <Select.Root value={form.scope_type} onValueChange={v => { set('scope_type', v); set('scope_ids', []); }}>
                            <Select.Trigger style={{ width: '100%' }} />
                            <Select.Content>
                                <Select.Item value="user">Employee(s)</Select.Item>
                                <Select.Item value="department">Department(s)</Select.Item>
                                <Select.Item value="designation">Designation(s)</Select.Item>
                                <Select.Item value="org">Whole Organization</Select.Item>
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    {/* Multi-select for scope items */}
                    {form.scope_type !== 'org' && (
                        <SearchableMultiSelect
                            label={`Select ${scopeLabel}`}
                            options={scopeOptions}
                            selected={form.scope_ids}
                            onChange={v => set('scope_ids', v)}
                            placeholder={`Choose ${scopeLabel.toLowerCase()}…`}
                            maxDisplay={4}
                        />
                    )}

                    {form.scope_type === 'org' && (
                        <Callout.Root color="blue" size="1">
                            <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                            <Callout.Text>This assignment will apply to all employees.</Callout.Text>
                        </Callout.Root>
                    )}

                    {/* Selection summary */}
                    {form.scope_type !== 'org' && form.scope_ids.length > 0 && (
                        <Callout.Root color="green" size="1">
                            <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                            <Callout.Text>
                                {form.scope_ids.length} {scopeLabel.toLowerCase()} selected — {form.scope_ids.length} assignment{form.scope_ids.length !== 1 ? 's' : ''} will be created.
                            </Callout.Text>
                        </Callout.Root>
                    )}
                    </>)}

                    {/* Assignment Type Selector */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1" weight="medium">Assignment Type *</Text>
                        <SegmentedControl.Root value={assignmentType} onValueChange={setAssignmentType} style={{ width: '100%' }}>
                            <SegmentedControl.Item value="shift">Single Shift</SegmentedControl.Item>
                            <SegmentedControl.Item value="pattern">Rotation Pattern</SegmentedControl.Item>
                        </SegmentedControl.Root>
                    </Box>

                    {/* Shift / Pattern selector */}
                    {assignmentType === 'shift' ? (
                        <Box>
                            <Text size="1" color="gray" as="div" mb="1" weight="medium">Shift *</Text>
                            <Select.Root value={String(form.shift_id)} onValueChange={v => set('shift_id', v)}>
                                <Select.Trigger placeholder="Select shift" style={{ width: '100%' }} />
                                <Select.Content>
                                    {shifts.map(s => (
                                        <Select.Item key={s.id} value={String(s.id)}>
                                            {s.name} ({s.code})
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                    ) : (
                        <Box>
                            <Text size="1" color="gray" as="div" mb="1" weight="medium">Rotation Pattern *</Text>
                            <Select.Root value={String(form.rotation_pattern_id)} onValueChange={v => set('rotation_pattern_id', v)}>
                                <Select.Trigger placeholder="Select rotation pattern" style={{ width: '100%' }} />
                                <Select.Content>
                                    {patterns.map(p => (
                                        <Select.Item key={p.id} value={String(p.id)}>
                                            {p.name} ({p.code})
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                    )}

                    {/* Date pickers */}
                    <Flex gap="3" wrap="wrap">
                        <Box style={{ flex: '1 1 130px' }}>
                            <DateTimePicker
                                mode="date"
                                label="Anchor date"
                                value={form.anchor_date}
                                onChange={v => set('anchor_date', v)}
                            />
                        </Box>
                        <Box style={{ flex: '1 1 130px' }}>
                            <DateTimePicker
                                mode="date"
                                label="Effective from *"
                                value={form.effective_from}
                                onChange={v => set('effective_from', v)}
                            />
                        </Box>
                        <Box style={{ flex: '1 1 130px' }}>
                            <DateTimePicker
                                mode="date"
                                label="Effective to"
                                value={form.effective_to}
                                onChange={v => set('effective_to', v)}
                            />
                        </Box>
                    </Flex>

                    {/* Priority */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">Priority</Text>
                        <TextField.Root
                            type="number"
                            placeholder="Priority (0 = default)"
                            value={form.priority}
                            onChange={e => set('priority', e.target.value)}
                            style={{ width: 160 }}
                        />
                    </Box>

                    {error && <Text color="red" size="2">{error}</Text>}

                    {savedViolations.length > 0 && (
                        <Callout.Root color="amber" size="1">
                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                            <Callout.Text>
                                <Text weight="medium" as="div" mb="1">
                                    Assignment saved — working-time compliance warnings for review:
                                </Text>
                                <Flex direction="column" gap="1">
                                    {savedViolations.flatMap((g) => g.violations.map((v, i) => (
                                        <Text key={`${g.userId}-${i}`} as="div" size="1">
                                            <Text weight="medium">{g.name}</Text>: {v.date} — {v.message}
                                        </Text>
                                    )))}
                                </Flex>
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    <Flex justify="end" gap="2" mt="2">
                        {savedViolations.length > 0 ? (
                            <Button onClick={() => { setSavedViolations([]); onOpenChange(false); }}>
                                Done
                            </Button>
                        ) : (
                            <>
                                <Button variant="soft" color="gray" onClick={() => onOpenChange(false)} disabled={saving}>
                                    Cancel
                                </Button>
                                <Button onClick={save} disabled={saving || !canSave}>
                                    {saving ? 'Saving…' : isEdit ? 'Save changes'
                                        : form.scope_type !== 'org' && form.scope_ids.length > 1
                                            ? `Assign to ${form.scope_ids.length} ${scopeLabel}`
                                            : 'Save'}
                                </Button>
                            </>
                        )}
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
