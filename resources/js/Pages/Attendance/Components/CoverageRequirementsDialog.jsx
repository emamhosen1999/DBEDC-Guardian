import React, { useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button, DateInput, FormField, Icon, Input, SelectInput } from '@/Components/Cyber';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function CoverageRequirementsDialog({ open, onOpenChange }) {
    const qc = useQueryClient();
    const { designations = [] } = usePage().props;

    const { data: reqData } = useQuery({ queryKey: ['coverage-requirements'], queryFn: () => requestJson('get', '/attendance/coverage-requirements'), enabled: open });
    const { data: locData } = useQuery({ queryKey: ['work-locations'], queryFn: () => requestJson('get', '/attendance/work-locations'), enabled: open });
    const { data: shiftData } = useQuery({ queryKey: ['shifts'], queryFn: () => requestJson('get', '/attendance/shifts'), enabled: open });

    const requirements = reqData?.requirements || [];
    const locations = locData?.work_locations || [];
    const shifts = shiftData?.shifts || [];

    const [form, setForm] = useState({ work_location_id: '', shift_id: '', designation_id: '', required_headcount: '1', weekday: '', date: '' });
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const invalidate = () => { qc.invalidateQueries({ queryKey: ['coverage-requirements'] }); qc.invalidateQueries({ queryKey: ['coverage'] }); };

    const create = useMutation({
        mutationFn: () => requestJson('post', '/attendance/coverage-requirements', { data: {
            work_location_id: Number(form.work_location_id),
            shift_id: Number(form.shift_id),
            designation_id: form.designation_id ? Number(form.designation_id) : null,
            required_headcount: Number(form.required_headcount),
            weekday: form.weekday === '' ? null : Number(form.weekday),
            date: form.date || null,
        } }),
        onSuccess: () => { showToast.success('Requirement added.'); invalidate(); },
        onError: (e) => showToast.error(e?.message || 'Failed to add requirement.'),
    });

    const remove = useMutation({
        mutationFn: (id) => requestJson('delete', `/attendance/coverage-requirements/${id}`),
        onSuccess: () => { showToast.success('Removed.'); invalidate(); },
        onError: (e) => showToast.error(e?.message || 'Failed to remove.'),
    });

    const canAdd = form.work_location_id && form.shift_id && form.required_headcount !== '';

    if (!open) return null;

    return (
        <Dialog title="Coverage requirements" onClose={() => onOpenChange?.(false)} className="cy-modal cy-modal--wide">
            <div className="cy-modal__section">
                <p className="cy-formtext">
                    Required headcount per post &amp; shift. Leave weekday/date empty for an all-days rule; set weekday for a recurring day, or date for a one-off override. Role optional.
                </p>
                <div className="cy-formgrid">
                    <FormField label="Location" htmlFor="cov-loc">
                        <SelectInput id="cov-loc" value={form.work_location_id} onChange={v => set('work_location_id', v)} options={[{ value: '', label: 'Location', disabled: true }, ...locations.map(l => ({ value: String(l.id), label: l.name }))]} />
                    </FormField>
                    <FormField label="Shift" htmlFor="cov-shift">
                        <SelectInput id="cov-shift" value={form.shift_id} onChange={v => set('shift_id', v)} options={[{ value: '', label: 'Shift', disabled: true }, ...shifts.map(s => ({ value: String(s.id), label: s.code }))]} />
                    </FormField>
                    <FormField label="Role (opt)" htmlFor="cov-role">
                        <SelectInput id="cov-role" value={form.designation_id || 'any'} onChange={v => set('designation_id', v === 'any' ? '' : v)} options={[{ value: 'any', label: 'Any (total)' }, ...designations.map(d => ({ value: String(d.id), label: d.title }))]} />
                    </FormField>
                    <FormField label="Count" htmlFor="cov-count">
                        <Input id="cov-count" type="number" min="0" value={form.required_headcount} onChange={e => set('required_headcount', e.target.value)} />
                    </FormField>
                    <FormField label="Weekday (opt)" htmlFor="cov-weekday">
                        <SelectInput id="cov-weekday" value={form.weekday === '' ? 'all' : form.weekday} onChange={v => set('weekday', v === 'all' ? '' : v)} options={[{ value: 'all', label: 'All days' }, ...WEEKDAYS.map((w, i) => ({ value: String(i), label: w }))]} />
                    </FormField>
                    <FormField label="Date (opt)" htmlFor="cov-date">
                        <DateInput id="cov-date" mode="date" label="Date (opt)" value={form.date} onChange={v => set('date', v)} />
                    </FormField>
                </div>
                <div className="cy-formactions"><Button size="sm" disabled={!canAdd || create.isPending} onClick={() => create.mutate()}>Add</Button></div>
            </div>

            <div className="cy-dt cy-dt--modal" tabIndex={0} role="region" aria-label="Coverage requirements, scrollable">
                <table className="cy-table">
                    <thead>
                        <tr><th scope="col">Location</th><th scope="col">Shift</th><th scope="col">Role</th><th scope="col">Count</th><th scope="col">When</th><th scope="col"><span className="visually-hidden">Actions</span></th></tr>
                    </thead>
                    <tbody>
                        {requirements.length === 0 && <tr><td colSpan={6} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><p className="cy-empty__text">No coverage requirements yet</p></div></td></tr>}
                        {requirements.map(r => (
                            <tr key={r.id}>
                                <td>{r.work_location?.name || `#${r.work_location_id}`}</td>
                                <td>{r.shift?.code || `#${r.shift_id}`}</td>
                                <td>{r.designation?.title || 'Any'}</td>
                                <td>{r.required_headcount}</td>
                                <td>{r.date ? r.date : (r.weekday === null || r.weekday === undefined ? 'All days' : WEEKDAYS[r.weekday])}</td>
                                <td><button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={() => remove.mutate(r.id)} aria-label={`Remove requirement ${r.work_location?.name || r.work_location_id} ${r.shift?.code || r.shift_id}`}><Icon name="trash" /></button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="cy-modal__foot"><Button color="default" variant="outline" size="sm" onClick={() => onOpenChange?.(false)}>Close</Button></div>
        </Dialog>
    );
}
