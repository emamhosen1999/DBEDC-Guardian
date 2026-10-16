import React, { useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button, FormField, Segmented, Textarea } from '@/Components/Cyber';

const COMP = { payment: 'Overtime pay', replacement: 'Replacement leave' };

/**
 * Approving a day-based overtime claim: the approver CONFIRMS the compensation the employee chose (rest days: pay or replacement;
 * holidays: pay only) and may adjust the credit (full / half day) with a recorded reason, which is how a declared end time
 * (no punch-out) is accepted or corrected.
 */
export default function OvertimeDecisionDialog({ claim, busy, onConfirm, onClose }) {
    const allowed = claim.claim_type === 'holiday' ? ['payment'] : ['payment', 'replacement'];
    const [compensation, setCompensation] = useState(claim.compensation);
    const [credit, setCredit] = useState(String(claim.day_credit));
    const [reason, setReason] = useState('');
    const changed = Number(credit) !== Number(claim.day_credit);
    const ok = !changed || reason.trim().length > 0;

    return (
        <Dialog title="Approve overtime claim" onClose={onClose} className="cy-modal cy-modal--narrow">
            <form onSubmit={(e) => { e.preventDefault(); if (ok) onConfirm({ compensation, adjusted_credit: changed ? Number(credit) : undefined, adjust_reason: changed ? reason.trim() : undefined }); }}>
                <div className="cy-modal__section">
                    {claim.end_time_declared && <Alert tone="warning" title="No punch-out, end time declared by the employee">Accept the declared time, or change the credit below with a reason.</Alert>}
                    <FormField label="Compensation">
                        {allowed.length > 1
                            ? <Segmented label="Compensation" value={compensation} onChange={setCompensation} options={allowed.map((c) => ({ value: c, label: COMP[c] }))} />
                            : <span>{COMP[allowed[0]]} only (holiday work)</span>}
                    </FormField>
                    <FormField label="Credit">
                        <Segmented label="Credit" value={credit} onChange={setCredit} options={[{ value: '1', label: 'Full day' }, { value: '0.5', label: 'Half day' }]} />
                    </FormField>
                    {changed && (
                        <FormField label="Why is the credit changed?" htmlFor="ot-adjust" required>
                            <Textarea id="ot-adjust" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required />
                        </FormField>
                    )}
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button type="submit" color="success" size="sm" disabled={!ok} loading={busy}>Approve</Button>
                </div>
            </form>
        </Dialog>
    );
}
