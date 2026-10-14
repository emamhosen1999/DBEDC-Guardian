import React, { useState, useMemo, useEffect } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button, DateInput, FormField, Input, Segmented, SelectInput } from '@/Components/Cyber';
import { usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import SearchableMultiSelect from '@/Components/SearchableMultiSelect';
import { violationsFromResult, groupViolationsByEmployee, keyEmployeesById } from '@/Pages/Attendance/complianceViolations';

export default function ShiftAssignmentForm({ open, onOpenChange, onSaved, assignment = null, employees = [], departments = [], designations = [] }) {
    const isEdit = !!assignment;
    // Assigning to the whole organization is company-wide: the server refuses it for anyone who is
    // not an attendance administrator (a global role, or attendance.settings) — a department admin
    // assigns within their own departments.
    const { auth } = usePage().props;
    const canAssignOrg = Boolean(auth?.isSuperAdmin
        || auth?.permissions?.includes('attendance.settings')
        || ['Super Administrator', 'Administrator', 'HR Manager'].some((r) => auth?.roles?.includes(r)));
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
        const found = list.find(x => String(x.id) === String(assignment.scope_id));
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
                    scope_ids: form.scope_ids.map(String),
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

    if (!open) return null;

    const close = () => { setError(''); setForm(empty); onOpenChange(false); };

    return (
        <Dialog title={isEdit ? 'Edit Assignment' : 'Assign Shift'} onClose={close} className="cy-modal cy-modal--wide">
            <div className="cy-modal__section cy-stack">
                <p className="cy-formtext">
                    {isEdit
                        ? 'Update this assignment — e.g. set an end date to supersede it from a future date.'
                        : 'Assign a shift to one or more employees, departments, or designations.'}
                </p>

                {isEdit && <Alert tone="info">Editing assignment — {scopeText}</Alert>}
                {!isEdit && (<>
                    {/* Scope type selector */}
                    <FormField label="Assign to" htmlFor="assign-scope">
                        <SelectInput
                            id="assign-scope"
                            value={form.scope_type}
                            onChange={v => { set('scope_type', v); set('scope_ids', []); }}
                            options={[
                                { value: 'user', label: 'Employee(s)' },
                                { value: 'department', label: 'Department(s)' },
                                { value: 'designation', label: 'Designation(s)' },
                                ...(canAssignOrg ? [{ value: 'org', label: 'Whole Organization' }] : []),
                            ]}
                        />
                    </FormField>

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

                    {form.scope_type === 'org' && <Alert tone="info">This assignment will apply to all employees.</Alert>}

                    {/* Selection summary */}
                    {form.scope_type !== 'org' && form.scope_ids.length > 0 && (
                        <Alert tone="success">
                            {form.scope_ids.length} {scopeLabel.toLowerCase()} selected — {form.scope_ids.length} assignment{form.scope_ids.length !== 1 ? 's' : ''} will be created.
                        </Alert>
                    )}
                </>)}

                {/* Assignment Type Selector */}
                <FormField label="Assignment type" required>
                    <Segmented
                        label="Assignment type"
                        value={assignmentType}
                        onChange={setAssignmentType}
                        options={[{ value: 'shift', label: 'Single Shift' }, { value: 'pattern', label: 'Rotation Pattern' }]}
                    />
                </FormField>

                {/* Shift / Pattern selector */}
                {assignmentType === 'shift' ? (
                    <FormField label="Shift" htmlFor="assign-shift" required>
                        <SelectInput
                            id="assign-shift"
                            value={String(form.shift_id)}
                            onChange={v => set('shift_id', v)}
                            options={[{ value: '', label: 'Select shift', disabled: true }, ...shifts.map(s => ({ value: String(s.id), label: `${s.name} (${s.code})` }))]}
                        />
                    </FormField>
                ) : (
                    <FormField label="Rotation pattern" htmlFor="assign-pattern" required>
                        <SelectInput
                            id="assign-pattern"
                            value={String(form.rotation_pattern_id)}
                            onChange={v => set('rotation_pattern_id', v)}
                            options={[{ value: '', label: 'Select rotation pattern', disabled: true }, ...patterns.map(p => ({ value: String(p.id), label: `${p.name} (${p.code})` }))]}
                        />
                    </FormField>
                )}

                {/* Dates */}
                <div className="cy-formgrid">
                    <FormField label="Anchor date" htmlFor="assign-anchor">
                        <DateInput id="assign-anchor" mode="date" label="Anchor date" value={form.anchor_date} onChange={v => set('anchor_date', v)} />
                    </FormField>
                    <FormField label="Effective from" htmlFor="assign-from" required>
                        <DateInput id="assign-from" mode="date" label="Effective from" value={form.effective_from} onChange={v => set('effective_from', v)} />
                    </FormField>
                    <FormField label="Effective to" htmlFor="assign-to">
                        <DateInput id="assign-to" mode="date" label="Effective to" value={form.effective_to} onChange={v => set('effective_to', v)} />
                    </FormField>
                </div>

                {/* Priority */}
                <FormField label="Priority" htmlFor="assign-priority">
                    <Input id="assign-priority" type="number" placeholder="Priority (0 = default)" value={form.priority} onChange={e => set('priority', e.target.value)} className="cy-input--short" />
                </FormField>

                {error && <Alert tone="danger">{error}</Alert>}

                {savedViolations.length > 0 && (
                    <Alert tone="warning" title="Assignment saved — working-time compliance warnings for review">
                        {savedViolations.flatMap((g) => g.violations.map((v, i) => (
                            <div key={`${g.userId}-${i}`}><b>{g.name}</b>: {v.date} — {v.message}</div>
                        )))}
                    </Alert>
                )}
            </div>
            <div className="cy-modal__foot">
                {savedViolations.length > 0 ? (
                    <Button size="sm" onClick={() => { setSavedViolations([]); onOpenChange(false); }}>Done</Button>
                ) : (
                    <>
                        <Button color="default" variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                        <Button size="sm" onClick={save} disabled={saving || !canSave}>
                            {saving ? 'Saving…' : isEdit ? 'Save changes'
                                : form.scope_type !== 'org' && form.scope_ids.length > 1
                                    ? `Assign to ${form.scope_ids.length} ${scopeLabel}`
                                    : 'Save'}
                        </Button>
                    </>
                )}
            </div>
        </Dialog>
    );
}
