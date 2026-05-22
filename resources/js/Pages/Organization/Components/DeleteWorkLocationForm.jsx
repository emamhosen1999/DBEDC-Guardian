import React, { useState } from 'react';
import { Dialog, Button, Flex, Text, Callout, Spinner } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import * as useWorkLocationsQuery from '@/api/queries/useWorkLocationsQuery';
import { showToast } from '@/utils/toastUtils';

const DeleteWorkLocationForm = ({ open, handleClose, handleDelete, currentRow }) => {
    const deleteWorkLocation = useWorkLocationsQuery.useDeleteWorkLocation();
    const isMutating = deleteWorkLocation.isPending;

    const handleConfirmDelete = async () => {
        if (!currentRow) return;

        try {
            await deleteWorkLocation.mutateAsync(currentRow.id);
            showToast.success('Location deleted successfully');
            handleDelete(); 
        } catch (error) {
            showToast.error(error.response?.data?.message || 'Failed to delete work location');
        }
    };

    if (!currentRow) return null;

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !isMutating) handleClose(); }}>
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
                    <Button variant="soft" color="gray" onClick={handleClose} disabled={isMutating}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={handleConfirmDelete} disabled={isMutating}>
                        {isMutating ? <Spinner size="1" /> : <><TrashIcon /> Delete Location</>}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DeleteWorkLocationForm;
