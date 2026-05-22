import React, { useState } from "react";
import { Box, Button, Dialog, Flex, Spinner, Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import { showToast } from "@/utils/toastUtils";
import { useForm } from '@inertiajs/react';

const DeleteLeaveForm = ({ open, closeModal, leaveId, setLeavesData, setLeaves, setTotalRows, setLastPage, setError, deleteLeaveOptimized, fetchLeavesStats }) => {
    const { processing, delete: deleteRequest } = useForm();

    const handleDelete = () => {
        if (!leaveId) {
            showToast.error('Invalid leave ID provided');
            return;
        }

        const options = {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (response) => {
                // Optimistic update approach
                if (deleteLeaveOptimized) {
                    deleteLeaveOptimized(leaveId);
                    if (setTotalRows) setTotalRows(prev => Math.max(0, prev - 1));
                    if (fetchLeavesStats) {
                        fetchLeavesStats();
                    }
                } else {
                    // Fallback approach
                    if (setLeavesData) setLeavesData(response.props.leavesData);
                    if (setTotalRows) setTotalRows(response.props.leaves.total);
                    if (setLastPage) setLastPage(response.props.leaves.last_page);
                    if (setLeaves) setLeaves(response.props.leaves.data);
                    if (setError) setError(false);
                    if (fetchLeavesStats) fetchLeavesStats();
                }

                showToast.success('Leave application deleted successfully');
                closeModal();
            },
            onError: (error) => {
                console.error('Error deleting leave:', error);
                
                // Enhanced error handling
                if (error.status === 404) {
                    const { leavesData } = error.props || {};
                    if (setLeavesData && leavesData) setLeavesData(leavesData);
                    if (setError) setError(error.props?.message || 'Leave not found.');
                    showToast.error('Leave not found or already deleted');
                } else if (error.status === 403) {
                    showToast.error('You do not have permission to delete this leave');
                } else if (error.status === 422) {
                    showToast.error('Cannot delete leave with current status');
                } else {
                    showToast.error(error.props?.error || 'Failed to delete leave application');
                }
            }
        };

        deleteRequest(route('leave-delete', { id: leaveId, route: route().current() }), options);
    };
    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !processing) closeModal(); }}>
            <Dialog.Content style={{ maxWidth: 420 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <Box style={{ padding: 8, background: 'var(--red-a3)', borderRadius: 'var(--radius-2)', display: 'flex' }}>
                            <ExclamationTriangleIcon style={{ color: 'var(--red-9)' }} />
                        </Box>
                        Confirm Deletion
                    </Flex>
                </Dialog.Title>

                <Box p="3" mb="4" style={{ background: 'var(--red-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a5)' }}>
                    <Flex align="start" gap="2">
                        <ExclamationTriangleIcon style={{ color: 'var(--red-9)', flexShrink: 0, marginTop: 2 }} />
                        <Box>
                            <Text size="2" weight="medium" color="red" style={{ display: 'block', marginBottom: 4 }}>Are you sure you want to delete this leave application?</Text>
                            <Text size="2" color="gray">This action cannot be undone. The leave application will be permanently removed from the system.</Text>
                        </Box>
                    </Flex>
                </Box>

                <Flex justify="end" gap="2">
                    <Button variant="soft" color="gray" onClick={closeModal} disabled={processing} style={{ cursor: 'pointer' }}>Cancel</Button>
                    <Button color="red" onClick={handleDelete} disabled={processing} style={{ cursor: 'pointer' }}>
                        {processing ? <Spinner size="1" /> : <TrashIcon />} {processing ? 'Deleting...' : 'Delete Leave'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}


export default DeleteLeaveForm;