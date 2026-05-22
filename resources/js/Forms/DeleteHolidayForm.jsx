import React, { useState } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog.jsx';

const DeleteHolidayForm = ({ open, closeModal, holidayIdToDelete, setHolidaysData }) => {
    const [deleting, setDeleting] = useState(false);

    const handleDelete = async () => {
        if (!holidayIdToDelete) {
            showToast.error('Invalid holiday ID provided');
            return;
        }
        setDeleting(true);
        try {
            const response = await axios.delete(route('holiday-delete'), {
                params: { id: holidayIdToDelete, route: route().current() },
            });
            if (response.status === 200) {
                setHolidaysData(response.data.holidays);
                showToast.success('Holiday deleted successfully');
                closeModal();
            }
        } catch (error) {
            if (error.response?.status === 404) showToast.error('Holiday not found or already deleted');
            else if (error.response?.status === 403) showToast.error('You do not have permission to delete this holiday');
            else if (error.response?.status === 422) showToast.error('Cannot delete holiday with current status');
            else showToast.error(error.response?.data?.error || 'Failed to delete holiday');
        } finally {
            setDeleting(false);
        }
    };

    return (
        <DeleteConfirmDialog
            open={open}
            onClose={closeModal}
            onConfirm={handleDelete}
            title="Delete holiday"
            description="Are you sure you want to delete this holiday? This action cannot be undone."
            confirmLabel={deleting ? 'Deleting…' : 'Delete holiday'}
            loading={deleting}
        />
    );
};

export default DeleteHolidayForm;
