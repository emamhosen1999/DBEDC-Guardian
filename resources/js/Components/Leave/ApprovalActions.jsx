import React, { useState } from 'react';
import axios from 'axios';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Button, FormField, Icon, Textarea } from '@/Components/Cyber';
import { showToast } from '@/utils/toastUtils';

/** Approve / Reject for the approver of a pending leave, with Cyber dialogs (comments are optional, a rejection needs a reason). */
export default function ApprovalActions({ leave, onApprovalComplete }) {
    const [dialog, setDialog] = useState(null); // 'approve' | 'reject'
    const [comments, setComments] = useState('');
    const [rejectionReason, setRejectionReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState('');

    const close = () => { if (!processing) { setDialog(null); setError(''); } };

    const call = async (routeName, payload, okMessage, failMessage) => {
        setProcessing(true); setError('');
        try {
            const response = await axios.post(route(routeName, leave.id), payload);
            if (response.data.success) {
                showToast.success(response.data.message || okMessage);
                setDialog(null); setComments(''); setRejectionReason('');
                onApprovalComplete?.(response.data);
            } else {
                showToast.error(response.data.message || failMessage);
            }
        } catch (e) {
            if (e.response?.status === 422) setError(Object.values(e.response.data.errors || {}).flat()[0] || failMessage);
            else showToast.error(e.response?.data?.message || failMessage);
        } finally {
            setProcessing(false);
        }
    };

    const reject = () => {
        if (!rejectionReason || rejectionReason.trim().length < 10) { setError('Rejection reason must be at least 10 characters'); return; }
        call('leaves.reject', { reason: rejectionReason }, 'Leave rejected successfully', 'An error occurred while rejecting the leave');
    };

    return (
        <>
            <span className="cy-actions">
                <Button size="sm" color="success" onClick={() => setDialog('approve')}><Icon name="check-lg" /> Approve</Button>
                <Button size="sm" color="danger" variant="outline" onClick={() => setDialog('reject')}><Icon name="x-lg" /> Reject</Button>
            </span>

            {dialog === 'approve' && (
                <Dialog title="Approve leave request" onClose={close} className="cy-modal cy-modal--narrow">
                    <div className="cy-modal__section">
                        <p>Are you sure you want to approve this leave request?</p>
                        <FormField label="Comments (optional)" htmlFor="lv-approve-comments">
                            <Textarea id="lv-approve-comments" rows={3} value={comments} onChange={(e) => setComments(e.target.value)} placeholder="Add any comments for the approval" />
                        </FormField>
                    </div>
                    <div className="cy-modal__foot">
                        <Button color="default" variant="outline" size="sm" onClick={close} disabled={processing}>Cancel</Button>
                        <Button color="success" size="sm" loading={processing} onClick={() => call('leaves.approve', { comments: comments || null }, 'Leave approved successfully', 'An error occurred while approving the leave')}>Approve</Button>
                    </div>
                </Dialog>
            )}
            {dialog === 'reject' && (
                <Dialog title="Reject leave request" onClose={close} className="cy-modal cy-modal--narrow">
                    <div className="cy-modal__section">
                        <FormField label="Reason for rejection" htmlFor="lv-reject-reason" required error={error}>
                            <Textarea id="lv-reject-reason" rows={3} value={rejectionReason} onChange={(e) => setRejectionReason(e.target.value)} placeholder="Reason for rejection (at least 10 characters)" />
                        </FormField>
                    </div>
                    <div className="cy-modal__foot">
                        <Button color="default" variant="outline" size="sm" onClick={close} disabled={processing}>Cancel</Button>
                        <Button color="danger" size="sm" loading={processing} onClick={reject}>Reject</Button>
                    </div>
                </Dialog>
            )}
        </>
    );
}
