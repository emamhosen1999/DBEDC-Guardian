import React, { useState } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog.jsx';

const DeletePerformanceReviewForm = ({ open, onClose, performanceReview, fetchData, currentPage, perPage, filterData }) => {
    const [loading, setLoading] = useState(false);

    const handleDelete = async () => {
        if (!performanceReview || !performanceReview.id) return;

        setLoading(true);
        try {
            await axios.delete(route('hr.performance.reviews.destroy', performanceReview.id));
            showToast.success('Performance review deleted successfully');
            fetchData({ page: currentPage, perPage, ...filterData });
            onClose();
        } catch (error) {
            console.error('Error deleting performance review:', error);
            showToast.error('Failed to delete performance review');
        } finally {
            setLoading(false);
        }
    };

    if (!performanceReview) return null;

    const employeeName = performanceReview.employee?.name || 'this employee';
    const feedbackWarning = performanceReview.has_feedback
        ? ' This review has employee feedback and comments that will also be removed.'
        : '';

    return (
        <DeleteConfirmDialog
            open={open}
            onClose={onClose}
            onConfirm={handleDelete}
            title="Delete performance review"
            description={`Are you sure you want to delete the performance review for ${employeeName}? This action cannot be undone.${feedbackWarning}`}
            confirmLabel={loading ? 'Deleting…' : 'Delete'}
            loading={loading}
        />
    );
};

export default DeletePerformanceReviewForm;
