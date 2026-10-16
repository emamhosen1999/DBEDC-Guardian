import React, { useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button, FormField, Textarea } from '@/Components/Cyber';

/** Asks for a mandatory reason (e.g. why a document must be uploaded again). Mounted only while open. */
export default function ReasonDialog({ title, label = 'Reason', confirmLabel = 'Send', onConfirm, onClose, busy = false, error }) {
    const [reason, setReason] = useState('');
    const trimmed = reason.trim();
    return (
        <Dialog title={title} onClose={onClose} className="cy-modal cy-modal--narrow">
            <form onSubmit={(e) => { e.preventDefault(); if (trimmed) onConfirm(trimmed); }}>
                <div className="cy-modal__section">
                    <FormField label={label} htmlFor="reason-text" required error={error}>
                        <Textarea id="reason-text" value={reason} onChange={(e) => setReason(e.target.value)} rows={4} required />
                    </FormField>
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button type="submit" size="sm" disabled={!trimmed} loading={busy}>{confirmLabel}</Button>
                </div>
            </form>
        </Dialog>
    );
}
