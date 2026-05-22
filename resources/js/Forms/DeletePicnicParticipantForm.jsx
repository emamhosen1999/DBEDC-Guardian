import React, { useState } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog.jsx';

const DeletePicnicParticipantForm = ({ open, handleClose, leaveIdToDelete, setLeavesData }) => {
    const [deleting, setDeleting] = useState(false);

    const handleDelete = async () => {
        if (!leaveIdToDelete) {
            showToast.error('Invalid participant ID provided');
            return;
        }

        setDeleting(true);
        try {
            const response = await axios.delete(route('leave-delete', { id: leaveIdToDelete, route: route().current() }));

            if (response.status === 200) {
                setLeavesData(response.data.leavesData);
                showToast.success('Leave application deleted successfully');
            }
        } catch (error) {
            console.error('Error deleting task:', error);
            showToast.error(error.response?.data?.error || 'Failed to delete leave application');
        } finally {
            setDeleting(false);
            handleClose();
        }
    };

    return (
        <DeleteConfirmDialog
            open={open}
            onClose={handleClose}
            onConfirm={handleDelete}
            title="Confirm deletion"
            description="Are you sure you want to delete this leave? This action cannot be undone."
            confirmLabel={deleting ? 'Deleting…' : 'Delete'}
            loading={deleting}
        />
    );
};

export default DeletePicnicParticipantForm;
