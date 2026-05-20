import React, { useState } from 'react';
import { Dialog, Button, Flex, Text, Callout, Spinner } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const DeleteDesignationForm = ({ open, onClose, onSuccess, designation }) => {
    const [loading, setLoading] = useState(false);

    const handleDelete = async () => {
        if (!designation) return;
        setLoading(true);

        try {
            await axios.delete(`/designations/${designation.id}`);
            showToast.success('Designation deleted successfully');
            if (onSuccess) onSuccess(designation);
            onClose();
        } catch (error) {
            if (error.response?.status === 400 || error.response?.status === 422) {
                showToast.error(error.response?.data?.error || 'Cannot delete designation with assigned employees');
            } else {
                showToast.error('An error occurred while deleting the designation');
            }
        } finally {
            setLoading(false);
        }
    };

    if (!designation) return null;

    const hasEmployees = designation.employee_count > 0;

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !loading) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>Delete Designation</Dialog.Title>

                <Flex direction="column" gap="4" mb="5">
                    <Text size="2">
                        Are you sure you want to delete the designation <strong>{designation.title}</strong>? 
                        This action cannot be undone.
                    </Text>

                    {hasEmployees && (
                        <Callout.Root color="red" role="alert">
                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                            <Callout.Text>
                                This designation has <strong>{designation.employee_count}</strong> employees assigned to it. 
                                You cannot delete a designation with active employees. Please reassign them first.
                            </Callout.Text>
                        </Callout.Root>
                    )}
                </Flex>

                <Flex justify="end" gap="3">
                    <Button variant="soft" color="gray" onClick={onClose} disabled={loading}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={handleDelete} disabled={loading || hasEmployees}>
                        {loading ? <Spinner size="1" /> : <><TrashIcon /> Delete Designation</>}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DeleteDesignationForm;