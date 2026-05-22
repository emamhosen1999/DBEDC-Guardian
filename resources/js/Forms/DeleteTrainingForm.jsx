import React, { useState } from 'react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import DeleteConfirmDialog from '@/Components/Common/DeleteConfirmDialog.jsx';

const DeleteTrainingForm = ({
    open,
    closeModal,
    currentTraining,
    setTrainings,
    setTotalRows,
    setLastPage,
    fetchTrainingStats,
}) => {
    const [deleting, setDeleting] = useState(false);

    const handleDelete = async () => {
        if (!currentTraining) return;

        setDeleting(true);
        try {
            const response = await axios.delete(route('hr.training.destroy', currentTraining.id));

            if (response.status === 200) {
                if (setTrainings) {
                    setTrainings((prev) => prev.filter((training) => training.id !== currentTraining.id));
                }

                if (response.data.trainings) {
                    if (setTotalRows) setTotalRows(response.data.trainings.total);
                    if (setLastPage) setLastPage(response.data.trainings.last_page);
                    if (setTrainings) setTrainings(response.data.trainings.data);
                }

                if (fetchTrainingStats) fetchTrainingStats();

                showToast.success('Training program deleted successfully');
            }
        } catch (error) {
            console.error('Error deleting training:', error);

            if (error.response?.status === 404) {
                const { trainingsData } = error.response.data || {};
                if (setTrainings && trainingsData) setTrainings(trainingsData);
                showToast.error('Training not found or already deleted');
            } else if (error.response?.status === 403) {
                showToast.error('You do not have permission to delete this training');
            } else if (error.response?.status === 422) {
                showToast.error('Cannot delete training with current status or active enrollments');
            } else {
                showToast.error(error.response?.data?.error || 'Failed to delete training program');
            }
        } finally {
            setDeleting(false);
            closeModal();
        }
    };

    const trainingDetails = currentTraining
        ? `"${currentTraining.title}" (${currentTraining.category?.name || 'N/A'}, trainer: ${
              currentTraining.trainer
                  ? `${currentTraining.trainer.first_name} ${currentTraining.trainer.last_name}`
                  : 'N/A'
          }, status: ${currentTraining.status}${
              currentTraining.enrollments?.length > 0
                  ? `, ${currentTraining.enrollments.length} enrolled`
                  : ''
          }). Deleting will remove all enrollments, assessments, and materials permanently.`
        : 'Are you sure you want to delete this training program? This action cannot be undone.';

    return (
        <DeleteConfirmDialog
            open={open}
            onClose={() => { if (!deleting) closeModal(); }}
            onConfirm={handleDelete}
            title="Delete training program"
            description={trainingDetails}
            confirmLabel={deleting ? 'Deleting…' : 'Delete training'}
            loading={deleting}
        />
    );
};

export default DeleteTrainingForm;
