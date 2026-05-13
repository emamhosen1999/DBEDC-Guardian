import React, { useState } from "react";
import { Dialog, Button, TextField, Text, Flex, Box, Checkbox } from '@radix-ui/themes';
import { CalendarIcon, ExclamationTriangleIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { router } from '@inertiajs/react';

const BulkCompletionDateModal = ({ isOpen, onClose, selectedWorks, onSuccess }) => {
    const [completionDate, setCompletionDate] = useState('');
    const [clearDate, setClearDate] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async () => {
        if (!clearDate && !completionDate) return;

        setIsSubmitting(true);
        try {
            const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || window.Laravel?.csrfToken;

            const response = await axios.post(router.route('dailyWorks.bulkUpdateCompletionDate'), {
                work_ids: selectedWorks.map(w => w.id),
                completion_date: clearDate ? null : completionDate
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
            console.error('Bulk completion date update error:', error);
            showToast.error('Failed to update completion date');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog.Root open={isOpen} onOpenChange={onClose}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>Update Completion Date</Dialog.Title>
                <Dialog.Description>
                    Update completion date for {selectedWorks.length} selected work(s)
                </Dialog.Description>

                <Box my="4">
                    <Flex gap="2" mb="3">
                        <Checkbox 
                            checked={clearDate}
                            onCheckedChange={(checked) => {
                                setClearDate(checked);
                                if (checked) setCompletionDate('');
                            }}
                        />
                        <Text size="2">Clear completion date</Text>
                    </Flex>
                    
                    {!clearDate && (
                        <Box>
                            <Text size="2" weight="bold" mb="2">Completion Date</Text>
                            <TextField.Root 
                                type="date" 
                                value={completionDate}
                                onChange={(e) => setCompletionDate(e.target.value)}
                            />
                        </Box>
                    )}
                </Box>

                <Flex gap="3" justify="end" mt="6">
                    <Dialog.Close>
                        <Button variant="soft" disabled={isSubmitting}>Cancel</Button>
                    </Dialog.Close>
                    <Button onClick={handleSubmit} disabled={(!clearDate && !completionDate) || isSubmitting}>
                        {isSubmitting ? 'Updating...' : 'Update'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkCompletionDateModal;
