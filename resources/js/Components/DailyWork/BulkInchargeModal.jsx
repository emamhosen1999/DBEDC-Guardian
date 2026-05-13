import React, { useState } from "react";
import { Dialog, Button, Select, Text, Flex, Box } from '@radix-ui/themes';
import { PersonIcon, ExclamationTriangleIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { router } from '@inertiajs/react';

const BulkInchargeModal = ({ isOpen, onClose, selectedWorks, incharges, onSuccess }) => {
    const [selectedIncharge, setSelectedIncharge] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async () => {
        if (!selectedIncharge) return;

        setIsSubmitting(true);
        try {
            const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || window.Laravel?.csrfToken;

            const response = await axios.post(router.route('dailyWorks.bulkUpdateIncharge'), {
                work_ids: selectedWorks.map(w => w.id),
                incharge_id: selectedIncharge
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
            console.error('Bulk incharge update error:', error);
            showToast.error('Failed to update incharge');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog.Root open={isOpen} onOpenChange={onClose}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>Assign Incharge</Dialog.Title>
                <Dialog.Description>
                    Assign incharge to {selectedWorks.length} selected work(s)
                </Dialog.Description>

                <Box my="4">
                    <Text size="2" weight="bold" mb="2">Select Incharge</Text>
                    <Select.Root value={selectedIncharge} onValueChange={setSelectedIncharge}>
                        <Select.Trigger placeholder="Select incharge..." />
                        <Select.Content>
                            {incharges.map((ic) => (
                                <Select.Item key={ic.id} value={String(ic.id)}>
                                    {ic.name} - {ic.designation}
                                </Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Box>

                <Flex gap="3" justify="end" mt="6">
                    <Dialog.Close>
                        <Button variant="soft" disabled={isSubmitting}>Cancel</Button>
                    </Dialog.Close>
                    <Button onClick={handleSubmit} disabled={!selectedIncharge || isSubmitting}>
                        {isSubmitting ? 'Updating...' : 'Assign'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkInchargeModal;
