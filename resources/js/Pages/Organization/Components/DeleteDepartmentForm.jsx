import React, { useState } from 'react';
import { Dialog, Button, Flex, Text, Callout, Spinner } from '@radix-ui/themes';
import { ExclamationTriangleIcon, TrashIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const DeleteDepartmentForm = ({ open, onClose, onSuccess, department }) => {
    const [loading, setLoading] = useState(false);

    const handleDelete = async () => {
        if (!department) return;
        setLoading(true);

        try {
            await axios.delete(`/departments/${department.id}`);
            showToast.success('Department deleted successfully');
            if (onSuccess) onSuccess();
            onClose();
        } catch (error) {
            if (error.response?.status === 400 || error.response?.status === 422) {
                showToast.error(error.response?.data?.error || 'Cannot delete department');
            } else {
                showToast.error('An error occurred while deleting the department');
            }
        } finally {
            setLoading(false);
        }
    };

    if (!department) return null;

    const hasEmployees = department.employee_count > 0;

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !loading) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>Delete Department</Dialog.Title>

                <Flex direction="column" gap="4" mb="5">
                    <Text size="2">
                        Are you sure you want to delete the department <strong>{department.name}</strong>? 
                        This action cannot be undone.
                    </Text>

                    {hasEmployees ? (
                        <Callout.Root color="red" role="alert">
                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                            <Callout.Text>
                                This department has <strong>{department.employee_count}</strong> employees assigned to it. 
                                You cannot delete a department with active employees. Please reassign them first.
                            </Callout.Text>
                        </Callout.Root>
                    ) : (
                        <Callout.Root color="amber" role="alert">
                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                            <Callout.Text>
                                All associated data will be permanently removed.
                            </Callout.Text>
                        </Callout.Root>
                    )}
                </Flex>

                <Flex justify="end" gap="3">
                    <Button variant="soft" color="gray" onClick={onClose} disabled={loading}>
                        Cancel
                    </Button>
                    <Button color="red" onClick={handleDelete} disabled={loading || hasEmployees}>
                        {loading ? <Spinner size="1" /> : <><TrashIcon /> Delete Department</>}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DeleteDepartmentForm;