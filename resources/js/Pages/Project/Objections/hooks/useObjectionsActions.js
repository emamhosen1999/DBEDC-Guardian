import { useCallback } from 'react';
import { router } from '@inertiajs/react';
import * as useObjectionsQuery from '@/api/queries/useObjectionsQuery';
import { showToast } from '@/utils/toastUtils';

/**
 * Mutation handlers for the Objections index page (create, RFI attach, edit, status, delete).
 */
export function useObjectionsActions({
    createForm,
    selectedFiles,
    setCreateLoading,
    onCreateClose,
    resetCreateForm,
    setRfiSearchLoading,
    setSuggestedRfis,
    selectedObjection,
    rfiSearchQuery,
    selectedRfis,
    suggestedRfis,
    setAttachLoading,
    onAttachClose,
    setExportLoading,
    editForm,
    editObjection,
    setEditLoading,
    onEditClose,
    statusAction,
    resolutionNotes,
    setStatusLoading,
    onStatusClose,
    setResolutionNotes,
    setObjections,
    setApiStats,
}) {
    const createObjection = useObjectionsQuery.useCreateObjection();
    const suggestRfis = useObjectionsQuery.useSuggestRfis();
    const attachRfis = useObjectionsQuery.useAttachRfis();
    const updateObjection = useObjectionsQuery.useUpdateObjection();
    const submitObjection = useObjectionsQuery.useSubmitObjection();
    const reviewObjection = useObjectionsQuery.useReviewObjection();
    const resolveObjection = useObjectionsQuery.useResolveObjection();
    const rejectObjection = useObjectionsQuery.useRejectObjection();
    const deleteObjection = useObjectionsQuery.useDeleteObjection();

    const handleCreateObjection = useCallback(async () => {
        if (!createForm.title || !createForm.description || !createForm.reason) {
            showToast.error('Please fill in all required fields');
            return;
        }

        setCreateLoading(true);
        try {
            const formData = new FormData();
            formData.append('title', createForm.title);
            formData.append('category', createForm.category);
            if (createForm.type) {
                formData.append('type', createForm.type);
            }
            formData.append('description', createForm.description);
            formData.append('reason', createForm.reason);
            formData.append('status', createForm.status);

            if (createForm.specific_chainages) {
                formData.append('specific_chainages', createForm.specific_chainages);
            }
            if (createForm.chainage_range_from) {
                formData.append('chainage_range_from', createForm.chainage_range_from);
            }
            if (createForm.chainage_range_to) {
                formData.append('chainage_range_to', createForm.chainage_range_to);
            }
            if (!createForm.specific_chainages && !createForm.chainage_range_from && createForm.chainage_from) {
                formData.append('chainage_from', createForm.chainage_from);
            }
            if (!createForm.specific_chainages && !createForm.chainage_range_to && createForm.chainage_to) {
                formData.append('chainage_to', createForm.chainage_to);
            }

            selectedFiles.forEach((file) => {
                formData.append('files[]', file);
            });

            const response = await createObjection.mutateAsync(formData);
            showToast.success(response.message || 'Objection created successfully');
            onCreateClose();
            resetCreateForm();
            router.reload({ only: ['objections', 'statistics'] });
        } catch (error) {
            showToast.error(error?.data?.error || 'Failed to create objection');
        } finally {
            setCreateLoading(false);
        }
    }, [createForm, selectedFiles, createObjection, onCreateClose, resetCreateForm, setCreateLoading]);

    const handleSuggestRfis = useCallback(async (chainageFrom, chainageTo, searchQuery = '', objectionType = null) => {
        setRfiSearchLoading(true);
        try {
            const params = {};
            if (chainageFrom) params.chainage_from = chainageFrom;
            if (chainageTo) params.chainage_to = chainageTo;
            if (searchQuery) params.search = searchQuery;
            if (objectionType) params.type = objectionType;

            const response = await suggestRfis.mutateAsync(params);
            setSuggestedRfis(response.rfis || []);
        } catch (error) {
            console.error('Failed to suggest RFIs:', error);
            setSuggestedRfis([]);
        } finally {
            setRfiSearchLoading(false);
        }
    }, [suggestRfis, setRfiSearchLoading, setSuggestedRfis]);

    const handleRfiSearch = useCallback(() => {
        if (selectedObjection) {
            const summary = selectedObjection.chainage_summary || {};
            const specificChainages = summary.specific?.join(', ') || '';
            const rangeFrom = summary.range?.split(' - ')[0] || selectedObjection.chainage_from;
            const rangeTo = summary.range?.split(' - ')[1] || selectedObjection.chainage_to;
            const objType = selectedObjection.type;

            if (rfiSearchQuery) {
                handleSuggestRfis(null, null, rfiSearchQuery, objType);
            } else if (specificChainages) {
                handleSuggestRfis(specificChainages, null, '', objType);
            } else {
                handleSuggestRfis(rangeFrom, rangeTo, rfiSearchQuery, objType);
            }
        } else if (rfiSearchQuery) {
            handleSuggestRfis(null, null, rfiSearchQuery);
        }
    }, [selectedObjection, rfiSearchQuery, handleSuggestRfis]);

    const handleAttachRfis = useCallback(async () => {
        if (!selectedObjection || selectedRfis.length === 0) {
            showToast.error('Please select at least one RFI');
            return;
        }

        setAttachLoading(true);
        try {
            const response = await attachRfis.mutateAsync({
                objectionId: selectedObjection.id,
                rfiIds: selectedRfis.map(id => parseInt(id, 10)),
            });
            showToast.success(response.message || 'RFIs attached successfully');
            onAttachClose();
            router.reload({ only: ['objections'] });
        } catch (error) {
            showToast.error(error.response?.data?.error || 'Failed to attach RFIs');
        } finally {
            setAttachLoading(false);
        }
    }, [selectedObjection, selectedRfis, attachRfis, onAttachClose, setAttachLoading]);

    const handleExportSelectedRfis = useCallback(async () => {
        if (suggestedRfis.length === 0) {
            showToast.error('No RFIs to export');
            return;
        }

        setExportLoading(true);
        try {
            const rfisToExport = selectedRfis.length > 0
                ? suggestedRfis.filter(rfi => selectedRfis.includes(String(rfi.id)))
                : suggestedRfis;

            const exportData = rfisToExport.map(rfi => ({
                'RFI Number': rfi.number || '',
                'Date': rfi.date || 'N/A',
                'Chainage': rfi.location || 'N/A',
                'Side': rfi.side || 'N/A',
                'Layer/Qty': rfi.qty_layer || 'N/A',
                'Type': rfi.type || 'N/A',
                'Description': rfi.description || 'N/A',
                'Status': rfi.status || 'N/A',
                'Objection Title': selectedObjection?.title || 'N/A',
                'Objection Chainage': `${selectedObjection?.chainage_from || ''} - ${selectedObjection?.chainage_to || ''}`,
            }));

            if (typeof window !== 'undefined') {
                const XLSX = await import('xlsx');
                const worksheet = XLSX.utils.json_to_sheet(exportData);
                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, 'RFIs');

                const colWidths = Object.keys(exportData[0] || {}).map(() => ({ wch: 20 }));
                worksheet['!cols'] = colWidths;

                const filename = `rfis_${selectedObjection?.title?.replace(/[\/\\\s]/g, '_') || 'export'}_${new Date().toISOString().split('T')[0]}`;
                XLSX.writeFile(workbook, `${filename}.xlsx`);
                showToast.success(`Exported ${exportData.length} RFIs successfully`);
            }
        } catch (error) {
            console.error('Export error:', error);
            showToast.error('Failed to export RFIs');
        } finally {
            setExportLoading(false);
        }
    }, [suggestedRfis, selectedRfis, selectedObjection, setExportLoading]);

    const handleEditObjection = useCallback(async () => {
        if (!editForm.title || !editForm.description || !editForm.reason) {
            showToast.error('Please fill in all required fields');
            return;
        }

        setEditLoading(true);
        try {
            const response = await updateObjection.mutateAsync({ id: editObjection.id, data: editForm });
            showToast.success(response.message || 'Objection updated successfully');
            onEditClose();
            router.reload({ only: ['objections', 'statistics'] });
        } catch (error) {
            showToast.error(error.response?.data?.error || 'Failed to update objection');
        } finally {
            setEditLoading(false);
        }
    }, [editForm, editObjection, updateObjection, onEditClose, setEditLoading]);

    const handleStatusChange = useCallback(async () => {
        if (!selectedObjection || !statusAction) return;

        if (['resolve', 'reject'].includes(statusAction) && !resolutionNotes.trim()) {
            showToast.error('Please provide resolution notes');
            return;
        }

        setStatusLoading(true);
        try {
            let response;

            switch (statusAction) {
                case 'submit':
                    response = await submitObjection.mutateAsync(selectedObjection.id);
                    break;
                case 'review':
                    response = await reviewObjection.mutateAsync(selectedObjection.id);
                    break;
                case 'resolve':
                    response = await resolveObjection.mutateAsync({
                        id: selectedObjection.id,
                        resolutionNotes: resolutionNotes,
                    });
                    break;
                case 'reject':
                    response = await rejectObjection.mutateAsync({
                        id: selectedObjection.id,
                        resolutionNotes: resolutionNotes,
                    });
                    break;
                default:
                    throw new Error('Invalid action');
            }

            showToast.success(response.message || 'Status updated successfully');

            if (response.objection) {
                setObjections(prev => ({
                    ...prev,
                    data: prev.data.map(obj =>
                        obj.id === response.objection.id ? response.objection : obj
                    ),
                }));
            }

            if (response.statistics) {
                setApiStats(response.statistics);
            }

            onStatusClose();
            setResolutionNotes('');
        } catch (error) {
            showToast.error(error.response?.data?.error || 'Failed to update status');
        } finally {
            setStatusLoading(false);
        }
    }, [
        selectedObjection,
        statusAction,
        resolutionNotes,
        submitObjection,
        reviewObjection,
        resolveObjection,
        rejectObjection,
        onStatusClose,
        setResolutionNotes,
        setObjections,
        setApiStats,
        setStatusLoading,
    ]);

    const handleDeleteObjection = useCallback(async (objection) => {
        if (!confirm('Are you sure you want to delete this objection?')) return;

        try {
            const response = await deleteObjection.mutateAsync(objection.id);
            showToast.success(response.message || 'Objection deleted successfully');
            router.reload({ only: ['objections', 'statistics'] });
        } catch (error) {
            showToast.error(error.response?.data?.error || 'Failed to delete objection');
        }
    }, [deleteObjection]);

    const isMutating =
        createObjection.isPending ||
        attachRfis.isPending ||
        updateObjection.isPending ||
        submitObjection.isPending ||
        reviewObjection.isPending ||
        resolveObjection.isPending ||
        rejectObjection.isPending ||
        deleteObjection.isPending;

    return {
        handleCreateObjection,
        handleSuggestRfis,
        handleRfiSearch,
        handleAttachRfis,
        handleExportSelectedRfis,
        handleEditObjection,
        handleStatusChange,
        handleDeleteObjection,
        isMutating,
    };
}
