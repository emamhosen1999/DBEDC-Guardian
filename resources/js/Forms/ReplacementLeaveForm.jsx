import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button, DateInput, FormField, Segmented, Textarea } from '@/Components/Cyber';
import AttachmentField from '@/Components/Attachments/AttachmentField';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

/**
 * Request the replacement day off earned by rest-day work. The days come first-expiring-first; a half-day credit can only be used
 * as a half day. A clear application image is required. Once approved, the date becomes the employee's weekly off.
 */
export default function ReplacementLeaveForm({ open, onOpenChange, onSaved }) {
    const [date, setDate] = useState('');
    const [days, setDays] = useState('1');
    const [reason, setReason] = useState('');
    const [files, setFiles] = useState([]);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => { if (open) { setDate(''); setDays('1'); setReason(''); setFiles([]); setErrors({}); } }, [open]);
    const balance = useQuery({ queryKey: ['my-comp-off'], queryFn: () => requestJson('get', '/attendance/comp-off/mine'), enabled: open });
    if (!open) return null;
    const credits = balance.data?.credits ?? [];
    const close = () => onOpenChange(false);

    const save = async (e) => {
        e.preventDefault();
        setSaving(true); setErrors({});
        try {
            await requestJson('post', '/attendance/replacement', { data: { date, days, reason: reason.trim() || undefined, attachment_tokens: files.map((f) => f.token) } });
            showToast.success('Replacement leave requested.');
            onSaved?.(); close();
        } catch (err) {
            setErrors(err?.errors && typeof err.errors === 'object' ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])) : {});
            showToast.error(err?.message || 'Failed to send the request.');
        } finally { setSaving(false); }
    };

    return (
        <Dialog title="Request replacement leave" onClose={close} className="cy-modal">
            <form onSubmit={save}>
                <div className="cy-modal__section">
                    {credits.length === 0
                        ? <Alert tone="warning">You have no replacement leave to use yet. It is earned by an approved claim for work on a rest day.</Alert>
                        : <Alert tone="info" title={`${balance.data.available_days} day(s) available`}>
                            {credits.map((c) => `${c.days} day from ${dayjs(c.work_date).format('DD MMM')} (expires ${dayjs(c.expires_at).format('DD MMM YYYY')})`).join('; ')}. The credit expiring first is used first.
                        </Alert>}
                    <FormField label="Replacement date (a day you are rostered to work)" htmlFor="rl-date" required error={errors.date}>
                        <DateInput id="rl-date" mode="date" value={date} onChange={setDate} min={dayjs().format('YYYY-MM-DD')} />
                    </FormField>
                    <FormField label="Length" error={errors.days}>
                        <Segmented label="Length" value={days} onChange={setDays} options={[{ value: '1', label: 'Full day' }, { value: '0.5', label: 'Half day' }]} />
                    </FormField>
                    <FormField label="Note (optional)" htmlFor="rl-reason"><Textarea id="rl-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} /></FormField>
                    <AttachmentField value={files} onChange={setFiles} required label="Application image or document" error={errors.attachments} />
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={close} disabled={saving}>Cancel</Button>
                    <Button type="submit" size="sm" disabled={!date || files.length === 0 || credits.length === 0} loading={saving}>Send request</Button>
                </div>
            </form>
        </Dialog>
    );
}
