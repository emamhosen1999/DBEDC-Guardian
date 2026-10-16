import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Badge, Button, DateInput, FormField, Input, Segmented, Textarea } from '@/Components/Cyber';
import AttachmentField from '@/Components/Attachments/AttachmentField';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const DAY_LABEL = { rest_day: 'Rest day', holiday: 'Holiday' };
const COMP = { payment: 'Overtime pay', replacement: 'Replacement leave' };
const fmtDays = (d) => (d === 1 ? '1 day' : `${d} day`);

/**
 * Overtime CLAIM (owner policy): only work on a rostered rest day or a holiday can be claimed, as 1 or 0.5 day, paid or taken
 * as replacement leave (rest days). A clear document is always required. The server decides every rule; this form asks it
 * (check endpoint) so the employee sees the credit and the choices before submitting.
 *
 * `initial` prefills a day from the "worked, not claimed" list: { date, declared_end_time }.
 */
export default function OvertimeRequestForm({ open, onOpenChange, onSaved, initial }) {
    const [date, setDate] = useState('');
    const [endTime, setEndTime] = useState('');
    const [compensation, setCompensation] = useState('');
    const [reason, setReason] = useState('');
    const [files, setFiles] = useState([]);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        if (open) {
            setDate(initial?.date ?? ''); setEndTime(initial?.declared_end_time ?? ''); setCompensation(''); setReason(''); setFiles([]); setErrors({});
        }
    }, [open, initial]);

    const check = useQuery({
        queryKey: ['overtime-check', date, endTime],
        enabled: open && !!date,
        queryFn: () => requestJson('get', '/attendance/overtime/check', { params: { date, declared_end_time: endTime || undefined } }),
        retry: false,
    });
    const info = check.data;
    const needsEnd = info?.punch_out_missing;
    const choices = info?.eligible && info?.credit > 0 ? info.compensations : [];
    useEffect(() => { if (choices.length && !choices.includes(compensation)) setCompensation(choices.length === 1 ? choices[0] : ''); }, [info]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!open) return null;
    const close = () => onOpenChange(false);
    const canSubmit = info?.eligible && info.credit > 0 && compensation && files.length > 0 && (!needsEnd || endTime);

    const save = async (e) => {
        e.preventDefault();
        setSaving(true); setErrors({});
        try {
            await requestJson('post', '/attendance/overtime', {
                data: { date, compensation, declared_end_time: needsEnd ? endTime : undefined, reason: reason.trim() || undefined, attachment_tokens: files.map((f) => f.token) },
            });
            showToast.success('Overtime claim submitted.');
            onSaved?.();
            close();
        } catch (err) {
            setErrors(err?.errors && typeof err.errors === 'object' ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])) : {});
            showToast.error(err?.message || 'Failed to submit the claim.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog title="Claim rest-day or holiday work" onClose={close} className="cy-modal">
            <form onSubmit={save}>
                <div className="cy-modal__section">
                    <FormField label="Date worked" htmlFor="ot-date" required error={errors.date}>
                        <DateInput id="ot-date" mode="date" value={date} onChange={setDate} max={new Date().toISOString().slice(0, 10)} />
                    </FormField>
                    {check.isFetching && <p className="cy-row__sub">Checking the day…</p>}
                    {info && !check.isFetching && (
                        info.eligible || info.code === 'declare_end'
                            ? <Alert tone="info" title={`${DAY_LABEL[info.day_type] ?? 'Day'}${info.holiday_on_rest_day ? ' (holiday on your rest day)' : ''}`}>
                                {info.credit > 0 ? `You worked ${Math.floor(info.worked_minutes / 60)}h ${info.worked_minutes % 60}m: ${fmtDays(info.credit)} can be claimed.` : info.message}
                            </Alert>
                            : <Alert tone="warning">{info.message}</Alert>
                    )}
                    {needsEnd && (
                        <>
                            <Alert tone="warning" title="No punch-out on this day">There is no punch-out. Enter the time you finished work: the claim is flagged for your approver as an end time declared by you.</Alert>
                            <FormField label="Time you finished work" htmlFor="ot-end" required error={errors.declared_end_time}>
                                <Input id="ot-end" type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
                            </FormField>
                        </>
                    )}
                    {choices.length > 0 && (
                        <FormField label="Compensation" error={errors.compensation}>
                            {choices.length > 1
                                ? <Segmented label="Compensation" value={compensation} onChange={setCompensation} options={choices.map((c) => ({ value: c, label: COMP[c] }))} />
                                : <Badge soft color="secondary">{COMP[choices[0]]} only</Badge>}
                        </FormField>
                    )}
                    <FormField label="Note (optional)" htmlFor="ot-reason">
                        <Textarea id="ot-reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={500} />
                    </FormField>
                    <AttachmentField value={files} onChange={setFiles} required label="Application image or document" error={errors.attachments?.[0] ?? errors.attachments} />
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={close} disabled={saving}>Cancel</Button>
                    <Button type="submit" size="sm" disabled={!canSubmit} loading={saving}>Submit claim</Button>
                </div>
            </form>
        </Dialog>
    );
}
