import React, { useState } from 'react';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Button } from '@/Components/Cyber';
import AttachmentField from './AttachmentField';
import { submitReupload } from './attachmentsApi';
import { showToast } from '@/utils/toastUtils';

/** The employee uploads a clearer document for an application an approver returned. It goes back to the SAME approval step. */
export default function ReuploadDialog({ type, id, reason, onClose, onDone }) {
    const [files, setFiles] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const send = async () => {
        setBusy(true); setError('');
        try {
            await submitReupload(type, id, files.map((f) => f.token));
            showToast.success('Thank you. The new document was sent to your approver.');
            onDone?.(); onClose();
        } catch (e) {
            setError(e.message || 'The upload failed.');
        } finally { setBusy(false); }
    };

    return (
        <Dialog title="Upload a clearer document" onClose={onClose} className="cy-modal">
            <div className="cy-modal__section">
                {reason && <Alert tone="warning" title="Why it was returned">{reason}</Alert>}
                <AttachmentField value={files} onChange={setFiles} required label="New document" error={error} />
            </div>
            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                <Button size="sm" disabled={files.length === 0} loading={busy} onClick={send}>Send to approver</Button>
            </div>
        </Dialog>
    );
}
