import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { showToast } from '@/utils/toastUtils';
import { handleExportResponse } from '@/utils/exportUtils';
import AuditHistoryModal from './Components/AuditHistoryModal';
import TimesheetMap from './Components/TimesheetMap';
import TimeEdit from './Components/TimeEdit';
import DepartmentSelect from './Components/DepartmentSelect';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { Avatar } from '@/Components/Cyber/Map/Avatar';
import { Card, Field, Icon, IconButton, Pagination, Select, StatStrip, Tabs, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';
import { useAttendanceStore } from '@/store/attendanceStore';
import { RANGE_PRESETS, resolvePreset, isRangeMode } from './logRange';
import { useDailyTimesheet, usePresentUsers, useAttendanceDayPartition, useUpdateTimeCorrection, useMarkAsPresent, useDeleteAttendanceCorrection, useExportDailyTimesheet, useAttendanceLog, useExportAttendanceLog } from '@/api/queries/useAttendanceQuery';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';

/* ── helpers ──────────────────────────────────────────────── */

const formatTime = (timeString, date) => {
    if (!timeString) return null;
    try {
        const fmt = dayjs(date).format('YYYY-MM-DD');
        let dt;
        if (/^\d{2}:\d{2}:\d{2}$/.test(timeString))           dt = new Date(`${fmt}T${timeString}`);
        else if (/^\d{2}:\d{2}$/.test(timeString))             dt = new Date(`${fmt}T${timeString}:00`);
        else if (timeString.includes('T') || timeString.includes(' ')) dt = new Date(timeString);
        else                                                    dt = new Date(`${fmt}T${timeString}`);
        if (isNaN(dt.getTime())) return 'Invalid';
        return dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch { return 'Invalid'; }
};

const Dash = () => <span className="cy-muted">—</span>;

const TABS_ID = 'attendance-day';

/* ── table cell renderer ─────────────────────────────────────── */

/* A punch time: plain text, or a button that opens the inline editor for someone who may correct it. */
const PunchTime = ({ punch, field, label, date, canCorrect, editingCell, onStartEdit, onCancelEdit, onSaveTime }) => {
    const raw = field === 'punchin' ? punch.punch_in : punch.punch_out;
    const timeStr = formatTime(raw, date);
    const isEditing = editingCell?.attendanceId === punch.id && editingCell?.field === field;
    if (canCorrect && isEditing) {
        return <TimeEdit value={raw ? dayjs(raw).format('HH:mm') : ''} onSave={(time) => onSaveTime(punch.id, field, time)} onCancel={onCancelEdit} label={label} />;
    }
    if (canCorrect) {
        return (
            <button type="button" className="cy-linkbtn" onClick={() => onStartEdit(punch.id, field)} aria-label={`Edit ${label.toLowerCase()} time${timeStr ? `, now ${timeStr}` : ''}`}>
                {timeStr || <Dash />}
            </button>
        );
    }
    return <span>{timeStr || <Dash />}</span>;
};

const Cell = ({ attendance, colUid, canCorrect: canCorrectPermission, editingCell, onStartEdit, onCancelEdit, onSaveTime, onDelete, onHistory }) => {
    const isToday = dayjs(attendance.date).isSame(dayjs(), 'day');
    // Permission AND the server's per-row verdict (never oneself, never out of scope / outranking).
    const canCorrect = canCorrectPermission && attendance.can_act === true;
    const editProps = { date: attendance.date, canCorrect, editingCell, onStartEdit, onCancelEdit, onSaveTime };

    switch (colUid) {
        case 'date':
            return (
                <td className="cy-nowrap"><span className="cy-punch"><Icon name="calendar3" />{dayjs(attendance.date).format('MMM D, YYYY')}</span></td>
            );

        case 'employee':
            return (
                <td className="cy-nowrap">
                    <div className="cy-who">
                        <Avatar name={attendance.user?.name} photo={attendance.user?.profile_image_url || attendance.user?.profile_image} />
                        <span className="cy-who__text">
                            <span className="cy-who__name">{attendance.user?.name || 'Unknown'}</span>
                            <span className="cy-who__sub">{attendance.user?.phone || '—'}</span>
                        </span>
                    </div>
                </td>
            );

        case 'clockin_time': {
            const punches = attendance.punches || [];
            return (
                <td>
                    <div className="cy-punchlist">
                        {punches.length > 0 ? punches.map((p) => (
                            <span key={p.id} className="cy-punch cy-punch--in"><Icon name="clock" /><PunchTime punch={p} field="punchin" label="In" {...editProps} /></span>
                        )) : <span className="cy-muted">Not started</span>}
                    </div>
                </td>
            );
        }

        case 'clockout_time': {
            const punches = attendance.punches || [];
            return (
                <td>
                    <div className="cy-punchlist">
                        {punches.length > 0 ? punches.map((p) => (
                            <span key={p.id} className="cy-punch cy-punch--out"><Icon name="clock" /><PunchTime punch={p} field="punchout" label="Out" {...editProps} /></span>
                        )) : attendance.punchout_time
                            ? <span className="cy-muted">{isToday ? 'Still working' : 'Missing punch-out'}</span>
                            : <span className="cy-muted">Not started</span>}
                    </div>
                </td>
            );
        }

        case 'production_time': {
            const mins       = attendance.total_work_minutes || 0;
            const incomplete = attendance.has_incomplete_punch;
            const working    = attendance.punchin_time && !attendance.punchout_time && isToday;

            if (mins > 0) {
                const h = Math.floor(mins / 60);
                const m = Math.floor(mins % 60);
                return (
                    <td>
                        <span className={`cy-punch ${incomplete ? 'cy-text-warn' : 'cy-text-good'}`}><Icon name="clock" /><span>{`${h}h ${m}m`}</span></span>
                        {incomplete && <span className="cy-sub cy-text-warn">partial</span>}
                    </td>
                );
            }
            if (working) return (
                <td>
                    <span className="cy-punch cy-text-warn"><Icon name="arrow-repeat" />In progress</span>
                    <span className="cy-sub">Currently working</span>
                </td>
            );
            if (attendance.punchin_time && !attendance.punchout_time && !isToday) return (
                <td>
                    <span className="cy-punch cy-text-crit"><Icon name="exclamation-triangle" />Incomplete</span>
                    <span className="cy-sub">Missing punch-out</span>
                </td>
            );
            return <td><Dash /></td>;
        }

        case 'punch_details': {
            const count = attendance.punch_count || 0;
            return (
                <td>
                    <span className="cy-nowrap">{count} punch{count !== 1 ? 'es' : ''}</span>
                    {attendance.complete_punches === attendance.punch_count && count > 0
                        ? <span className="cy-sub cy-text-good">All complete</span>
                        : count > 0 ? <span className="cy-sub cy-text-warn">{attendance.complete_punches} complete</span> : null}
                </td>
            );
        }

        case 'actions':
            if (!canCorrect) return <td />;
            return (
                <td>
                    <span className="cy-actions">
                        {attendance.punches && attendance.punches.length > 0 ? (
                            attendance.punches.map((p, idx) => (
                                <span key={p.id} className="cy-actions">
                                    <button type="button" className="cy-iconbtn" onClick={() => onHistory(p.id)} aria-label={`History, punch ${idx + 1}`} title={`History, punch ${idx + 1}`}><Icon name="clock-history" /></button>
                                    <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={() => onDelete(p.id)} aria-label={`Delete punch ${idx + 1}`} title={`Delete punch ${idx + 1}`}><Icon name="trash" /></button>
                                </span>
                            ))
                        ) : (
                            attendance.id && !String(attendance.id).startsWith('user-') && (
                                <span className="cy-actions">
                                    <button type="button" className="cy-iconbtn" onClick={() => onHistory(attendance.id)} aria-label="History" title="History"><Icon name="clock-history" /></button>
                                    <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={() => onDelete(attendance.id)} aria-label="Mark absent" title="Mark absent"><Icon name="trash" /></button>
                                </span>
                            )
                        )}
                    </span>
                </td>
            );

        default:
            return <td><Dash /></td>;
    }
};

/* ── empty and loading states ────────────────────────────────── */

const EmptyState = ({ icon = 'clock', text }) => (
    <div className="cy-empty cy-empty--flat">
        <Icon name={icon} className="cy-empty__icon" />
        <p className="cy-empty__text">{text}</p>
    </div>
);

const Loading = ({ text = 'Loading…' }) => (
    <div className="cy-empty cy-empty--flat" role="status"><p className="cy-empty__text">{text}</p></div>
);

/* ── partition row (absent / upcoming / off-leave tabs) ──────── */

const shiftLabel = (shift) => {
    if (!shift) return null;
    const code = shift.code ? `[${shift.code}]` : '';
    const window = shift.start ? `${shift.start}${shift.end ? ` – ${shift.end}` : ''}` : '';
    return [code, window].filter(Boolean).join(' ');
};

const PartitionRow = ({ row, variant, onMarkAsPresent, markingId, canManage }) => {
    const user = row.user || {};
    const uid = user.id;

    return (
        <li>
            <Avatar name={user.name} photo={user.profile_image_url || user.profile_image} />
            <div className="cy-rows__main">
                <div className="cy-who__name">{user.name || 'Unknown'}</div>
                {user.employee_id && <div className="cy-who__sub">#{user.employee_id}</div>}

                {/* variant-specific detail */}
                {variant === 'absent' && (
                    <div className="cy-rows__detail">
                        {row.shift
                            ? <span className="cy-punch cy-text-crit"><Icon name="calendar3" />{shiftLabel(row.shift)}</span>
                            : <span className="cy-punch cy-muted"><Icon name="people" />Rostered, no punch</span>}
                    </div>
                )}
                {variant === 'upcoming' && (
                    <div className="cy-rows__detail">
                        <span className="cy-punch cy-text-info"><Icon name="clock" />{shiftLabel(row.shift) || 'Scheduled'}</span>
                    </div>
                )}
                {variant === 'off_leave' && (
                    <div className="cy-rows__detail">
                        {row.kind === 'leave'
                            ? <span className="cy-punch cy-text-info"><Icon name="calendar-event" />On leave{row.leave_type ? ` · ${row.leave_type}` : ''}</span>
                            : <span className="cy-punch cy-muted"><Icon name="calendar-event" />Off</span>}
                    </div>
                )}
            </div>

            {/* Mark present: absent tab only */}
            {variant === 'absent' && canManage && user.can_act === true && onMarkAsPresent && (
                <button type="button" className="cy-btn cy-btn--outline-default" disabled={markingId === uid} onClick={() => onMarkAsPresent(user)}>
                    <Icon name={markingId === uid ? 'arrow-repeat' : 'check-circle'} className={markingId === uid ? 'cy-spin' : ''} /> {markingId === uid ? 'Marking…' : 'Mark present'}
                </button>
            )}
        </li>
    );
};

/* A partition tab: the loading and empty states, or the rows. */
const PartitionList = ({ rows, variant, isLoading, emptyIcon, emptyText, onMarkAsPresent, markingId, canManage }) => {
    if (isLoading) return <Loading />;
    if (!rows || rows.length === 0) return <EmptyState icon={emptyIcon} text={emptyText} />;
    return (
        <ul className="cy-rows">
            {rows.map((row, i) => (
                <PartitionRow key={row.user?.id ?? `row-${i}`} row={row} variant={variant} onMarkAsPresent={onMarkAsPresent} markingId={markingId} canManage={canManage} />
            ))}
        </ul>
    );
};

/* ── main ─────────────────────────────────────────────────── */

const DailyTimesheetTab = ({
    selectedDate,
    onDateChange,
    isActive = true,
    departments = [],
    designations = [],
    onSummary,
}) => {
    const { auth, url } = usePage().props;

    const canViewAll = auth.permissions?.includes('attendance.view')   || false;
    const canManage  = auth.permissions?.includes('attendance.correct') || auth.permissions?.includes('attendance.create') || false;
    const canCorrect = auth.permissions?.includes('attendance.correct') || auth.permissions?.includes('attendance.delete') || false;
    const canExport  = auth.permissions?.includes('attendance.export') || canManage;
    const isAdminView = canViewAll && url !== '/attendance-employee';

    // Zustand store for shared state
    const { employeeQuery, setEmployeeQuery } = useAttendanceStore();

    /* state */
    const [downloading,  setDownloading]  = useState('');
    const [activeTab,    setActiveTab]    = useState('present'); // present | absent | upcoming | offleave
    const [lastChecked,  setLastChecked]  = useState(null);
    const prevUpdateRef = useRef(null);

    /* Filters and page live in the URL under a `t_` prefix (every Attendance
       tab stays mounted). The from/to range and preset stay local: they are
       derived from the page's `date` and re-anchored by the effect below. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 't_page',
        debounceKeys: [],
        defaults: { t_dept: '', t_desig: '', t_status: '', t_page: 1, t_per: 20 },
    });
    const currentPage = f.values.t_page;
    const perPage = f.values.t_per;
    const setCurrentPage = f.setPage;
    const setPerPage = (v) => f.set('t_per', v);

    // Range + filter state (Log mode)
    const [toDate, setToDate] = useState(selectedDate);
    const [preset, setPreset] = useState('today');

    // The server confines the timesheet to the actor's departments; the filter only narrows within them.
    const deptFilter = f.values.t_dept || '';
    const setDeptFilter = (v) => f.set('t_dept', v);
    const desigFilter = f.values.t_desig;
    const setDesigFilter = (v) => f.set('t_desig', v);
    const statusFilter = f.values.t_status;
    const setStatusFilter = (v) => f.set('t_status', v);

    // Keep "to" anchored to "from" while in single-day (today/preset) usage.
    useEffect(() => {
        if (!isRangeMode(selectedDate, toDate) && preset !== 'custom') {
            setToDate(selectedDate);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedDate]);

    const rangeMode = isRangeMode(selectedDate, toDate);

    // Editing state for inline correction
    const [editingCell, setEditingCell] = useState(null); // { attendanceId, field: 'punchin' | 'punchout' }

    // React Query hooks
    const { data: dailyTimesheetData, isLoading: isLoadingTimesheet, refetch: refetchTimesheet } = useDailyTimesheet({
        date: selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null,
        page: currentPage,
        perPage,
        employee: employeeQuery,
        departmentId: deptFilter || undefined,
        designationId: desigFilter || undefined,
    });

    // present-users cache is warmed here so useMarkAsPresent's optimistic patch has a
    // target; we only need its refetcher for the realtime signal.
    const { refetch: refetchPresent } = usePresentUsers(
        selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null
    );

    // Single-day partition (present / absent / upcoming / off-leave) — powers the
    // team-attendance style tabs + stat band. Only the department filter is a server
    // param (frozen contract); it is disabled outside the admin single-day view.
    const partitionEnabled = isAdminView && !rangeMode;
    const { data: partitionData, isLoading: isLoadingPartition, refetch: refetchPartition } = useAttendanceDayPartition(
        partitionEnabled && selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null,
        deptFilter || undefined,
        desigFilter || undefined
    );

    // Live updates: when anyone punches (mobile/web) or is marked present for this date,
    // another user's dashboard refetches presence within ~1s. The actor's own change is
    // filtered out (their mutation already refetched). Past-date views only react to that date.
    const signalDate = selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null;
    useRealtimeSignals({
        path: isActive && signalDate ? `attendance/${signalDate}` : null,
        selfActorId: auth?.user?.id ?? null,
        onSignal: () => { refetchPresent(); refetchPartition(); refetchTimesheet(); },
    });

    const logFilters = {
        employee: employeeQuery || undefined,
        departmentId: deptFilter || undefined,
        designationId: desigFilter || undefined,
        status: statusFilter || undefined,
    };

    const { data: logData, isLoading: isLoadingLog } = useAttendanceLog({
        from: rangeMode ? selectedDate : null,
        to: rangeMode ? toDate : null,
        page: currentPage,
        perPage,
        ...logFilters,
    });

    const exportLog = useExportAttendanceLog();

    // Mutations
    const updateTimeCorrection = useUpdateTimeCorrection();
    const markAsPresent = useMarkAsPresent();
    const deleteAttendanceCorrection = useDeleteAttendanceCorrection();
    const exportDailyTimesheet = useExportDailyTimesheet();

    // Derived state from React Query data
    const attendances = dailyTimesheetData?.attendances || [];
    const totalRows = dailyTimesheetData?.total || 0;
    const lastPage = dailyTimesheetData?.last_page || 1;
    const isLoaded = !isLoadingTimesheet; // present-tab table readiness

    // Partition payload — defensive against a `{ success, data }` envelope (requestJson
    // already unwraps `success`, but this keeps us safe either way).
    const partition = (partitionData && partitionData.counts) ? partitionData : (partitionData?.data ?? {});
    const counts = partition.counts || { present: 0, absent: 0, upcoming: 0, off_leave: 0, total: 0 };

    // Client-side search across the partition tabs (the frozen contract only accepts
    // date + department_id server-side; department is already applied by the endpoint).
    const q = (employeeQuery || '').trim().toLowerCase();
    const matchesSearch = (u) => !q
        || (u?.name || '').toLowerCase().includes(q)
        || String(u?.employee_id || '').toLowerCase().includes(q);
    const filterRows = (rows) => (Array.isArray(rows) ? rows.filter((r) => matchesSearch(r?.user)) : []);

    const absentRows   = filterRows(partition.absent);
    const upcomingRows = filterRows(partition.upcoming);
    const offLeaveRows = filterRows(partition.off_leave);

    /* columns */
    const columns = useMemo(() => [
        ...(!isAdminView            ? [{ uid: 'date',            name: 'Date'       }] : []),
        ...(isAdminView             ? [{ uid: 'employee',        name: 'Employee'   }] : []),
        { uid: 'clockin_time',        name: 'Clock In'   },
        { uid: 'clockout_time',       name: 'Clock Out'  },
        { uid: 'production_time',     name: 'Work Hours' },
        { uid: 'punch_details',       name: 'Punches'    },
        ...(canCorrect              ? [{ uid: 'actions',         name: 'Actions'    }] : []),
    ], [isAdminView, canCorrect]);

    /* polling */
    const checkUpdates = useCallback(async () => {
        if (!selectedDate || !isActive || document.visibilityState === 'hidden') return;
        try {
            const res = await fetch(route('check-timesheet-updates', {
                date: dayjs(selectedDate).format('YYYY-MM-DD'),
            }));
            if (!res.ok) return;
            const data = await res.json();
            if (data.success && data.last_updated !== prevUpdateRef.current) {
                prevUpdateRef.current = data.last_updated;
                await Promise.all([refetchTimesheet(), refetchPartition()]);
            }
            setLastChecked(new Date());
        } catch { /* silent */ }
    }, [selectedDate, refetchTimesheet, refetchPartition]);

    const externalKeyRef = useRef(`${selectedDate}|${toDate}|${employeeQuery}`);
    useEffect(() => {
        const key = `${selectedDate}|${toDate}|${employeeQuery}`;
        if (externalKeyRef.current === key) return;
        externalKeyRef.current = key;
        if (f.values.t_page !== 1) f.setPage(1);
    }, [selectedDate, toDate, employeeQuery]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        // React Query handles automatic refetching based on dependencies
        // No manual fetch needed
    }, [selectedDate, perPage, employeeQuery]);

    useEffect(() => {
        if (currentPage > 1) refetchTimesheet();
    }, [currentPage, refetchTimesheet]);

    useEffect(() => {
        const id = setInterval(checkUpdates, 5000);
        return () => clearInterval(id);
    }, [checkUpdates]);

    /* export */
    const exportFile = useCallback(async (type) => {
        setDownloading(type);
        try {
            const mime = type === 'pdf' ? 'application/pdf' : undefined;
            const ext  = type === 'excel' ? 'xlsx' : 'pdf';

            let data;
            if (rangeMode) {
                data = await exportLog.mutateAsync({
                    from: selectedDate, to: toDate, type, ...logFilters,
                });
            } else {
                data = await exportDailyTimesheet.mutateAsync({ date: selectedDate, type });
            }

            const label = rangeMode
                ? `Attendance_Log_${dayjs(selectedDate).format('YYYY_MM_DD')}_${dayjs(toDate).format('YYYY_MM_DD')}.${ext}`
                : `Daily_Timesheet_${dayjs(selectedDate).format('YYYY_MM_DD')}.${ext}`;
            await handleExportResponse(data, label, mime, ext);
        } catch (err) {
            console.error('Export failed:', err);
            showToast.error(`Failed to download ${type}.`);
        } finally { setDownloading(''); }
    }, [selectedDate, toDate, rangeMode, exportDailyTimesheet, exportLog, employeeQuery, deptFilter, desigFilter, statusFilter]);

    const applyPreset = useCallback((value) => {
        setPreset(value);
        const resolved = resolvePreset(value);
        if (!resolved) return; // custom: leave dates as-is
        onDateChange({ target: { value: resolved.from } });
        setToDate(resolved.to);
        setCurrentPage(1);
    }, [onDateChange]);

    // Handle time correction save
    const handleTimeSave = async (attendanceId, field, time) => {
        try {
            const formattedTime = dayjs(`${selectedDate} ${time}`).format('YYYY-MM-DD HH:mm:ss');

            if (!attendanceId) {
                showToast.warning('Cannot create new attendance record from this view. Please use mark as present.');
                return;
            }

            await updateTimeCorrection.mutateAsync({
                attendanceId,
                data: { [field]: formattedTime },
            });

            // Optimistic patch already flipped the cell; the mutation reconciles
            // on settle. No manual refetch needed (avoids a redundant full fetch).
            setEditingCell(null);
        } catch (error) {
            console.error('Error updating attendance:', error);
            showToast.error(error.response?.data?.error || 'Failed to update attendance');
        }
    };

    // Handle delete attendance
    const handleDeleteAttendance = async (attendanceId) => {
        if (!confirm('Are you sure you want to delete this attendance record?')) {
            return;
        }

        try {
            await deleteAttendanceCorrection.mutateAsync(attendanceId);
            refetchTimesheet();
        } catch (error) {
            console.error('Error deleting attendance:', error);
            showToast.error('Failed to delete attendance record');
        }
    };

    // Start editing a cell
    const startEdit = (attendanceId, field) => {
        setEditingCell({ attendanceId, field });
    };

    // Cancel editing
    const cancelEdit = () => {
        setEditingCell(null);
    };

    /* audit history modal */
    const [historyId, setHistoryId] = useState(null);

    /* mark as present */
    const [markingId, setMarkingId] = useState(null);
    const handleMarkAsPresent = useCallback(async (user, date = selectedDate) => {
        setMarkingId(user.id);
        try {
            await markAsPresent.mutateAsync({
                userId: user.id,
                date: dayjs(date).format('YYYY-MM-DD'),
            });
            // useMarkAsPresent already patches the daily-timesheet / present-users caches
            // optimistically; refetch the partition (its own cache key) so the Absent tab
            // drops the row and the stat band re-counts.
            await Promise.all([refetchTimesheet(), refetchPartition()]);
        } catch (e) {
            const msg = e.response?.data?.message || 'Failed to mark as present.';
            showToast.error(msg);
        } finally {
            setMarkingId(null);
        }
    }, [selectedDate, refetchTimesheet, refetchPartition, markAsPresent]);

    /* Partition tab definitions: counts drive the stat strip and the tab counters. */
    const partitionTabs = [
        { key: 'present',  label: 'Present',     count: counts.present ?? 0,   tone: 'success' },
        { key: 'absent',   label: 'Absent',      count: counts.absent ?? 0,    tone: 'danger' },
        { key: 'upcoming', label: 'Upcoming',    count: counts.upcoming ?? 0,  tone: 'theme' },
        { key: 'offleave', label: 'Off / Leave', count: counts.off_leave ?? 0 },
    ];

    const stats = [
        { key: 'present',  label: 'Present',      value: isLoadingPartition ? undefined : counts.present ?? 0,   tone: 'good' },
        { key: 'absent',   label: 'Absent',       value: isLoadingPartition ? undefined : counts.absent ?? 0,    tone: (counts.absent ?? 0) > 0 ? 'crit' : 'neutral' },
        { key: 'upcoming', label: 'Upcoming',     value: isLoadingPartition ? undefined : counts.upcoming ?? 0,  tone: 'theme' },
        { key: 'off',      label: 'Off / Leave',  value: isLoadingPartition ? undefined : counts.off_leave ?? 0, tone: 'neutral' },
        { key: 'total',    label: 'Total roster', value: isLoadingPartition ? undefined : counts.total ?? 0,     tone: 'info' },
    ];

    /* The page header shows this tab's figures as chips; report them by value so the effect never loops. */
    const summaryChips = rangeMode
        ? [{ value: logData?.total, label: 'Records', tone: 'theme' }]
        : isAdminView && partitionData
            ? [
                { value: counts.present ?? 0, label: 'Present', tone: 'success' },
                { value: counts.absent ?? 0, label: 'Absent', tone: (counts.absent ?? 0) > 0 ? 'danger' : 'default' },
                { value: counts.upcoming ?? 0, label: 'Upcoming', tone: 'default' },
                { value: counts.off_leave ?? 0, label: 'Off / leave', tone: 'warning' },
            ]
            : [];
    const summaryKey = JSON.stringify(summaryChips);
    useEffect(() => {
        onSummary?.(summaryChips);
    }, [summaryKey, onSummary]); // eslint-disable-line react-hooks/exhaustive-deps

    /* The page of rows the table shows, and the card's footer (pagination) for it. */
    const paginationFor = (total, loadingFlag, lastPageNo) => ((lastPageNo > 1 || currentPage > 1) && !loadingFlag ? (
        <Pagination
            pagination={{ currentPage, perPage, total }}
            onPageChange={setCurrentPage}
            onRowsPerPageChange={(v) => setPerPage(v)}
            loading={loadingFlag}
            label="Timesheet pagination"
        />
    ) : null);

    const presentTable = (
        <div className="cy-dt">
            <table className="cy-table">
                <thead>
                    <tr>{columns.map((c) => <th key={c.uid} scope="col">{c.name}</th>)}</tr>
                </thead>
                <tbody>
                    {!isLoaded
                        ? <tr><td colSpan={columns.length} className="cy-cell--empty"><Loading text="Loading timesheet…" /></td></tr>
                        : attendances.length === 0
                            ? <tr><td colSpan={columns.length} className="cy-cell--empty"><EmptyState text="No attendance records for this date" /></td></tr>
                            : attendances.map((a) => (
                                <tr key={a.id || a.user_id}>
                                    {columns.map((c) => (
                                        <Cell
                                            key={c.uid}
                                            attendance={a}
                                            colUid={c.uid}
                                            canCorrect={canCorrect}
                                            editingCell={editingCell}
                                            onStartEdit={startEdit}
                                            onCancelEdit={cancelEdit}
                                            onSaveTime={handleTimeSave}
                                            onDelete={handleDeleteAttendance}
                                            onHistory={setHistoryId}
                                        />
                                    ))}
                                </tr>
                            ))}
                </tbody>
            </table>
        </div>
    );

    const logTable = (
        <div className="cy-dt cy-dt--tall">
            <table className="cy-table">
                <thead>
                    <tr><th scope="col">Date</th><th scope="col">Employee</th><th scope="col">Clock in</th><th scope="col">Clock out</th><th scope="col">Work hours</th><th scope="col">Status</th></tr>
                </thead>
                <tbody>
                    {isLoadingLog
                        ? <tr><td colSpan={6} className="cy-cell--empty"><Loading text="Loading log…" /></td></tr>
                        : (logData?.rows?.length ?? 0) === 0
                            ? <tr><td colSpan={6} className="cy-cell--empty"><EmptyState text="No records for this range and filters" /></td></tr>
                            : logData.rows.map((row, idx) => (
                                <tr key={`${row.user_id}-${row.date}-${idx}`}>
                                    <td className="cy-nowrap">{dayjs(row.date).format('MMM D, YYYY')}</td>
                                    <td><span className="cy-who__name">{row.employee_name}</span></td>
                                    <td className="cy-nowrap">{row.clock_in ? dayjs(row.clock_in).format('h:mm A') : '—'}</td>
                                    <td className="cy-nowrap">{row.clock_out ? dayjs(row.clock_out).format('h:mm A') : '—'}</td>
                                    <td className="cy-nowrap">{row.work_hours}</td>
                                    <td className="cy-muted">{row.remarks}</td>
                                </tr>
                            ))}
                </tbody>
            </table>
        </div>
    );

    const cardFooter = rangeMode
        ? paginationFor(logData?.total ?? 0, isLoadingLog, logData?.last_page ?? 1)
        : paginationFor(totalRows, !isLoaded, lastPage);

    const panelProps = (key) => ({ role: 'tabpanel', id: panelId(TABS_ID, key), 'aria-labelledby': tabId(TABS_ID, key) });

    /* ── render ─────────────────────────────────────────────── */
    return (
        <>
            <div className="dl-page">
                <div className="dl-row">
                    <div className="dl-col dl-col--12">
                        <Card id="attendance-timesheet" title={rangeMode ? 'Attendance log' : isAdminView ? 'Daily timesheet' : 'My timesheet'} flush footer={cardFooter}>
                            {/* ── Toolbar (identical for every tab) ─────────────── */}
                            <Toolbar label="Timesheet filters">
                                <ToolbarGroup>
                                    {isAdminView && (
                                        <Field icon="search" type="search" label="Search employee…" value={employeeQuery} onChange={(e) => setEmployeeQuery(e.target.value)} />
                                    )}

                                    <Select label="Date range" value={preset} onChange={applyPreset} options={RANGE_PRESETS} />

                                    {preset === 'custom' && (
                                        <>
                                            <Field
                                                icon="calendar3" type="date" label="From date" value={dayjs(selectedDate).format('YYYY-MM-DD')} max={dayjs(toDate).format('YYYY-MM-DD')}
                                                onChange={(e) => {
                                                    const start = e.target.value;
                                                    if (!start) return;
                                                    onDateChange({ target: { value: start } });
                                                    if (start > dayjs(toDate).format('YYYY-MM-DD')) setToDate(start);
                                                    setCurrentPage(1);
                                                }}
                                            />
                                            <Field
                                                icon="calendar3" type="date" label="To date" value={dayjs(toDate).format('YYYY-MM-DD')} min={dayjs(selectedDate).format('YYYY-MM-DD')}
                                                onChange={(e) => { if (e.target.value) { setToDate(e.target.value); setCurrentPage(1); } }}
                                            />
                                        </>
                                    )}

                                    {/* Department + Designation: shown for admin in BOTH single-day (tabs)
                                        and range (log) mode. Department drives the partition endpoint. */}
                                    {isAdminView && (
                                        <DepartmentSelect value={deptFilter || 'all'} onChange={(v) => setDeptFilter(v === 'all' ? '' : v)} departments={departments} />
                                    )}

                                    {isAdminView && (
                                        <Select
                                            label="Designation"
                                            value={desigFilter ? String(desigFilter) : 'all'}
                                            onChange={(v) => setDesigFilter(v === 'all' ? '' : v)}
                                            options={[{ value: 'all', label: 'All designations' }, ...designations.map((d) => ({ value: String(d.id), label: d.title }))]}
                                        />
                                    )}

                                    {/* Status filter is only meaningful for the ranged log table. */}
                                    {isAdminView && rangeMode && (
                                        <Select
                                            label="Status"
                                            value={statusFilter || 'all'}
                                            onChange={(v) => setStatusFilter(v === 'all' ? '' : v)}
                                            options={[
                                                { value: 'all', label: 'All statuses' }, { value: 'present', label: 'Present' }, { value: 'absent', label: 'Absent' },
                                                { value: 'on_leave', label: 'On leave' }, { value: 'incomplete', label: 'Incomplete' }, { value: 'holiday', label: 'Holiday' }, { value: 'day_off', label: 'Day off' },
                                            ]}
                                        />
                                    )}
                                </ToolbarGroup>

                                {/* right: last updated + refresh + export */}
                                <ToolbarGroup end>
                                    {lastChecked && (
                                        <span className="cy-toolbar__note" role="status">
                                            Updated {lastChecked.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true })}
                                        </span>
                                    )}

                                    <IconButton icon="arrow-clockwise" label="Refresh" onClick={() => Promise.all([refetchTimesheet(), refetchPartition()])} />

                                    {canExport && (
                                        <>
                                            <button type="button" className="cy-btn cy-btn--outline-default" disabled={!isLoaded || downloading !== ''} onClick={() => exportFile('excel')}>
                                                <Icon name="file-earmark-excel" /> {downloading === 'excel' ? 'Exporting…' : 'Excel'}
                                            </button>
                                            <button type="button" className="cy-btn cy-btn--outline-default" disabled={!isLoaded || downloading !== ''} onClick={() => exportFile('pdf')}>
                                                <Icon name="file-earmark-pdf" /> {downloading === 'pdf' ? 'Exporting…' : 'PDF'}
                                            </button>
                                        </>
                                    )}
                                </ToolbarGroup>
                            </Toolbar>

                            {/* Body: (1) ranged log table, (2) non-admin self view, (3) admin stat strip + Present / Absent / Upcoming / Off-Leave */}
                            {rangeMode && logTable}
                            {!rangeMode && !isAdminView && presentTable}
                            {!rangeMode && isAdminView && (
                                <>
                                    <StatStrip items={stats} label="Today's attendance" busy={isLoadingPartition} />
                                    <Tabs tabs={partitionTabs} value={activeTab} onChange={setActiveTab} idPrefix={TABS_ID} label="Attendance by status" className="cy-tabs--scroll" />
                                    {activeTab === 'present' && <div {...panelProps('present')}>{presentTable}</div>}
                                    {activeTab === 'absent' && (
                                        <div {...panelProps('absent')}>
                                            <PartitionList
                                                rows={absentRows} variant="absent" isLoading={isLoadingPartition} emptyIcon="check-circle"
                                                emptyText="No absentees — everyone rostered has punched in."
                                                onMarkAsPresent={handleMarkAsPresent} markingId={markingId} canManage={canManage}
                                            />
                                        </div>
                                    )}
                                    {activeTab === 'upcoming' && (
                                        <div {...panelProps('upcoming')}>
                                            <PartitionList rows={upcomingRows} variant="upcoming" isLoading={isLoadingPartition} emptyIcon="clock" emptyText="No upcoming shifts — no one is scheduled to start later today." />
                                        </div>
                                    )}
                                    {activeTab === 'offleave' && (
                                        <div {...panelProps('offleave')}>
                                            <PartitionList rows={offLeaveRows} variant="off_leave" isLoading={isLoadingPartition} emptyIcon="calendar-event" emptyText="No one is off or on leave for this date." />
                                        </div>
                                    )}
                                </>
                            )}
                        </Card>
                    </div>
                </div>

                {/* ── Map: admin only, single-day mode only ───────── */}
                {isAdminView && !rangeMode && (
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <ErrorBoundary>
                                <TimesheetMap selectedDate={selectedDate} isActive={isActive} />
                            </ErrorBoundary>
                        </div>
                    </div>
                )}
            </div>

            <AuditHistoryModal
                open={!!historyId}
                attendanceId={historyId}
                onOpenChange={() => setHistoryId(null)}
            />
        </>
    );
};

export default DailyTimesheetTab;
