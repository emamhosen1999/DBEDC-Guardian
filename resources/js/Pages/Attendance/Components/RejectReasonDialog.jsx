import React, { useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button, FormField, Textarea } from '@/Components/Cyber';

/**
 * Asks for the reason of a rejection (a reason is required: the confirm button stays disabled until one is typed).
 * Replaces the browser's window.prompt. Mounted only while open, so its text always starts empty.
 */
export default function RejectReasonDialog({ title = 'Reject request', onConfirm, onClose, busy = false }) {
    const [reason, setReason] = useState('');
    const trimmed = reason.trim();
    const submit = (e) => {
        e.preventDefault();
        if (!trimmed) return;
        onConfirm(trimmed);
    };
    return (
        <Dialog title={title} onClose={onClose} className="cy-modal cy-modal--narrow">
            <form onSubmit={submit}>
                <div className="cy-modal__section">
                    <FormField label="Reason for rejection" htmlFor="reject-reason" required>
                        <Textarea id="reject-reason" value={reason} onChange={(e) => setReason(e.target.value)} rows={4} required />
                    </FormField>
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button type="submit" color="danger" size="sm" disabled={!trimmed} loading={busy}>Reject</Button>
                </div>
            </form>
        </Dialog>
    );
}
