import React, { useState } from "react";
import { Dialog, Button, Text, Flex, Box, ScrollArea } from '@radix-ui/themes';
import { TrashIcon, ExclamationTriangleIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { router } from '@inertiajs/react';

const BulkDeleteModal = ({ isOpen, onClose, selectedWorks, onSuccess }) => {
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleDelete = async () => {
        setIsSubmitting(true);
        try {
            const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || window.Laravel?.csrfToken;

            const response = await axios.post(router.route('dailyWorks.bulkDelete'), {
                work_ids: selectedWorks.map(w => w.id)
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
            console.error('Bulk delete error:', error);
            showToast.error('Failed to delete works');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog.Root open={isOpen} onOpenChange={onClose}>
            <Dialog.Content style={{ maxWidth: 500 }}>
                <Dialog.Title>Confirm Bulk Delete</Dialog.Title>
                <Dialog.Description>
                    Are you sure you want to delete {selectedWorks.length} work(s)? This action cannot be undone.
                </Dialog.Description>

                <Box my="4">
                    <Flex align="center" gap="2" mb="3" style={{ color: 'var(--red-11)', background: 'var(--red-2)', padding: '12px', borderRadius: 'var(--radius-3)' }}>
                        <ExclamationTriangleIcon style={{ width: 20, height: 20 }} />
                        <Text size="2" weight="bold">This will permanently delete the following works:</Text>
                    </Flex>
                    
                    <ScrollArea style={{ height: 200 }}>
                        <Box style={{ background: 'var(--gray-a2)', padding: '8px', borderRadius: 'var(--radius-2)' }}>
                            {selectedWorks.map((work) => (
                                <Flex key={work.id} justify="between" py="1" style={{ borderBottom: '1px solid var(--gray-a4)', fontSize: 12 }}>
                                    <Text>{work.rfi_number}</Text>
                                    <Text style={{ color: 'var(--gray-11)' }}>{work.location || '—'}</Text>
                                </Flex>
                            ))}
                        </Box>
                    </ScrollArea>
                </Box>

                <Flex gap="3" justify="end" mt="6">
                    <Dialog.Close>
                        <Button variant="soft" disabled={isSubmitting}>Cancel</Button>
                    </Dialog.Close>
                    <Button color="red" onClick={handleDelete} disabled={isSubmitting}>
                        {isSubmitting ? 'Deleting...' : (
                            <>
                                <TrashIcon style={{ width: 14, height: 14, marginRight: 6 }} />
                                Delete {selectedWorks.length} Work(s)
                            </>
                        )}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default BulkDeleteModal;
