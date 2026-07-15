/**
 * BulkStatusUpdateModal.jsx
 * Confirmation dialog for bulk leave status changes.
 * Shows a breakdown of selected leaves by current status, lets the admin
 * pick a target status, and fires the bulk-status-update endpoint.
 */
import React, { useState, useMemo } from 'react';
import {
    Badge, Button, Callout, Dialog, Flex,
    Separator, Spinner, Text,
} from '@radix-ui/themes';
import { Panel } from '@/Components/ui/Panel';
import {
    CheckCircledIcon, ClockIcon, CrossCircledIcon,
    ExclamationTriangleIcon, InfoCircledIcon,
    UpdateIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const STATUS_OPTIONS = [
    { value: 'approved',  label: 'Approve',  color: 'green', icon: CheckCircledIcon },
    { value: 'declined',  label: 'Reject',   color: 'red',   icon: CrossCircledIcon },
    { value: 'pending',   label: 'Pending',  color: 'amber', icon: ClockIcon },
];

const STATUS_CONFIG = {
    New:      { color: 'blue',  label: 'New' },
    Pending:  { color: 'amber', label: 'Pending' },
    Approved: { color: 'green', label: 'Approved' },
    Declined: { color: 'red',   label: 'Declined' },
};

export default function BulkStatusUpdateModal({
    open,
    onClose,
    selectedLeaves = [],
    targetStatus: initialTarget = null,
    onSuccess,
}) {
    const [targetStatus, setTargetStatus] = useState(initialTarget || 'approved');
    const [loading, setLoading] = useState(false);

    // Reset when modal opens with a new target
    React.useEffect(() => {
        if (open && initialTarget) setTargetStatus(initialTarget);
    }, [open, initialTarget]);

    // Group selected leaves by current status
    const statusBreakdown = useMemo(() => {
        const groups = {};
        selectedLeaves.forEach(l => {
            const s = l.status || 'New';
            groups[s] = (groups[s] || 0) + 1;
        });
        return groups;
    }, [selectedLeaves]);

    // Count how many will actually change vs skip
    const willChange = useMemo(() => {
        const normalized = targetStatus === 'declined' ? 'Declined'
            : targetStatus === 'approved' ? 'Approved'
            : targetStatus === 'pending' ? 'Pending'
            : 'New';
        return selectedLeaves.filter(l => l.status !== normalized).length;
    }, [selectedLeaves, targetStatus]);

    const willSkip = selectedLeaves.length - willChange;

    const handleConfirm = async () => {
        if (willChange === 0) {
            showToast.info('All selected leaves already have this status.');
            return;
        }

        setLoading(true);
        try {
            const { data } = await axios.post(route('leaves.bulk-status-update'), {
                leave_ids: selectedLeaves.map(l => l.id),
                status: targetStatus,
            });
            showToast.success(data.message || `${data.updated_count} leave(s) updated.`);
            onSuccess?.(targetStatus, data);
            onClose();
        } catch (err) {
            const msg = err.response?.data?.error || err.response?.data?.message || 'Bulk status update failed.';
            showToast.error(msg);
        } finally {
            setLoading(false);
        }
    };

    const activeOption = STATUS_OPTIONS.find(o => o.value === targetStatus);

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v) onClose(); }}>
            <Dialog.Content maxWidth="480px">
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <UpdateIcon style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                        Bulk Status Update
                    </Flex>
                </Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="4">
                    Update the status of {selectedLeaves.length} selected leave request{selectedLeaves.length !== 1 ? 's' : ''}.
                </Dialog.Description>

                {/* Status breakdown */}
                <Panel tinted mb="4" p="3">
                    <Text size="2" weight="medium" as="div" mb="2">Current Status Breakdown</Text>
                    <Flex gap="2" wrap="wrap">
                        {Object.entries(statusBreakdown).map(([status, count]) => {
                            const cfg = STATUS_CONFIG[status] || { color: 'gray', label: status };
                            return (
                                <Badge key={status} color={cfg.color} variant="soft" size="2">
                                    {cfg.label}: {count}
                                </Badge>
                            );
                        })}
                    </Flex>
                </Panel>

                <Separator size="4" mb="4" />

                {/* Target status selector */}
                <Text size="2" weight="medium" as="div" mb="2">Set Status To</Text>
                <Flex gap="2" mb="4">
                    {STATUS_OPTIONS.map(opt => {
                        const Icon = opt.icon;
                        const isActive = targetStatus === opt.value;
                        return (
                            <Button
                                key={opt.value}
                                size="2"
                                variant={isActive ? 'solid' : 'surface'}
                                color={opt.color}
                                onClick={() => setTargetStatus(opt.value)}
                                style={{ flex: 1 }}
                            >
                                <Icon style={{ width: 14, height: 14 }} />
                                {opt.label}
                            </Button>
                        );
                    })}
                </Flex>

                {/* Impact summary */}
                {willSkip > 0 && (
                    <Callout.Root color="amber" size="1" mb="4">
                        <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                        <Callout.Text>
                            {willSkip} leave{willSkip !== 1 ? 's' : ''} already {activeOption?.label?.toLowerCase() || targetStatus} — will be skipped.
                        </Callout.Text>
                    </Callout.Root>
                )}

                {willChange > 0 && (
                    <Callout.Root color={activeOption?.color || 'blue'} size="1" mb="4">
                        <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                        <Callout.Text>
                            <strong>{willChange}</strong> leave{willChange !== 1 ? 's' : ''} will be changed to <strong>{activeOption?.label || targetStatus}</strong>.
                        </Callout.Text>
                    </Callout.Root>
                )}

                {/* Actions */}
                <Flex justify="end" gap="2" mt="2">
                    <Button
                        variant="soft"
                        color="gray"
                        onClick={onClose}
                        disabled={loading}
                    >
                        Cancel
                    </Button>
                    <Button
                        color={activeOption?.color || 'blue'}
                        onClick={handleConfirm}
                        disabled={loading || willChange === 0}
                    >
                        {loading ? (
                            <><Spinner size="1" /> Updating…</>
                        ) : (
                            <>{React.createElement(activeOption?.icon || UpdateIcon, { style: { width: 14, height: 14 } })} {activeOption?.label || 'Update'} {willChange} Leave{willChange !== 1 ? 's' : ''}</>
                        )}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
