import React, { useState, useEffect, useMemo } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button, Checkbox, DateRange, FormField, Icon, Input, SelectInput } from '@/Components/Cyber';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const emptyGraceTier = () => ({ upto_minutes: 15, outcome: 'late' });

const emptyBreaks = () => ({ unpaid_meal_minutes: '', meal_threshold_minutes: '' });
const emptyOvertime = () => ({
    daily_threshold_minutes: '',
    daily_multiplier: '',
    double_time_threshold_minutes: '',
    double_time_multiplier: '',
    require_preauthorization: false,
});

export default function PolicyForm({ open, onOpenChange, onSaved, policy = null, employees = [], departments = [], designations = [] }) {
    const empty = {
        name: '',
        scope_type: 'org',
        scope_id: '',
        effective_from: '',
        effective_to: '',
        punch_strictness: 'warn',
        outside_window_minutes: 120,
    };
    const [form, setForm] = useState(empty);
    const [graceTiers, setGraceTiers] = useState([]);
    const [rounding, setRounding] = useState({ strategy: 'none', unit_minutes: 15, direction: 'nearest' });
    const [breaks, setBreaks] = useState(emptyBreaks());
    const [overtime, setOvertime] = useState(emptyOvertime());
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const isEdit = !!policy?.id;
    const scopeOptions = useMemo(() => {
        if (form.scope_type === 'department') {
            return departments.map(item => ({ value: String(item.id), label: item.name }));
        }
        if (form.scope_type === 'designation') {
            return designations.map(item => ({ value: String(item.id), label: item.title || item.name }));
        }
        if (form.scope_type === 'user') {
            return employees.map(item => ({ value: String(item.id), label: `${item.name} (${item.id})` }));
        }
        return [];
    }, [departments, designations, employees, form.scope_type]);

    useEffect(() => {
        if (open) {
            if (policy) {
                setForm({
                    name: policy.name || '',
                    scope_type: policy.scope_type || 'org',
                    scope_id: policy.scope_id != null ? String(policy.scope_id) : '',
                    effective_from: policy.effective_from || '',
                    effective_to: policy.effective_to || '',
                    punch_strictness: policy.punch_strictness || 'warn',
                    outside_window_minutes: policy.outside_window_minutes ?? 120,
                });
                setGraceTiers(policy.grace_tiers?.late || []);
                setRounding(policy.rounding || { strategy: 'none', unit_minutes: 15, direction: 'nearest' });
                const ro = policy.rule_overrides || {};
                setBreaks(ro.breaks
                    ? {
                        unpaid_meal_minutes: ro.breaks.unpaid_meal_minutes ?? '',
                        meal_threshold_minutes: ro.breaks.meal_threshold_minutes ?? '',
                    }
                    : emptyBreaks()
                );
                setOvertime(ro.overtime
                    ? {
                        daily_threshold_minutes: ro.overtime.daily_threshold_minutes ?? '',
                        daily_multiplier: ro.overtime.daily_multiplier ?? '',
                        double_time_threshold_minutes: ro.overtime.double_time_threshold_minutes ?? '',
                        double_time_multiplier: ro.overtime.double_time_multiplier ?? '',
                        require_preauthorization: ro.overtime.require_preauthorization ?? false,
                    }
                    : emptyOvertime()
                );
            } else {
                setForm(empty);
                setGraceTiers([]);
                setRounding({ strategy: 'none', unit_minutes: 15, direction: 'nearest' });
                setBreaks(emptyBreaks());
                setOvertime(emptyOvertime());
            }
            setError('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, policy]);

    const addGraceTier = () => setGraceTiers(t => [...t, emptyGraceTier()]);
    const removeGraceTier = (idx) => setGraceTiers(t => t.filter((_, i) => i !== idx));
    const updateGraceTier = (idx, key, val) => setGraceTiers(t => t.map((row, i) => i === idx ? { ...row, [key]: val } : row));

    const setBreak = (k, v) => setBreaks(b => ({ ...b, [k]: v }));
    const setOt = (k, v) => setOvertime(o => ({ ...o, [k]: v }));

    // Determine if breaks group has any values filled in
    const breaksHasData = breaks.unpaid_meal_minutes !== '' || breaks.meal_threshold_minutes !== '';
    // Determine if overtime group has any values filled in
    const overtimeHasData =
        overtime.daily_threshold_minutes !== '' ||
        overtime.daily_multiplier !== '' ||
        overtime.double_time_threshold_minutes !== '' ||
        overtime.double_time_multiplier !== '' ||
        overtime.require_preauthorization === true;

    const save = async () => {
        setError('');
        setSaving(true);
        try {
            // Assemble rule_overrides — omit empty groups so a policy stays neutral on that axis
            const breaksPayload = breaksHasData
                ? {
                    unpaid_meal_minutes: breaks.unpaid_meal_minutes !== '' ? Number(breaks.unpaid_meal_minutes) : undefined,
                    meal_threshold_minutes: breaks.meal_threshold_minutes !== '' ? Number(breaks.meal_threshold_minutes) : undefined,
                }
                : undefined;

            const overtimePayload = overtimeHasData
                ? {
                    daily_threshold_minutes: overtime.daily_threshold_minutes !== '' ? Number(overtime.daily_threshold_minutes) : undefined,
                    daily_multiplier: overtime.daily_multiplier !== '' ? Number(overtime.daily_multiplier) : undefined,
                    double_time_threshold_minutes: overtime.double_time_threshold_minutes !== '' ? Number(overtime.double_time_threshold_minutes) : undefined,
                    double_time_multiplier: overtime.double_time_multiplier !== '' ? Number(overtime.double_time_multiplier) : undefined,
                    require_preauthorization: overtime.require_preauthorization,
                }
                : undefined;

            const ruleOverrides = (breaksPayload || overtimePayload)
                ? {
                    ...(breaksPayload ? { breaks: breaksPayload } : {}),
                    ...(overtimePayload ? { overtime: overtimePayload } : {}),
                }
                : null;

            const payload = {
                name: form.name,
                scope_type: form.scope_type,
                scope_id: form.scope_type === 'org' ? null : String(form.scope_id || '').trim() || null,
                effective_from: form.effective_from,
                effective_to: form.effective_to || null,
                punch_strictness: form.punch_strictness,
                outside_window_minutes: Number(form.outside_window_minutes) || 0,
                grace_tiers: graceTiers.length > 0
                    ? { late: graceTiers.map(t => ({ upto_minutes: Number(t.upto_minutes) || 0, outcome: t.outcome })) }
                    : null,
                rounding: rounding.strategy !== 'none'
                    ? { strategy: rounding.strategy, unit_minutes: Number(rounding.unit_minutes) || 15, direction: rounding.direction }
                    : null,
                rule_overrides: ruleOverrides,
            };

            if (isEdit) {
                await requestJson('put', `/attendance/policies/${policy.id}`, { data: payload });
                showToast.success('Policy updated.');
            } else {
                await requestJson('post', '/attendance/policies', { data: payload });
                showToast.success('Policy created.');
            }
            onSaved?.();
            onOpenChange(false);
        } catch (e) {
            const msg = e?.message || 'Failed to save policy.';
            setError(msg);
            showToast.error(msg);
        } finally {
            setSaving(false);
        }
    };

    if (!open) return null;

    const close = () => { setError(''); onOpenChange(false); };
    /* A numeric field: label above, optional placeholder / step. */
    const num = (id, label, value, onChange, extra = {}) => (
        <FormField label={label} htmlFor={id}>
            <Input id={id} type="number" min="0" value={value} onChange={e => onChange(e.target.value)} {...extra} />
        </FormField>
    );

    return (
        <Dialog title={isEdit ? 'Edit Policy' : 'New Policy'} onClose={close} className="cy-modal cy-modal--wide">
            <div className="cy-modal__section cy-stack">
                <FormField label="Name" htmlFor="policy-name" required>
                    <Input id="policy-name" placeholder="Policy name" value={form.name} onChange={e => set('name', e.target.value)} />
                </FormField>

                <FormField label="Scope" htmlFor="policy-scope">
                    <SelectInput
                        id="policy-scope"
                        value={form.scope_type}
                        onChange={v => { set('scope_type', v); set('scope_id', ''); }}
                        options={[{ value: 'org', label: 'Whole organization' }, { value: 'department', label: 'Department' }, { value: 'designation', label: 'Designation' }, { value: 'user', label: 'Employee' }]}
                    />
                </FormField>

                {form.scope_type !== 'org' && (
                    <FormField label={form.scope_type === 'user' ? 'Employee' : 'Scope target'} htmlFor="policy-target">
                        <SelectInput
                            id="policy-target"
                            value={form.scope_id}
                            onChange={value => set('scope_id', value)}
                            options={[{ value: '', label: `Select ${form.scope_type}`, disabled: true }, ...scopeOptions]}
                        />
                    </FormField>
                )}

                <FormField label="Effective from * to Effective to">
                    <DateRange
                        startLabel="Effective from"
                        endLabel="Effective to"
                        value={{ start: form.effective_from, end: form.effective_to }}
                        onChange={({ start, end }) => { set('effective_from', start); set('effective_to', end); }}
                    />
                </FormField>

                <div className="cy-formgrid">
                    <FormField label="Punch strictness" htmlFor="policy-strict">
                        <SelectInput id="policy-strict" value={form.punch_strictness} onChange={v => set('punch_strictness', v)} options={[{ value: 'warn', label: 'Warn' }, { value: 'flag', label: 'Flag' }, { value: 'restrict', label: 'Restrict' }]} />
                    </FormField>
                    {num('policy-outside', 'Outside window (minutes)', form.outside_window_minutes, v => set('outside_window_minutes', v), { max: '1440' })}
                </div>
            </div>

            <fieldset className="cy-modal__section cy-stack cy-fieldset">
                <div className="cy-label cy-label--row">
                    <legend className="cy-legend">Grace tiers (late arrival)</legend>
                    <Button size="sm" color="default" variant="outline" onClick={addGraceTier}><Icon name="plus-lg" /> Add tier</Button>
                </div>
                {graceTiers.length === 0 && <span className="cy-muted">No grace tiers configured.</span>}
                {graceTiers.map((tier, idx) => (
                    <div key={idx} className="cy-tierrow">
                        <FormField label="Up to (min)" htmlFor={`policy-tier-${idx}`}>
                            <Input id={`policy-tier-${idx}`} type="number" min="0" value={tier.upto_minutes} onChange={e => updateGraceTier(idx, 'upto_minutes', e.target.value)} />
                        </FormField>
                        <FormField label="Outcome" htmlFor={`policy-tier-out-${idx}`}>
                            <SelectInput id={`policy-tier-out-${idx}`} value={tier.outcome} onChange={v => updateGraceTier(idx, 'outcome', v)} options={[{ value: 'present', label: 'Present' }, { value: 'late', label: 'Late' }, { value: 'half_day', label: 'Half day' }]} />
                        </FormField>
                        <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={() => removeGraceTier(idx)} aria-label={`Remove grace tier ${idx + 1}`}><Icon name="trash" /></button>
                    </div>
                ))}
            </fieldset>

            <fieldset className="cy-modal__section cy-stack cy-fieldset">
                <legend className="cy-legend">Rounding</legend>
                <div className="cy-formgrid">
                    <FormField label="Strategy" htmlFor="policy-round">
                        <SelectInput id="policy-round" value={rounding.strategy} onChange={v => setRounding(r => ({ ...r, strategy: v }))} options={[{ value: 'none', label: 'None' }, { value: 'nearest', label: 'Nearest' }, { value: 'quarter_hour', label: 'Quarter hour' }, { value: 'seven_minute', label: 'Seven minute' }]} />
                    </FormField>
                    {rounding.strategy !== 'none' && (
                        <>
                            <FormField label="Unit (minutes)" htmlFor="policy-unit">
                                <Input id="policy-unit" type="number" min="1" value={rounding.unit_minutes} onChange={e => setRounding(r => ({ ...r, unit_minutes: e.target.value }))} />
                            </FormField>
                            <FormField label="Direction" htmlFor="policy-dir">
                                <SelectInput id="policy-dir" value={rounding.direction} onChange={v => setRounding(r => ({ ...r, direction: v }))} options={[{ value: 'nearest', label: 'Nearest' }, { value: 'up', label: 'Up' }, { value: 'down', label: 'Down' }]} />
                            </FormField>
                        </>
                    )}
                </div>
            </fieldset>

            <fieldset className="cy-modal__section cy-stack cy-fieldset">
                <legend className="cy-legend">Breaks</legend>
                <div className="cy-formgrid">
                    {num('policy-meal', 'Unpaid meal (minutes)', breaks.unpaid_meal_minutes, v => setBreak('unpaid_meal_minutes', v), { placeholder: 'e.g. 30' })}
                    {num('policy-meal-th', 'Meal threshold (minutes)', breaks.meal_threshold_minutes, v => setBreak('meal_threshold_minutes', v), { placeholder: 'e.g. 360' })}
                </div>
                <span className="cy-formtext">Leave blank to inherit defaults.</span>
            </fieldset>

            <fieldset className="cy-modal__section cy-stack cy-fieldset">
                <legend className="cy-legend">Overtime</legend>
                <div className="cy-formgrid">
                    {num('policy-ot-daily', 'Daily threshold (minutes)', overtime.daily_threshold_minutes, v => setOt('daily_threshold_minutes', v), { placeholder: 'e.g. 480' })}
                    {num('policy-ot-mult', 'Daily multiplier', overtime.daily_multiplier, v => setOt('daily_multiplier', v), { step: '0.01', placeholder: 'e.g. 1.5' })}
                    {num('policy-ot-double', 'Double-time threshold (minutes)', overtime.double_time_threshold_minutes, v => setOt('double_time_threshold_minutes', v), { placeholder: 'e.g. 720' })}
                    {num('policy-ot-double-mult', 'Double-time multiplier', overtime.double_time_multiplier, v => setOt('double_time_multiplier', v), { step: '0.01', placeholder: 'e.g. 2.0' })}
                </div>
                <Checkbox checked={overtime.require_preauthorization} onCheckedChange={v => setOt('require_preauthorization', !!v)} label="Require pre-authorization for overtime" />
                <span className="cy-formtext">Leave blank to inherit defaults.</span>
            </fieldset>

            {error && <div className="cy-modal__section"><Alert tone="danger">{error}</Alert></div>}

            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={close} disabled={saving}>Cancel</Button>
                <Button size="sm" onClick={save} disabled={saving || !form.name || !form.effective_from || (form.scope_type !== 'org' && !form.scope_id)}>{saving ? 'Saving…' : 'Save'}</Button>
            </div>
        </Dialog>
    );
}
