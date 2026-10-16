import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Badge, Button } from '@/Components/Cyber';
import AttachmentViewer from './AttachmentViewer';
import ReasonDialog from './ReasonDialog';
import ReuploadDialog from './ReuploadDialog';
import { requestReupload } from './attachmentsApi';
import { showToast } from '@/utils/toastUtils';

/**
 * Documents of a leave application in the leave lists: View (image / PDF viewer), the "returned for re-upload" state with the reason,
 * the applicant's Re-upload button and the approver's "Request re-upload". `leave.attachment_state` comes from LeaveResource.
 */
export default function LeaveAttachmentActions({ leave, onChanged }) {
    const { auth } = usePage().props;
    const state = leave.attachment_state;
    const [viewing, setViewing] = useState(false);
    const [returning, setReturning] = useState(false);
    const [reuploading, setReuploading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    if (!state) return null;

    const isOwner = String(leave.user_id) === String(auth?.user?.id);
    const isPending = String(leave.status).toLowerCase() === 'pending';
    const canRequest = !isOwner && isPending && !state.returned_for_reupload && !!(auth?.isSuperAdmin || auth?.permissions?.includes('leaves.request_reupload'));
    const current = state.files.filter((f) => !f.superseded);

    const send = async (reason) => {
        setBusy(true); setError('');
        try {
            await requestReupload('leave', leave.id, reason);
            showToast.success('Returned to the employee for a clearer document.');
            setReturning(false); setViewing(false); onChanged?.();
        } catch (e) { setError(e.message || 'Could not return the application.'); } finally { setBusy(false); }
    };
    const request = <Button size="sm" color="default" variant="outline" onClick={() => { setError(''); setReturning(true); }}>Request re-upload</Button>;

    return (
        <>
            {current.length > 0 && <Button size="sm" color="default" variant="outline" onClick={() => setViewing(true)}>Documents ({current.length})</Button>}
            {state.returned_for_reupload && <Badge soft color="warning">Returned for re-upload</Badge>}
            {state.returned_for_reupload && isOwner && <Button size="sm" onClick={() => setReuploading(true)}>Re-upload</Button>}
            {canRequest && !viewing && request}
            {viewing && <AttachmentViewer title="Leave documents" attachments={state} onClose={() => setViewing(false)} actions={canRequest && request} />}
            {returning && <ReasonDialog title="Request re-upload" label="What is wrong with the document?" confirmLabel="Return to employee" busy={busy} error={error} onClose={() => setReturning(false)} onConfirm={send} />}
            {reuploading && <ReuploadDialog type="leave" id={leave.id} reason={state.reupload?.reason} onClose={() => setReuploading(false)} onDone={onChanged} />}
        </>
    );
}
