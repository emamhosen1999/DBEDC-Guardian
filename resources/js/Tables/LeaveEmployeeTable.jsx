import React, { useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { Badge, Button, Checkbox, Icon, Pagination, SelectInput } from '@/Components/Cyber';
import ApprovalActions from '@/Components/Leave/ApprovalActions.jsx';
import LeaveAttachmentActions from '@/Components/Attachments/LeaveAttachmentActions.jsx';

/* ── helpers ─────────────────────────────────────────────────────────────── */
const STATUS_TONE = { New: 'theme', Pending: 'warning', Approved: 'success', Declined: 'danger' };
const STATUS_OPTIONS = Object.keys(STATUS_TONE).map((value) => ({ value, label: value }));

const getLeaveDuration = (fromDate, toDate) => {
    const diff = Math.ceil(Math.abs(new Date(toDate) - new Date(fromDate)) / 86400000) + 1;
    return diff === 1 ? '1 day' : `${diff} days`;
};
const formatDate = (d) => new Date(d).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });

/** The leave list in Cyber's table: one row per application with its status, documents and actions. */
const LeaveEmployeeTable = React.forwardRef(({
    leaves,
    allUsers,
    handleClickOpen,
    setCurrentLeave,
    openModal,
    setLeaves,
    setCurrentPage,
    currentPage,
    totalRows,
    perPage,
    onRowsPerPageChange,
    employee,
    isAdminView = false,
    onBulkDelete,
    onBulkStatusUpdate,
    canApproveLeaves = false,
    canEditLeaves = false,
    canDeleteLeaves = false,
    fetchLeavesStats,
    onLeaveUpdated,
    loading = false,
}, ref) => {
    const { auth } = usePage().props;
    const [updatingLeave, setUpdatingLeave] = useState(null);
    const [selectedIds, setSelectedIds] = useState(new Set());

    const getUserInfo = (userId) => allUsers?.find((u) => String(u.id) === String(userId)) || { name: 'Unknown User', phone: '' };
    const handlePageChange = useCallback((page) => { setCurrentPage?.(page); }, [setCurrentPage]);

    const updateLeaveStatus = useCallback(async (leave, newStatus) => {
        if (leave.status === newStatus) {
            showToast.info(`Leave is already ${newStatus}.`);
            return;
        }
        const actionKey = `${leave.id}-${newStatus}`;
        if (updatingLeave === actionKey) return;
        setUpdatingLeave(actionKey);
        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route('leave-update-status'), { id: leave.id, status: newStatus });
                if (response.status === 200) {
                    setLeaves(prev => prev.map(l => l.id === leave.id ? { ...l, status: newStatus } : l));
                    fetchLeavesStats?.();
                    resolve(response.data.message || 'Leave status updated successfully');
                } else {
                    reject(response.data?.message || 'Failed to update leave status');
                }
            } catch (error) {
                reject(error.response?.data?.message || error.response?.statusText || 'Failed to update leave status');
            } finally {
                setUpdatingLeave(null);
            }
        });
        showToast.promise(promise, {
            loading: 'Updating leave status...',
            success: 'Leave status updated successfully!',
            error:   'Failed to update leave status',
        });
        return promise;
    }, [setLeaves, updatingLeave, fetchLeavesStats]);

    /* ── selection ── */
    const allSelected = leaves.length > 0 && selectedIds.size === leaves.length;
    const toggleAll = () => setSelectedIds(allSelected ? new Set() : new Set(leaves.map((l) => l.id)));
    const toggleOne = (id) => setSelectedIds((prev) => { const next = new Set(prev); next.has(id) ? next.delete(id) : next.add(id); return next; });
    const picked = () => leaves.filter((l) => selectedIds.has(l.id));

    const bulkBar = isAdminView && selectedIds.size > 0 && (
        <div className="cy-bulkbar" role="toolbar" aria-label="Bulk actions">
            <span className="cy-row__sub">{selectedIds.size} of {leaves.length} selected</span>
            {canApproveLeaves && onBulkStatusUpdate && (
                <>
                    <Button size="sm" color="success" onClick={() => onBulkStatusUpdate(picked(), 'approved')}>Approve</Button>
                    <Button size="sm" color="danger" variant="outline" onClick={() => onBulkStatusUpdate(picked(), 'declined')}>Reject</Button>
                </>
            )}
            {onBulkDelete && <Button size="sm" color="danger" variant="outline" onClick={() => { onBulkDelete(picked()); setSelectedIds(new Set()); }}>Delete</Button>}
        </div>
    );

    const busy = (leave) => !!updatingLeave?.startsWith(`${leave.id}-`);
    const columns = [
        ...(isAdminView ? ['select', 'employee'] : []), 'leave_type', 'from_date', 'to_date', 'status', 'reason', ...(isAdminView ? ['actions'] : []),
    ];
    const HEAD = { select: '', employee: 'Employee', leave_type: 'Leave type', from_date: 'From', to_date: 'To', status: 'Status', reason: 'Reason', actions: 'Actions' };

    const cell = (leave, key) => {
        const user = getUserInfo(leave.user_id);
        switch (key) {
            case 'select':
                return <Checkbox checked={selectedIds.has(leave.id)} onCheckedChange={() => toggleOne(leave.id)} aria-label={`Select leave of ${user.name}`} />;
            case 'employee':
                return <><b>{user.name || 'Unknown User'}</b>{user.phone ? <div className="cy-row__sub">{user.phone}</div> : null}</>;
            case 'leave_type':
                return <b>{leave.leave_type}</b>;
            case 'from_date':
                return <>{formatDate(leave.from_date)}<div className="cy-row__sub">{getLeaveDuration(leave.from_date, leave.to_date)}</div></>;
            case 'to_date':
                return formatDate(leave.to_date);
            case 'status': {
                const canApproveThisLeave = leave.approval_chain && leave.status === 'pending'
                    && leave.approval_chain.some((level) => level.level === leave.current_approval_level && level.approver_id === auth.user.id && level.status === 'pending');
                const label = leave.status ? leave.status.charAt(0).toUpperCase() + leave.status.slice(1) : 'New';
                return (
                    <span className="cy-actions">
                        <Badge soft color={STATUS_TONE[label] || 'secondary'}>{label}</Badge>
                        <LeaveAttachmentActions leave={leave} onChanged={() => { fetchLeavesStats?.(); onLeaveUpdated?.(); }} />
                        {canApproveThisLeave && !leave.returned_for_reupload
                            ? <ApprovalActions leave={leave} onApprovalComplete={() => { fetchLeavesStats?.(); onLeaveUpdated?.(); }} />
                            : isAdminView && canApproveLeaves && (
                                <SelectInput size="sm" aria-label={`Set status of the leave of ${user.name}`} value="" disabled={busy(leave)}
                                    onChange={(v) => v && updateLeaveStatus(leave, v)}
                                    options={[{ value: '', label: 'Set status…' }, ...STATUS_OPTIONS]} />
                            )}
                    </span>
                );
            }
            case 'reason':
                return <span className="cy-wrap" title={leave.reason || ''}>{leave.reason || 'No reason provided'}</span>;
            case 'actions':
                return (
                    <span className="cy-actions">
                        {canEditLeaves && <Button size="sm" color="default" variant="outline" disabled={busy(leave)} aria-label="Edit leave" onClick={() => { setCurrentLeave(leave); openModal('addEditLeave'); }}><Icon name="pencil" /> Edit</Button>}
                        {canDeleteLeaves && <Button size="sm" color="danger" variant="outline" disabled={busy(leave)} aria-label="Delete leave" onClick={() => handleClickOpen(leave.id, 'deleteLeave')}><Icon name="trash" /> Delete</Button>}
                    </span>
                );
            default:
                return leave[key];
        }
    };

    return (
        <div>
            {bulkBar}
            <div className="cy-dt" tabIndex={0} role="region" aria-label="Leave applications, scrollable" aria-busy={loading || undefined}>
                <table className="cy-table">
                    <thead>
                        <tr>
                            {columns.map((key) => (
                                <th key={key} scope="col">
                                    {key === 'select'
                                        ? <Checkbox checked={allSelected} onCheckedChange={toggleAll} aria-label="Select all leaves" />
                                        : key === 'actions' ? <span className="visually-hidden">Actions</span> : HEAD[key]}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {leaves.length === 0 ? (
                            <tr><td colSpan={columns.length} className="cy-cell--empty">
                                <div className="cy-empty cy-empty--flat"><p className="cy-empty__text">{employee ? `No leaves for "${employee}"` : 'No leave requests for the selected period'}</p></div>
                            </td></tr>
                        ) : leaves.map((leave) => (
                            <tr key={leave.id}>
                                {columns.map((key) => <td key={key} className={key === 'from_date' || key === 'to_date' ? 'cy-nowrap' : undefined}>{cell(leave, key)}</td>)}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination pagination={{ currentPage, perPage, total: totalRows }} onPageChange={handlePageChange} onRowsPerPageChange={onRowsPerPageChange} loading={loading} label="Leave pages" />
        </div>
    );
});

LeaveEmployeeTable.displayName = 'LeaveEmployeeTable';

export default LeaveEmployeeTable;
