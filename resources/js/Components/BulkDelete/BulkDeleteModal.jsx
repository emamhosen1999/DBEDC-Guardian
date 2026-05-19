import React, { useState, useCallback } from 'react';
import { Badge, Box, Button, Dialog, Flex, ScrollArea, Separator, Spinner, Text } from '@radix-ui/themes';
import { CheckCircledIcon, ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';

import { showToast } from '@/utils/toastUtils';
import axios from 'axios';

const BulkDeleteModal = ({ 
    open, 
    onClose, 
    onSuccess,
    selectedLeaves = [],
    allUsers = []
}) => {
    // State
    const [isDeleting, setIsDeleting] = useState(false);
    const [errors, setErrors] = useState({});

    // Get user name helper
    const getUserName = useCallback((userId) => {
        const user = allUsers.find(u => u.id === userId);
        return user?.name || `User ID: ${userId}`;
    }, [allUsers]);

    // Check if any leaves are approved (cannot be deleted)
    const approvedLeaves = selectedLeaves.filter(leave => 
        leave.status && leave.status.toLowerCase() === 'approved'
    );
    const canDelete = approvedLeaves.length === 0;

    // Submit bulk deletion
    const handleDelete = useCallback(async () => {
        if (!canDelete) {
            const toastPromise = Promise.reject('Cannot delete approved leaves');
            showToast.promise(toastPromise, {
                error: 'Cannot delete approved leave requests'
            });
            return;
        }

        if (selectedLeaves.length === 0) {
            const toastPromise = Promise.reject('No leaves selected');
            showToast.promise(toastPromise, {
                error: 'No leave requests selected for deletion'
            });
            return;
        }

        setIsDeleting(true);

        // Follow exact same promise pattern as other forms
        const promise = new Promise(async (resolve, reject) => {
            try {
                const leaveIds = selectedLeaves.map(leave => leave.id);
                
                const response = await axios.delete(route('leaves.bulk.delete'), {
                    data: {
                        leave_ids: leaveIds
                    }
                });

               

                if (response.status === 200 && response.data.success) {
                    // Pass the response data to parent component for optimized updates
                    onSuccess?.(response.data);
                    onClose();
                    resolve([response.data.message || 'Leave requests deleted successfully']);
                } else {
                    console.error('Unexpected response status:', response.status);
                    reject(`Unexpected response status: ${response.status}`);
                }
            } catch (error) {
                console.error('Full error object:', error);

                if (error.response) {
                    console.error('Error response status:', error.response.status);
                    console.error('Error response data:', error.response.data);
                    
                    if (error.response.status === 422) {
                        // Handle validation errors
                        setErrors(error.response.data.errors || {});
                        reject(error.response.data.error || 'Failed to delete leave requests');
                    } else if (error.response.status === 403) {
                        // Handle authorization errors
                        reject(error.response.data.error || 'You are not authorized to delete these leave requests');
                    } else if (error.response.status === 404) {
                        // Handle not found errors
                        reject(error.response.data.error || 'Some leave requests were not found');
                    } else {
                        // Handle other HTTP errors
                        reject(`HTTP Error ${error.response.status}: ${error.response.data.message || 'An unexpected error occurred. Please try again later.'}`);
                    }
                } else if (error.request) {
                    console.error('No response received:', error.request);
                    reject('No response received from the server. Please check your internet connection.');
                } else {
                    console.error('Request setup error:', error.message);
                    reject('An error occurred while setting up the request.');
                }
            } finally {
                setIsDeleting(false);
            }
        });

        // Use exact same toast promise structure as other forms
        showToast.promise(
            promise,
            {
                pending: 'Deleting leave requests...',
                success: {
                    render({ data }) {
                        return data.join(', ');
                    }
                },
                error: {
                    render({ data }) {
                        return data;
                    }
                }
            }
        );
    }, [selectedLeaves, canDelete, onSuccess, onClose]);

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !isDeleting) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 560 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <Box style={{ padding: 8, background: 'var(--red-a3)', borderRadius: 'var(--radius-2)', display: 'flex' }}>
                            <TrashIcon style={{ color: 'var(--red-9)' }} />
                        </Box>
                        <Box>
                            Delete Leave Requests
                            <Text size="2" color="gray" style={{ display: 'block', fontWeight: 'normal' }}>
                                Confirm deletion of {selectedLeaves.length} leave request{selectedLeaves.length !== 1 ? 's' : ''}
                            </Text>
                        </Box>
                    </Flex>
                </Dialog.Title>

                {!canDelete && (
                    <Box p="3" mb="3" style={{ background: 'var(--red-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a5)' }}>
                        <Flex align="center" gap="2" mb="1">
                            <ExclamationTriangleIcon style={{ color: 'var(--red-9)' }} />
                            <Text size="2" weight="medium" color="red">Cannot Delete Approved Leaves</Text>
                        </Flex>
                        <Text size="2" color="red">
                            {approvedLeaves.length} of the selected leave requests are already approved and cannot be deleted.
                        </Text>
                    </Box>
                )}

                <Box mb="3">
                    <Flex align="center" gap="2" mb="2">
                        <CheckCircledIcon style={{ color: 'var(--accent-9)' }} />
                        <Text size="2" weight="medium">Selected Leave Requests ({selectedLeaves.length})</Text>
                    </Flex>
                    <ScrollArea style={{ maxHeight: 280, border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-2)' }}>
                        {selectedLeaves.map((leave, index) => {
                            const isApproved = leave.status?.toLowerCase() === 'approved';
                            const statusLower = leave.status?.toLowerCase();
                            const statusColor = statusLower === 'approved' ? 'green' : statusLower === 'pending' ? 'amber' : (statusLower === 'declined' || statusLower === 'rejected') ? 'red' : 'gray';
                            return (
                                <Box key={leave.id} p="3" style={{ opacity: isApproved ? 0.6 : 1, background: isApproved ? 'var(--red-a2)' : undefined }}>
                                    <Flex align="center" gap="2" mb="1" wrap="wrap">
                                        <Text size="2" weight="medium">{getUserName(leave.user_id)}</Text>
                                        <Badge color={statusColor} variant="soft">{leave.status || 'Unknown'}</Badge>
                                        {isApproved && <Badge color="red" variant="outline">Cannot Delete</Badge>}
                                    </Flex>
                                    <Text size="1" color="gray" style={{ display: 'block' }}>{leave.leave_type} — {leave.from_date} to {leave.to_date}</Text>
                                    <Text size="1" color="gray">{leave.no_of_days} day{leave.no_of_days !== 1 ? 's' : ''} — {leave.reason}</Text>
                                    {index < selectedLeaves.length - 1 && <Separator size="4" mt="3" />}
                                </Box>
                            );
                        })}
                    </ScrollArea>
                </Box>

                {canDelete && (
                    <Box p="3" mb="3" style={{ background: 'var(--amber-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--amber-a5)' }}>
                        <Text size="2" color="amber">This action cannot be undone. The selected leave requests will be permanently deleted.</Text>
                    </Box>
                )}

                <Flex justify="end" gap="2">
                    <Button variant="soft" color="gray" onClick={onClose} disabled={isDeleting} style={{ cursor: 'pointer' }}>Cancel</Button>
                    <Button color="red" onClick={handleDelete} disabled={!canDelete || isDeleting || selectedLeaves.length === 0} style={{ cursor: 'pointer' }}>
                        {isDeleting ? <Spinner size="1" /> : <TrashIcon />}
                        {isDeleting ? 'Deleting...' : `Delete ${selectedLeaves.length} Request${selectedLeaves.length !== 1 ? 's' : ''}`}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkDeleteModal;
