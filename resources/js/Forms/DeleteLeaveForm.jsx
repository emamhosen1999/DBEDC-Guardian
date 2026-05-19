import React, { useState } from "react";
import { Box, Button, Dialog, Flex, Spinner, Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import { showToast } from "@/utils/toastUtils";
import axios from 'axios';

const DeleteLeaveForm = ({ open, closeModal, leaveId, setLeavesData, setLeaves, setTotalRows, setLastPage, setError, deleteLeaveOptimized, fetchLeavesStats }) => {
    const [deleting, setDeleting] = useState(false);

    const handleDelete = async () => {
        if (!leaveId) {
            showToast.error('Invalid leave ID provided');
            return;
        }

        setDeleting(true);
        
        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.delete(route('leave-delete', { id: leaveId, route: route().current() }));

                if (response.status === 200) {
                    // Optimistic update approach
                    if (deleteLeaveOptimized) {
                        deleteLeaveOptimized(leaveId);
                        if (setTotalRows) setTotalRows(prev => Math.max(0, prev - 1));
                        if (fetchLeavesStats) {
                            fetchLeavesStats();
                        }
                    } else {
                        // Fallback approach
                        if (setLeavesData) setLeavesData(response.data.leavesData);
                        if (setTotalRows) setTotalRows(response.data.leaves.total);
                        if (setLastPage) setLastPage(response.data.leaves.last_page);
                        if (setLeaves) setLeaves(response.data.leaves.data);
                        if (setError) setError(false);
                        if (fetchLeavesStats) fetchLeavesStats();
                    }

                    resolve('Leave application deleted successfully');
                }
            } catch (error) {
                console.error('Error deleting leave:', error);
                
                // Enhanced error handling
                if (error.response?.status === 404) {
                    const { leavesData } = error.response.data || {};
                    if (setLeavesData && leavesData) setLeavesData(leavesData);
                    if (setError) setError(error.response?.data?.message || 'Leave not found.');
                    reject('Leave not found or already deleted');
                } else if (error.response?.status === 403) {
                    reject('You do not have permission to delete this leave');
                } else if (error.response?.status === 422) {
                    reject('Cannot delete leave with current status');
                } else {
                    reject(error.response?.data?.error || 'Failed to delete leave application');
                }
            } finally {
                setDeleting(false);
                closeModal();
            }
        });

        showToast.promise(
            promise,
            {
                pending: 'Deleting leave application...',
                success: {
                    render({ data }) {
                        return data;
                    }
                },
                error: {
                    render({ data }) {
                        return data;
                    }
                }
            }
        );
    };
    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !deleting) closeModal(); }}>
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
                    <Button variant="soft" color="gray" onClick={closeModal} disabled={deleting} style={{ cursor: 'pointer' }}>Cancel</Button>
                    <Button color="red" onClick={handleDelete} disabled={deleting} style={{ cursor: 'pointer' }}>
                        {deleting ? <Spinner size="1" /> : <TrashIcon />} {deleting ? 'Deleting...' : 'Delete Leave'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}


export default DeleteLeaveForm;