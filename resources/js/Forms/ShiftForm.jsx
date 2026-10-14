import React, { useState, useEffect, useMemo } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button, DateInput, FormField, Input, SelectInput, Switch } from '@/Components/Cyber';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import DepartmentField from '@/Components/Access/DepartmentFieldCyber';
import { usePage } from '@inertiajs/react';

const DEFAULT_FORM = {
    name: '', code: '', type: 'fixed', start_time: '09:00', end_time: '17:30',
    crosses_midnight: false, grace_in_minutes: 15, grace_out_minutes: 0,
    full_day_minutes: 480, half_day_minutes: 240, min_present_minutes: 0,
    break_minutes: 0, color: '#3b82f6', is_active: true,
    department_id: '', // '' = company-wide
};

// DB returns TIME columns as "HH:mm:ss" but Laravel validates "HH:mm"
const normalizeTime = (t) => (typeof t === 'string' && t.length > 5 ? t.slice(0, 5) : t);

const todayStr = () => new Date().toISOString().slice(0, 10);

// Columns that change HOW a day is scored — mirrors ShiftController::TIME_BEHAVIOR_FIELDS.
// Editing any of these on an EXISTING shift is versioned server-side so past
// attendance is never silently re-scored against the new definition.
const TIME_BEHAVIOR_FIELDS = [
    'start_time', 'end_time', 'crosses_midnight', 'grace_in_minutes',
    'grace_out_minutes', 'full_day_minutes', 'half_day_minutes',
    'min_present_minutes', 'break_minutes',
];

// '' (company-wide) travels as null; a department as its numeric id.
const withOwner = (data) => ({ ...data, department_id: data.department_id === '' || data.department_id == null ? null : Number(data.department_id) });

