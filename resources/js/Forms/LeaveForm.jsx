import React, { useEffect, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { Alert, Badge, Button, DateRange, FormField, Input, SelectInput, Textarea } from '@/Components/Cyber';
import DepartmentFieldCyber from '@/Components/Access/DepartmentFieldCyber.jsx';
import AttachmentField from '@/Components/Attachments/AttachmentField';
import { showToast } from '@/utils/toastUtils';

const flatten = (errs) => Object.fromEntries(Object.entries(errs || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));

/**
 * Add / edit a leave application, built only from the Cyber library. The leave type decides whether a supporting document is
 * needed (always / beyond N days / optional); the document goes through the shared attachment pipeline (clarity check, private
 * storage) and the application carries its token. The server enforces every rule again.
 */
const LeaveForm = ({
    open,
    closeModal,
    leavesData,
    setLeavesData,
    currentLeave,
    allUsers,
    departments = [],
    selectedMonth,
    addLeaveOptimized,
    updateLeaveOptimized,
    refetchStats,
    fetchLeavesStats,
}) => {
    const { auth } = usePage().props;
    const isSuperAdmin = auth.isSuperAdmin || false;
    const canManageLeaves = isSuperAdmin || auth.permissions?.includes('leaves.approve') || auth.permissions?.includes('leaves.manage') || false;

    const [userId, setUserId] = useState(currentLeave?.user_id || auth.user.id);
    const [departmentId, setDepartmentId] = useState(() => {
        if (currentLeave?.user_id) return allUsers?.find((u) => u.id === currentLeave.user_id)?.department_id ?? '';
        return auth?.user?.department_id ?? '';
    });
    const [leaveType, setLeaveType] = useState(currentLeave?.leave_type || '');
    const [fromDate, setFromDate] = useState(currentLeave?.from_date ? currentLeave.from_date.split('T')[0] : '');
    const [toDate, setToDate] = useState(currentLeave?.to_date ? currentLeave.to_date.split('T')[0] : '');
    const [reason, setReason] = useState(currentLeave?.reason || '');
    const [files, setFiles] = useState([]);
    const [existing, setExisting] = useState(currentLeave?.attachments || []);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);

    const leaveTypes = leavesData?.leaveTypes || [];
    const leaveCounts = leavesData?.leaveCountsByUser?.[userId] || [];

    const daysCount = useMemo(() => {
        const start = new Date(fromDate); const end = new Date(toDate);
        return fromDate && toDate && end >= start ? Math.ceil((end - start) / 86400000) + 1 : '';
    }, [fromDate, toDate]);

    const remainingOf = (type) => {
        const lc = leaveCounts.find((l) => l.leave_type === type.type);
        return lc ? type.days - lc.days_used : type.days;
    };
    const chosenType = leaveTypes.find((t) => t.type === leaveType);
    const remaining = chosenType ? remainingOf(chosenType) : '';

    // Employees of the chosen department (a manager can apply on someone's behalf).
    const employees = useMemo(() => (allUsers || []).filter((u) => !u.deleted_at && (!departmentId || String(u.department_id ?? u.department?.id) === String(departmentId))), [allUsers, departmentId]);
    useEffect(() => {
        if (canManageLeaves && departmentId && userId && !employees.some((e) => String(e.id) === String(userId))) setUserId('');
    }, [departmentId, employees]); // eslint-disable-line react-hooks/exhaustive-deps

    const rule = chosenType?.attachment_rule || (chosenType?.requires_attachment_days != null ? 'beyond_days' : 'optional');
    const required = rule === 'always' || (rule === 'beyond_days' && chosenType?.requires_attachment_days != null && Number(daysCount) > Number(chosenType.requires_attachment_days));
    const hint = rule === 'always' ? 'This leave type always needs a clear supporting document.'
        : rule === 'beyond_days' ? `Required when the leave is longer than ${chosenType.requires_attachment_days} day${chosenType.requires_attachment_days == 1 ? '' : 's'}.`
            : 'Optional.';

    if (!open) return null;
    const close = () => { if (!submitting) closeModal(); };

    const submit = async (e) => {
        e.preventDefault();
        if (submitting) return;
        setSubmitting(true); setErrors({});
        const payload = {
            user_id: userId, leaveType, fromDate, toDate, daysCount, leaveReason: reason, month: selectedMonth,
            attachment_tokens: files.map((f) => f.token),
        };
        if (currentLeave) payload.id = currentLeave.id;

        let response;
        try {
            response = await axios.post(route(currentLeave ? 'leave-update' : 'leave-add'), payload);
        } catch (error) {
            const data = error.response?.data;
            if (error.response?.status === 422) setErrors(flatten(data?.errors));
            showToast.error(data?.message || data?.error || 'Failed to submit application');
            setSubmitting(false);
            return;
        }

        if (response?.status === 200 || response?.status === 201) {
            showToast.success(response.data?.message || 'Leave submitted successfully');
            (response.data?.warnings || []).forEach((w) => showToast.info(w));
            closeModal();
            try {
                if (typeof setLeavesData === 'function' && response.data?.leavesData) setLeavesData(response.data.leavesData);
                if (currentLeave && typeof updateLeaveOptimized === 'function') updateLeaveOptimized(response.data.leave);
                else if (typeof addLeaveOptimized === 'function' && response.data?.leave) addLeaveOptimized(response.data.leave);
                (refetchStats || fetchLeavesStats)?.();
            } catch (uiErr) {
                console.warn('Post-leave submission UI sync warning:', uiErr);
            }
        }
        setSubmitting(false);
    };

    const retire = async (att) => {
        try {
            await axios.delete(route('leaves.attachments.delete', { id: currentLeave.id, mediaId: att.id }));
            setExisting((p) => p.filter((a) => a.id !== att.id));
            showToast.success('Attachment removed');
        } catch (err) {
            showToast.error(err.response?.data?.message || 'Failed to remove attachment');
        }
    };

    return (
        <Dialog title={currentLeave ? 'Edit leave' : 'Add leave'} onClose={close} className="cy-modal cy-modal--wide">
            <form onSubmit={submit}>
                <div className="cy-modal__section">
                    {canManageLeaves && (
                        <>
                            <DepartmentFieldCyber value={departmentId} onChange={setDepartmentId} departments={departments} label="Department" />
                            <FormField label="Employee" htmlFor="lv-employee" required error={errors.user_id}>
                                <SelectInput id="lv-employee" value={String(userId ?? '')} onChange={setUserId}
                                    options={[{ value: '', label: 'Select employee', disabled: true }, ...employees.map((u) => ({ value: String(u.id), label: u.name }))]} />
                            </FormField>
                        </>
                    )}
                    <FormField label="Leave type" htmlFor="lv-type" required error={errors.leaveType}>
                        <SelectInput id="lv-type" value={leaveType} onChange={setLeaveType}
                            options={[{ value: '', label: 'Select leave type', disabled: true }, ...leaveTypes.map((t) => ({ value: t.type, label: `${t.type} (${remainingOf(t)} remaining)`, disabled: remainingOf(t) <= 0 }))]} />
                    </FormField>
                    <FormField label="Remaining days" htmlFor="lv-remaining">
                        <Input id="lv-remaining" readOnly value={remaining} />
                    </FormField>
                    <FormField label="Leave period" error={errors.fromDate || errors.toDate} hint={daysCount ? `${daysCount} day${daysCount === 1 ? '' : 's'}` : undefined}>
                        <DateRange value={{ start: fromDate, end: toDate }} onChange={({ start, end }) => { setFromDate(start); setToDate(end); }} />
                    </FormField>
                    <FormField label="Reason (optional)" htmlFor="lv-reason" error={errors.leaveReason}>
                        <Textarea id="lv-reason" rows={3} value={reason} onChange={(e) => { setReason(e.target.value); if (errors.leaveReason) setErrors({ ...errors, leaveReason: undefined }); }} placeholder="Reason for leave" />
                    </FormField>

                    {existing.length > 0 && (
                        <ul className="cy-docs" aria-label="Documents already attached">
                            {existing.map((att) => (
                                <li key={att.id} className="cy-docs__row">
                                    <span className="cy-docs__main"><a className="cy-docs__name" href={att.url} target="_blank" rel="noopener noreferrer">{att.name}</a></span>
                                    <Badge soft color="success">Attached</Badge>
                                    <Button size="sm" color="default" variant="outline" onClick={() => retire(att)} aria-label={`Remove ${att.name}`}>Remove</Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <AttachmentField value={files} onChange={setFiles} required={required && existing.length === 0} max={3 - existing.length}
                        label="Supporting documents" hint={hint} error={errors.attachments} disabled={submitting} />
                    {errors.attachments && <Alert tone="danger">{errors.attachments}</Alert>}
                </div>
                <div className="cy-modal__foot">
                    <Button color="default" variant="outline" size="sm" onClick={close} disabled={submitting}>Cancel</Button>
                    <Button type="submit" size="sm" loading={submitting}>Submit</Button>
                </div>
            </form>
        </Dialog>
    );
};

export default LeaveForm;
