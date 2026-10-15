import React, { useEffect, useId, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { Alert, Badge, Button, DateInput, FormField, Input, SelectInput, Textarea } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { showToast } from '@/utils/toastUtils';

import { SIDES, SIDE_LABEL, TYPES, errorsOf, formatDay, postJson, todayDhaka } from './dwUtils.js';

const EMPTY = { date: '', number: '', planned_time: '', type: 'Pavement', description: '', location: '', side: 'TR-L', qty_layer: '', assigned: '', inspection_details: '' };

function useDebounced(value, ms = 400) {
    const [v, setV] = useState(value);
    useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t); }, [value, ms]);
    return v;
}

/**
 * Create or edit one work. The chainage is checked against the corridor as you type: the readable span, the jurisdiction and
 * in-charge on the work's date, and whether the RFI number is taken. Status moves are made from the detail, not here.
 */
export default function WorkFormDialog({ work, people, can, onClose, onSaved, onConflict }) {
    const editing = !!work;
    const labelled = useId();
    const [v, setV] = useState(() => (editing ? {
        date: work.date ?? '', number: work.number ?? '', planned_time: work.planned_time ?? '', type: work.type ?? 'Pavement', description: work.description ?? '', location: work.location ?? '',
        side: work.side ?? 'TR-L', qty_layer: work.qty_layer ?? '', assigned: work.assigned ?? '', inspection_details: work.inspection_details ?? '',
    } : { ...EMPTY, date: todayDhaka() }));
    const [errors, setErrors] = useState({});
    const [general, setGeneral] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k, val) => { setV((p) => ({ ...p, [k]: val })); setErrors((p) => ({ ...p, [k]: undefined })); };

    const probe = useDebounced({ location: v.location, date: v.date, number: v.number });
    const check = useQuery({
        queryKey: ['daily-works', 'chainage-check', probe, work?.id],
        queryFn: () => postJson(route('dailyWorks.chainageCheck'), { ...probe, id: work?.id }),
        enabled: !!(probe.location || probe.number),
        staleTime: 30_000,
    });
    const c = check.data;
    const duplicate = c?.duplicate;
    const people2 = useMemo(() => [{ value: '', label: 'Not assigned' }, ...people.map((p) => ({ value: p.employee_id, label: `${p.name}${p.designation ? `, ${p.designation}` : ''} (${p.employee_id})` }))], [people]);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true); setGeneral(null);
        const body = editing
            ? { id: work.id, lock_version: work.lock_version, status: work.status, inspection_result: work.inspection_result ?? undefined, completion_time: work.completion_time ?? undefined, ...v }
            : { status: 'new', ...v };
        if (!can.assign) delete body.assigned;
        if (!v.assigned) delete body.assigned;
        try {
            await postJson(editing ? route('dailyWorks.update') : route('dailyWorks.add'), body);
            showToast.success(editing ? `RFI ${v.number} saved` : `RFI ${v.number} added`);
            onSaved();
        } catch (err) {
            const r = errorsOf(err);
            if (r.status === 409) { onConflict(r.message); return; }
            setErrors(r.fields);
            if (!Object.keys(r.fields).length) setGeneral(r.message);
        } finally { setBusy(false); }
    };

    return (
        <Dialog title={editing ? `Edit RFI ${work.number}` : 'Add a daily work'} onClose={onClose} className="cy-modal cy-modal--wide dw-form" labelledBy={labelled}>
            <form onSubmit={submit} noValidate>
                <div className="cy-modal__body">
                    {general && <Alert tone="danger" onDismiss={() => setGeneral(null)}>{general}</Alert>}
                    <div className="dw-formgrid">
                        <FormField label="RFI number" htmlFor="dw-n" required error={errors.number}>
                            <Input id="dw-n" value={v.number} onChange={(e) => set('number', e.target.value)} invalid={!!errors.number} autoComplete="off" />
                        </FormField>
                        <FormField label="RFI date" htmlFor="dw-d" required error={errors.date}><DateInput id="dw-d" label="RFI date" value={v.date} onChange={(val) => set('date', val)} /></FormField>
                        <FormField label="RFI time" htmlFor="dw-t" required error={errors.planned_time}><Input id="dw-t" type="time" value={v.planned_time} onChange={(e) => set('planned_time', e.target.value)} invalid={!!errors.planned_time} /></FormField>
                        <FormField label="Type" htmlFor="dw-ty" required error={errors.type}><SelectInput id="dw-ty" value={v.type} onChange={(val) => set('type', val)} options={TYPES.map((t) => ({ value: t, label: t }))} /></FormField>
                    </div>
                    {duplicate && <Alert tone="warning" title="This RFI number is already used">RFI {duplicate.number} dated {formatDay(duplicate.date)} ({duplicate.status}{duplicate.deleted ? ', deleted' : ''}) has it. {duplicate.deleted ? 'Restore that work instead of adding another.' : 'A resubmission is made by importing the later sheet or editing that work.'}</Alert>}
                    <div className="dw-formgrid">
                        <FormField label="Location (chainage)" htmlFor="dw-l" required error={errors.location} hint="Write it like K5+900 or K05+900-K06+400.">
                            <Input id="dw-l" value={v.location} onChange={(e) => set('location', e.target.value)} invalid={!!errors.location || !!c?.error} autoComplete="off" />
                        </FormField>
                        <FormField label="Road side" htmlFor="dw-s" required error={errors.side}><SelectInput id="dw-s" value={v.side} onChange={(val) => set('side', val)} options={SIDES.map((s) => ({ value: s, label: `${s}, ${SIDE_LABEL[s]}` }))} /></FormField>
                        {v.type === 'Embankment' && <FormField label="Layer number" htmlFor="dw-q" required error={errors.qty_layer}><Input id="dw-q" value={v.qty_layer} onChange={(e) => set('qty_layer', e.target.value)} invalid={!!errors.qty_layer} /></FormField>}
                    </div>
                    <div className="dw-checkbox" aria-live="polite">
                        {c?.error && <Alert tone="warning">{c.error}</Alert>}
                        {c?.span && c.jurisdiction && (
                            <p className="dw-preview"><Badge soft color="secondary">{c.jurisdiction.code}</Badge> {c.span.text} lies in {c.jurisdiction.name} on {formatDay(v.date)}. {c.in_charge?.name ? <>In-charge then: <strong>{c.in_charge.name}</strong>.</> : null}</p>
                        )}
                    </div>
                    <FormField label="Description" htmlFor="dw-ds" required error={errors.description}><Textarea id="dw-ds" rows={3} value={v.description} onChange={(e) => set('description', e.target.value)} invalid={!!errors.description} /></FormField>
                    {can.assign && (
                        <FormField label="Assigned to" htmlFor="dw-as" error={errors.assigned} hint={c?.team?.length ? `${c.team.length} people are on the team of this section on that date.` : undefined}>
                            <SelectInput id="dw-as" value={v.assigned} onChange={(val) => set('assigned', val)} options={people2} />
                        </FormField>
                    )}
                    <FormField label="Inspection details" htmlFor="dw-in" error={errors.inspection_details} hint={`${v.inspection_details.length} of 1000 characters`}><Textarea id="dw-in" rows={3} maxLength={1000} value={v.inspection_details} onChange={(e) => set('inspection_details', e.target.value)} /></FormField>
                </div>
                <div className="cy-modal__foot">
                    <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={busy} disabled={!!duplicate && !editing && !duplicate.deleted ? true : undefined}>{editing ? 'Save changes' : 'Add work'}</Button>
                </div>
            </form>
        </Dialog>
    );
}