export default function ShiftForm({ open, onOpenChange, onSaved, initial = null, departments = [] }) {
    const { auth } = usePage().props;
    // Moving a template between departments (or making it company-wide) is for attendance administrators;
    // a department admin keeps his templates in his department.
    const canMoveOwner = Boolean(auth?.scope?.attendance);
    const [form, setForm] = useState(DEFAULT_FORM);
    const [baseline, setBaseline] = useState(null); // snapshot of the loaded shift, for change detection
    const [effectiveFrom, setEffectiveFrom] = useState(todayStr());
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const isEdit = Boolean(initial?.id);

    useEffect(() => {
        if (open) {
            if (initial) {
                const normalized = { ...DEFAULT_FORM, ...initial };
                normalized.start_time = normalizeTime(normalized.start_time);
                normalized.end_time = normalizeTime(normalized.end_time);
                normalized.core_start_time = normalizeTime(normalized.core_start_time);
                normalized.core_end_time = normalizeTime(normalized.core_end_time);
                normalized.department_id = initial.department_id ? String(initial.department_id) : '';
                setForm(normalized);
                setBaseline(normalized);
            } else {
                setForm(DEFAULT_FORM);
                setBaseline(null);
            }
            setEffectiveFrom(todayStr());
            setConfirmOpen(false);
        }
    }, [open, initial]);

    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    // Only meaningful when editing an existing shift — create flow has no "before" state.
    const hasTimeBehaviorChange = useMemo(() => {
        if (!isEdit || !baseline) return false;
        return TIME_BEHAVIOR_FIELDS.some((field) => {
            const a = form[field];
            const b = baseline[field];
            if (field === 'crosses_midnight') return Boolean(a) !== Boolean(b);
            if (field === 'start_time' || field === 'end_time') return normalizeTime(a) !== normalizeTime(b);
            return Number(a ?? 0) !== Number(b ?? 0);
        });
    }, [form, baseline, isEdit]);

    const performSave = async () => {
        setSaving(true);
        try {
            if (isEdit) {
                const payload = withOwner(hasTimeBehaviorChange ? { ...form, effective_from: effectiveFrom } : form);
                const res = await requestJson('put', `/attendance/shifts/${initial.id}`, { data: payload });
                const versionNote = hasTimeBehaviorChange && res?.versions_count
                    ? ` New version ${res.versions_count} effective ${effectiveFrom}.`
                    : '';
                showToast.success(`Shift updated.${versionNote}`);
            } else {
                await requestJson('post', '/attendance/shifts', { data: withOwner(form) });
                showToast.success('Shift created.');
            }
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to save shift.');
        } finally {
            setSaving(false);
            setConfirmOpen(false);
        }
    };

    // Time-behavior edits on an existing shift require confirming the version
    // impact before submitting; everything else saves immediately.
    const handleSaveClick = () => {
        if (isEdit && hasTimeBehaviorChange) {
            setConfirmOpen(true);
        } else {
            performSave();
        }
    };

    if (!open) return null;

    return (
        <>
            <Dialog title={initial?.id ? 'Edit Shift' : 'New Shift'} onClose={() => onOpenChange(false)} className="cy-modal cy-modal--wide">
                <div className="cy-modal__section cy-stack">
                    <div className="cy-formgrid">
                        <FormField label="Name" htmlFor="shift-name" required>
                            <Input id="shift-name" placeholder="Name" value={form.name} onChange={e => set('name', e.target.value)} />
                        </FormField>
                        <FormField label="Code" htmlFor="shift-code" required>
                            <Input id="shift-code" placeholder="Code" value={form.code} onChange={e => set('code', e.target.value)} />
                        </FormField>
                    </div>

                    <DepartmentField
                        label="Department"
                        attendance
                        value={form.department_id ?? ''}
                        onChange={(v) => set('department_id', v)}
                        departments={departments}
                        noneLabel="Company-wide (every department)"
                        placeholder="Company-wide"
                        disabled={isEdit && !canMoveOwner}
                        helper="A department-owned shift is managed by that department's admin and visible only to its schedulers."
                    />

                    <FormField label="Type" htmlFor="shift-type">
                        <SelectInput id="shift-type" value={form.type} onChange={v => set('type', v)} options={[{ value: 'fixed', label: 'Fixed' }, { value: 'flexible', label: 'Flexible' }, { value: 'open', label: 'Open' }]} />
                    </FormField>

                    <div className="cy-formgrid">
                        <FormField label="Start" htmlFor="shift-start">
                            <DateInput id="shift-start" mode="time" label="Start" value={form.start_time} onChange={v => set('start_time', v)} />
                        </FormField>
                        <FormField label="End" htmlFor="shift-end">
                            <DateInput id="shift-end" mode="time" label="End" value={form.end_time} onChange={v => set('end_time', v)} />
                        </FormField>
                    </div>

                    <Switch checked={Boolean(form.crosses_midnight)} onCheckedChange={v => set('crosses_midnight', v)} label="Crosses midnight (night shift)" />

                    <div className="cy-formgrid">
                        <FormField label="Grace in (min)" htmlFor="shift-grace-in">
                            <Input id="shift-grace-in" type="number" min="0" value={form.grace_in_minutes} onChange={e => set('grace_in_minutes', +e.target.value)} />
                        </FormField>
                        <FormField label="Grace out (min)" htmlFor="shift-grace-out">
                            <Input id="shift-grace-out" type="number" min="0" value={form.grace_out_minutes} onChange={e => set('grace_out_minutes', +e.target.value)} />
                        </FormField>
                    </div>

                    <div className="cy-formgrid">
                        <FormField label="Full day min" htmlFor="shift-full">
                            <Input id="shift-full" type="number" min="0" value={form.full_day_minutes} onChange={e => set('full_day_minutes', +e.target.value)} />
                        </FormField>
                        <FormField label="Half day min" htmlFor="shift-half">
                            <Input id="shift-half" type="number" min="0" value={form.half_day_minutes} onChange={e => set('half_day_minutes', +e.target.value)} />
                        </FormField>
                        <FormField label="Min present" htmlFor="shift-minp">
                            <Input id="shift-minp" type="number" min="0" value={form.min_present_minutes} onChange={e => set('min_present_minutes', +e.target.value)} />
                        </FormField>
                    </div>

                    <FormField label="Color" htmlFor="shift-color">
                        <Input id="shift-color" type="color" className="cy-input--color" value={form.color} onChange={e => set('color', e.target.value)} />
                    </FormField>

                    <Switch checked={Boolean(form.is_active)} onCheckedChange={v => set('is_active', v)} label={form.is_active ? 'Active' : 'Inactive'} />

                    {isEdit && hasTimeBehaviorChange && (
                        <FormField label="Effective from" htmlFor="shift-effective" hint="Changes apply from this date; earlier days keep the old times.">
                            <DateInput id="shift-effective" mode="date" label="Effective from" value={effectiveFrom} onChange={setEffectiveFrom} />
                        </FormField>
                    )}
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                    <Button size="sm" onClick={handleSaveClick} disabled={saving || !form.name || !form.code}>{saving ? 'Saving…' : 'Save'}</Button>
                </div>
            </Dialog>

            {confirmOpen && (
                <Dialog title="Confirm shift time change" onClose={() => setConfirmOpen(false)} className="cy-modal cy-modal--narrow">
                    <div className="cy-modal__section">
                        <p className="cy-modal__text">This changes the shift's times from {effectiveFrom}. Past attendance keeps the previous times (version history is kept).</p>
                    </div>
                    <div className="cy-modal__foot">
                        <Button color="default" variant="outline" size="sm" onClick={() => setConfirmOpen(false)} disabled={saving}>Cancel</Button>
                        <Button size="sm" onClick={performSave} disabled={saving}>{saving ? 'Saving…' : 'Confirm & save'}</Button>
                    </div>
                </Dialog>
            )}
        </>
    );
}
