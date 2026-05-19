import React, { useState } from 'react';
import { Box, Button, Dialog, Flex, Spinner, Text, TextArea } from '@radix-ui/themes';
import { CheckIcon, Cross2Icon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

export default function ApprovalActions({ leave, onApprovalComplete }) {
    const [isApproveOpen, setIsApproveOpen] = useState(false);
    const [isRejectOpen, setIsRejectOpen] = useState(false);
    const onApproveOpen = () => setIsApproveOpen(true);
    const onApproveClose = () => setIsApproveOpen(false);
    const onRejectOpen = () => setIsRejectOpen(true);
    const onRejectClose = () => setIsRejectOpen(false);
    const [comments, setComments] = useState('');
    const [rejectionReason, setRejectionReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const handleApprove = async () => {
        setProcessing(true);
        setErrors({});

        try {
            const response = await axios.post(route('leaves.approve', leave.id), {
                comments: comments || null
            });

            if (response.data.success) {
                showToast.success(response.data.message || 'Leave approved successfully');
                onApproveClose();
                setComments('');
                if (onApprovalComplete) {
                    onApprovalComplete(response.data);
                }
            } else {
                showToast.error(response.data.message || 'Failed to approve leave');
            }
        } catch (error) {
            console.error('Approval error:', error);
            if (error.response?.data?.message) {
                showToast.error(error.response.data.message);
            } else {
                showToast.error('An error occurred while approving the leave');
            }
        } finally {
            setProcessing(false);
        }
    };

    const handleReject = async () => {
        setProcessing(true);
        setErrors({});

        if (!rejectionReason || rejectionReason.trim().length < 10) {
            setErrors({ reason: 'Rejection reason must be at least 10 characters' });
            setProcessing(false);
            return;
        }

        try {
            const response = await axios.post(route('leaves.reject', leave.id), {
                reason: rejectionReason
            });

            if (response.data.success) {
                showToast.success(response.data.message || 'Leave rejected successfully');
                onRejectClose();
                setRejectionReason('');
                if (onApprovalComplete) {
                    onApprovalComplete(response.data);
                }
            } else {
                showToast.error(response.data.message || 'Failed to reject leave');
            }
        } catch (error) {
            console.error('Rejection error:', error);
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors || {});
            } else if (error.response?.data?.message) {
                showToast.error(error.response.data.message);
            } else {
                showToast.error('An error occurred while rejecting the leave');
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            <Flex gap="2">
                <Button color="green" size="1" onClick={onApproveOpen} style={{ cursor: 'pointer' }}>
                    <CheckIcon /> Approve
                </Button>
                <Button color="red" size="1" onClick={onRejectOpen} style={{ cursor: 'pointer' }}>
                    <Cross2Icon /> Reject
                </Button>
            </Flex>

            <Dialog.Root open={isApproveOpen} onOpenChange={v => { if (!v) onApproveClose(); }}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Approve Leave Request</Dialog.Title>
                    <Text size="2" color="gray" mb="3" style={{ display: 'block' }}>Are you sure you want to approve this leave request?</Text>
                    <Box mb="3">
                        <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Comments (Optional)</Text>
                        <TextArea
                            placeholder="Add any comments for the approval..."
                            value={comments}
                            onChange={e => setComments(e.target.value)}
                            rows={3}
                        />
                    </Box>
                    <Flex justify="end" gap="2">
                        <Button variant="soft" color="gray" onClick={onApproveClose} disabled={processing} style={{ cursor: 'pointer' }}>Cancel</Button>
                        <Button color="green" onClick={handleApprove} disabled={processing} style={{ cursor: 'pointer' }}>
                            {processing ? <Spinner size="1" /> : <CheckIcon />} Approve
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            <Dialog.Root open={isRejectOpen} onOpenChange={v => { if (!v) onRejectClose(); }}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Reject Leave Request</Dialog.Title>
                    <Text size="2" color="gray" mb="3" style={{ display: 'block' }}>Please provide a reason for rejecting this leave request.</Text>
                    <Box mb="3">
                        <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Rejection Reason <Text color="red">*</Text></Text>
                        <TextArea
                            placeholder="Explain why this leave is being rejected (minimum 10 characters)..."
                            value={rejectionReason}
                            onChange={e => setRejectionReason(e.target.value)}
                            rows={3}
                            style={{ borderColor: errors.reason ? 'var(--red-7)' : undefined }}
                        />
                        {errors.reason && <Text size="1" color="red">{errors.reason}</Text>}
                    </Box>
                    <Flex justify="end" gap="2">
                        <Button variant="soft" color="gray" onClick={onRejectClose} disabled={processing} style={{ cursor: 'pointer' }}>Cancel</Button>
                        <Button color="red" onClick={handleReject} disabled={processing} style={{ cursor: 'pointer' }}>
                            {processing ? <Spinner size="1" /> : <Cross2Icon />} Reject
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </>
    );
}
