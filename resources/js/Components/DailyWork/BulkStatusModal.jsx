import React, { useState } from "react";
import { Dialog, Button, Select, Text, Flex, Box } from '@radix-ui/themes';
import { CheckIcon, ExclamationTriangleIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { router } from '@inertiajs/react';

const BulkStatusModal = ({ isOpen, onClose, selectedWorks, onSuccess }) => {
    const [selectedStatus, setSelectedStatus] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const statusOptions = [
        { value: 'pending', label: 'Pending' },
        { value: 'in_progress', label: 'In Progress' },
        { value: 'completed', label: 'Completed' },
        { value: 'on_hold', label: 'On Hold' },
        { value: 'cancelled', label: 'Cancelled' },
    ];

    const handleSubmit = async () => {
        if (!selectedStatus) return;

        setIsSubmitting(true);
        try {
            const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || window.Laravel?.csrfToken;

            const response = await axios.post(router.route('dailyWorks.bulkUpdateStatus'), {
                work_ids: selectedWorks.map(w => w.id),
                status: selectedStatus
            }, {
                headers: {
                    'Content-Type': 'application/json',
                    ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
                }
            });

            if (response.status === 200) {
                onSuccess(response.data);
                onClose();
            }
        } catch (error) {
            console.error('Bulk status update error:', error);
            showToast.error('Failed to update status');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog.Root open={isOpen} onOpenChange={onClose}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>Update Status</Dialog.Title>
                <Dialog.Description>
                    Update status for {selectedWorks.length} selected work(s)
                </Dialog.Description>

                <Box my="4">
                    <Text size="2" weight="bold" mb="2">Select Status</Text>
                    <Select.Root value={selectedStatus} onValueChange={setSelectedStatus}>
                        <Select.Trigger placeholder="Select status..." />
                        <Select.Content>
                            {statusOptions.map((option) => (
                                <Select.Item key={option.value} value={option.value}>
                                    {option.label}
                                </Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Box>

                <Flex gap="3" justify="end" mt="6">
                    <Dialog.Close>
                        <Button variant="soft" disabled={isSubmitting}>Cancel</Button>
                    </Dialog.Close>
                    <Button onClick={handleSubmit} disabled={!selectedStatus || isSubmitting}>
                        {isSubmitting ? 'Updating...' : 'Update'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkStatusModal;
