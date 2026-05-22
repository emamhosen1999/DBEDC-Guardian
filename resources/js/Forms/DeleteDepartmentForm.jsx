import React, { useState } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog.jsx';

const DeleteDepartmentForm = ({ open, onClose, onSuccess, department }) => {
    const [loading, setLoading] = useState(false);

    const handleDelete = async () => {
        if (!department) {
            showToast.error('Invalid department provided');
            return;
        }
        if (department.employee_count > 0) {
            showToast.error('Cannot delete department with active employees. Please reassign them first.');
            return;
        }

        setLoading(true);
        try {
            const response = await axios.delete(`/departments/${department.id}`);
            if (response.status === 200) {
                if (onSuccess) onSuccess(department, 'delete');
                showToast.success(response.data.message || 'Department deleted successfully');
                onClose();
            }
        } catch (error) {
            console.error('Error deleting department:', error);
            if (error.response?.status === 422) {
                showToast.error(error.response?.data?.message || 'Cannot delete department with active employees');
            } else if (error.response?.status === 404) {
                showToast.error('Department not found or already deleted');
            } else if (error.response?.status === 403) {
                showToast.error('You do not have permission to delete this department');
            } else {
                showToast.error('An error occurred while deleting the department');
            }
        } finally {
            setLoading(false);
        }
    };

    if (!department) return null;

    const hasEmployees = department.employee_count > 0;
    const description = hasEmployees
        ? `Are you sure you want to delete "${department.name}"? This department has ${department.employee_count} employees assigned and cannot be deleted until they are reassigned.`
        : `Are you sure you want to delete "${department.name}"? This action cannot be undone. All associated data will be permanently removed.`;

    return (
        <DeleteConfirmDialog
            open={open}
            onClose={onClose}
            onConfirm={handleDelete}
            title="Delete department"
            description={description}
            confirmLabel={loading ? 'Deleting…' : 'Delete department'}
            loading={loading}
        />
    );
};

export default DeleteDepartmentForm;
