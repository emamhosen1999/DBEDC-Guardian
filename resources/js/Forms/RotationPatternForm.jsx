import React, { useState, useEffect } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button, FormField, Input, SelectInput } from '@/Components/Cyber';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import DepartmentField from '@/Components/Access/DepartmentFieldCyber';
import { usePage } from '@inertiajs/react';

const OFF = 'off';

export default function RotationPatternForm({ open, onOpenChange, onSaved, initial, departments = [] }) {
    const { auth } = usePage().props;
    const canMoveOwner = Boolean(auth?.scope?.attendance);
    const [departmentId, setDepartmentId] = useState(''); // '' = company-wide
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    const [cycleLength, setCycleLength] = useState(7);
    const [definition, setDefinition] = useState(Array(7).fill(OFF));
    const [saving, setSaving] = useState(false);

    const { data } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: open,
    });
    const allShifts = data?.shifts || [];
    // A department's pattern uses company-wide shifts and its own; a company-wide one only company-wide shifts.
    const shifts = allShifts.filter((s) => s.department_id == null || (departmentId !== '' && String(s.department_id) === String(departmentId)));

    useEffect(() => {
        if (open) {
            if (initial) {
                setDepartmentId(initial.department_id ? String(initial.department_id) : '');
                setName(initial.name || '');
                setCode(initial.code || '');
                setCycleLength(initial.cycle_length_days || 7);
                const initialDef = initial.definition || [];
                const def = initialDef.map(d => (d === null || d === undefined) ? OFF : String(d));
                const len = initial.cycle_length_days || 7;
                const paddedDef = def.slice(0, len);
                while (paddedDef.length < len) paddedDef.push(OFF);
                setDefinition(paddedDef);
            } else {
                setDepartmentId('');
                setName('');
                setCode('');
                setCycleLength(7);
                setDefinition(Array(7).fill(OFF));
            }
        }
    }, [open, initial]);

    const setCycle = (n) => {
        const len = Math.max(1, n);
        setCycleLength(len);
        setDefinition(prev => {
            const next = prev.slice(0, len);
            while (next.length < len) next.push(OFF);
            return next;
        });
    };

    const setDay = (idx, val) => {
        setDefinition(prev => prev.map((d, i) => i === idx ? val : d));
    };

    const save = async () => {
        setSaving(true);
        try {
            const method = initial ? 'put' : 'post';
            const url = initial ? `/attendance/rotation-patterns/${initial.id}` : '/attendance/rotation-patterns';
            await requestJson(method, url, {
                data: {
                    name,
                    code,
                    cycle_length_days: cycleLength,
                    definition: definition.map(d => (d === OFF ? null : Number(d))),
                    is_active: true,
                    department_id: departmentId === '' ? null : Number(departmentId),
                },
            });
            showToast.success(initial ? 'Pattern updated.' : 'Pattern created.');
            onSaved?.();
            onOpenChange(false);
        } catch (err) {
            showToast.error(err?.message || 'Failed to save pattern.');
        } finally {
            setSaving(false);
        }
    };

    if (!open) return null;

    return (
        <Dialog title={initial ? 'Edit Rotation Pattern' : 'New Rotation Pattern'} onClose={() => onOpenChange(false)} className="cy-modal cy-modal--wide">
            <div className="cy-modal__section cy-stack">
                <div className="cy-formgrid">
                    <FormField label="Name" htmlFor="pattern-name" required>
                        <Input id="pattern-name" placeholder="Name" value={name} onChange={e => setName(e.target.value)} />
                    </FormField>
                    <FormField label="Code" htmlFor="pattern-code" required>
                        <Input id="pattern-code" placeholder="Code" value={code} onChange={e => setCode(e.target.value)} />
                    </FormField>
                </div>
                <DepartmentField
                    label="Department"
                    attendance
                    value={departmentId}
                    onChange={setDepartmentId}
                    departments={departments}
                    noneLabel="Company-wide (every department)"
                    placeholder="Company-wide"
                    disabled={Boolean(initial) && !canMoveOwner}
                    helper="A department's pattern can use company-wide shifts and its own."
                />
                <FormField label="Cycle length (days)" htmlFor="pattern-cycle">
                    <Input id="pattern-cycle" type="number" min="1" max="60" value={cycleLength} onChange={e => setCycle(+e.target.value)} />
                </FormField>

                <fieldset className="cy-fieldset">
                    <legend className="cy-label">Day sequence</legend>
                    <div className="cy-sequence">
                        {definition.map((day, idx) => (
                            <div key={idx} className="cy-sequence__row">
                                <label className="cy-sequence__day" htmlFor={`pattern-day-${idx}`}>Day {idx + 1}</label>
                                <SelectInput
                                    id={`pattern-day-${idx}`}
                                    value={String(day)}
                                    onChange={v => setDay(idx, v)}
                                    options={[{ value: OFF, label: 'Off' }, ...shifts.map(s => ({ value: String(s.id), label: `${s.name} (${s.code})` }))]}
                                />
                            </div>
                        ))}
                    </div>
                </fieldset>
            </div>
            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                <Button size="sm" onClick={save} disabled={saving || !name || !code}>{saving ? 'Saving…' : 'Save'}</Button>
            </div>
        </Dialog>
    );
}
