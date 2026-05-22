import React, { useState } from 'react';
import { Dialog, Button, Flex, Text, Callout, Spinner } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const DeleteWorkLocationForm = ({ open, handleClose, handleDelete, currentRow }) => {
    const [loading, setLoading] = useState(false);

    const handleConfirmDelete = async () => {
        if (!currentRow) return;
        setLoading(true);

        try {
            await axios.delete(`/work-locations/${currentRow.id}`);
            showToast.success('Location deleted successfully');
            handleDelete(); 
        } catch (error) {
            showToast.error(error.response?.data?.message || 'Failed to delete work location');
        } finally {
            setLoading(false);
        }
    };

    if (!currentRow) return null;

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !loading) handleClose(); }}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>Delete Work Location</Dialog.Title>

                <Flex direction="column" gap="4" mb="5">
                    <Text size="2">
                        Are you sure you want to delete <strong>{currentRow.location}</strong>? 
                        This action cannot be undone.
                    </Text>

                    <Callout.Root color="amber" role="alert">
                        <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                        <Callout.Text>
                            <strong>Note:</strong> Make sure to reassign any ongoing work or 
                            responsibilities before deleting this location.
                        </Callout.Text>
                    </Callout.Root>
                </Flex>

                <Flex justify="end" gap="3">
                    <Button variant="soft" color="gray" onClick={handleClose} disabled={loading}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={handleConfirmDelete} disabled={loading}>
                        {loading ? <Spinner size="1" /> : <><TrashIcon /> Delete Location</>}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DeleteWorkLocationForm;