import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import {
    Box, Flex, Text, Table, Badge, Avatar, Button,
    TextField, ScrollArea, Skeleton, Tooltip, Select,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon, CalendarIcon, ClockIcon, PersonIcon,
    ExclamationTriangleIcon, CheckCircledIcon, DownloadIcon,
    MobileIcon,
    ReloadIcon, UpdateIcon, TrashIcon, CounterClockwiseClockIcon,
    ChevronLeftIcon, ChevronRightIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import dayjs from 'dayjs';
import { showToast } from '@/utils/toastUtils';
import { handleExportResponse } from '@/utils/exportUtils';
import AbsentSidebar from './AbsentSidebar';
import AuditHistoryModal from './Components/AuditHistoryModal';
import UserLocationsCard from '@/Components/UserLocationsCard.jsx';
import AttendanceTimePicker from '@/Components/AttendanceTimePicker.jsx';
import DateTimePicker from '@/Components/DateTimePicker';
import TablePagination from '@/Components/TablePagination.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { useAttendanceStore } from '@/store/attendanceStore';
import { RANGE_PRESETS, resolvePreset, isRangeMode } from './logRange';
import { useDailyTimesheet, usePresentUsers, useAbsentUsers, useUpdateTimeCorrection, useMarkAsPresent, useDeleteAttendanceCorrection, useExportDailyTimesheet, useAttendanceLog, useExportAttendanceLog } from '@/api/queries/useAttendanceQuery';
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

/* Sticky header: keeps column labels visible while the page scrolls a tall (≤20-row) table. */
const STICKY_HEAD = {
    position: 'sticky',
    top: 0,
    zIndex: 2,
    background: 'var(--color-panel-solid)',
    boxShadow: 'inset 0 -1px 0 var(--gray-a5)',
};

/* ── table cell renderer ─────────────────────────────────────── */

const Cell = ({ attendance, colUid, isAdminView, canCorrect, editingCell, onStartEdit, onCancelEdit, onSaveTime, onDelete, onHistory }) => {
    const isToday = dayjs(attendance.date).isSame(dayjs(), 'day');

    switch (colUid) {
        case 'date':
            return (
                <Table.Cell>
                    <Flex align="center" gap="2">
                        <CalendarIcon style={{ color: 'var(--accent-9)', width: 14, flexShrink: 0 }} />
                        <Text size="2">{dayjs(attendance.date).format('MMM D, YYYY')}</Text>
                    </Flex>
                </Table.Cell>
            );

        case 'employee':
            return (
                <Table.Cell style={{ whiteSpace: 'nowrap' }}>
                    <Flex align="center" gap="2">
                        <Avatar
                            src={attendance.user?.profile_image_url || attendance.user?.profile_image}
                            fallback={(attendance.user?.name || '?').charAt(0).toUpperCase()}
                            size="1"
                            radius="full"
                            style={{ flexShrink: 0 }}
                        />
                        <Flex direction="column" gap="0">
                            <Text size="2" weight="medium">{attendance.user?.name || 'Unknown'}</Text>
                            {attendance.user?.phone
                                ? <Text size="1" color="gray">{attendance.user.phone}</Text>
                                : <Flex align="center" gap="1">
                                    <MobileIcon style={{ width: 10, color: 'var(--gray-8)' }} />
                                    <Text size="1" color="gray">—</Text>
                                  </Flex>
                            }
                        </Flex>
                    </Flex>
                </Table.Cell>
            );

        case 'clockin_time': {
            const punches = attendance.punches || [];
            
            return (
                <Table.Cell>
                    <Flex direction="column" gap="1">
                        {punches.length > 0 ? (
                            punches.map((p) => {
                                const isEditing = editingCell?.attendanceId === p.id && editingCell?.field === 'punchin';
                                const timeStr = formatTime(p.punch_in, attendance.date);
                                
                                return (
                                    <Flex key={p.id} align="center" gap="2">
                                        <ClockIcon style={{ color: 'var(--green-9)', width: 14, flexShrink: 0 }} />
                                        {canCorrect && isEditing ? (
                                            <AttendanceTimePicker
                                                value={p.punch_in ? dayjs(p.punch_in).format('HH:mm') : ''}
                                                onSave={(time) => onSaveTime(p.id, 'punchin', time)}
                                                onCancel={onCancelEdit}
                                                label="In"
                                            />
                                        ) : canCorrect ? (
                                            <Text 
                                                size="2" 
                                                weight="medium"
                                                style={{ cursor: 'pointer', color: 'var(--accent-11)' }}
                                                onClick={() => onStartEdit(p.id, 'punchin')}
                                            >
                                                {timeStr || <Text size="2" color="gray">—</Text>}
                                            </Text>
                                        ) : (
                                            <Text size="2" weight="medium">
                                                {timeStr || <Text size="2" color="gray">—</Text>}
                                            </Text>
                                        )}
                                    </Flex>
                                );
                            })
                        ) : (
                            <Text size="2" color="gray">Not started</Text>
                        )}
                    </Flex>
                </Table.Cell>
            );
        }

        case 'clockout_time': {
            const punches = attendance.punches || [];
            
            return (
                <Table.Cell>
                    <Flex direction="column" gap="1">
                        {punches.length > 0 ? (
                            punches.map((p) => {
                                const isEditing = editingCell?.attendanceId === p.id && editingCell?.field === 'punchout';
                                const timeStr = formatTime(p.punch_out, attendance.date);
                                
                                return (
                                    <Flex key={p.id} align="center" gap="2">
                                        <ClockIcon style={{ color: 'var(--red-9)', width: 14, flexShrink: 0 }} />
                                        {canCorrect && isEditing ? (
                                            <AttendanceTimePicker
                                                value={p.punch_out ? dayjs(p.punch_out).format('HH:mm') : ''}
                                                onSave={(time) => onSaveTime(p.id, 'punchout', time)}
                                                onCancel={onCancelEdit}
                                                label="Out"
                                            />
                                        ) : canCorrect ? (
                                            <Text 
                                                size="2" 
                                                weight="medium"
                                                style={{ cursor: 'pointer', color: 'var(--accent-11)' }}
                                                onClick={() => onStartEdit(p.id, 'punchout')}
                                            >
                                                {timeStr || <Text size="2" color="gray">—</Text>}
                                            </Text>
                                        ) : (
                                            <Text size="2" weight="medium">
                                                {timeStr || <Text size="2" color="gray">—</Text>}
                                            </Text>
                                        )}
                                    </Flex>
                                );
                            })
                        ) : attendance.punchout_time ? (
                            <Text size="2" color="gray">{isToday ? 'Still working' : 'Missing punch-out'}</Text>
                        ) : (
                            <Text size="2" color="gray">Not started</Text>
                        )}
                    </Flex>
                </Table.Cell>
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
                    <Table.Cell>
                        <Flex align="center" gap="2">
                            <ClockIcon style={{ color: incomplete ? 'var(--amber-9)' : 'var(--green-9)', width: 14, flexShrink: 0 }} />
                            <Flex direction="column" gap="0">
                                <Text size="2" weight="medium">{`${h}h ${m}m`}</Text>
                                {incomplete && <Badge color="amber" size="1" variant="soft">partial</Badge>}
                            </Flex>
                        </Flex>
                    </Table.Cell>
                );
            }
            if (working) return (
                <Table.Cell>
                    <Flex align="center" gap="2">
                        <UpdateIcon style={{ color: 'var(--amber-9)', width: 14, flexShrink: 0 }} />
                        <Flex direction="column" gap="0">
                            <Text size="2" color="amber">In Progress</Text>
                            <Text size="1" color="gray">Currently working</Text>
                        </Flex>
                    </Flex>
                </Table.Cell>
            );
            if (attendance.punchin_time && !attendance.punchout_time && !isToday) return (
                <Table.Cell>
                    <Flex align="center" gap="2">
                        <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 14, flexShrink: 0 }} />
                        <Flex direction="column" gap="0">
                            <Text size="2" color="red">Incomplete</Text>
                            <Text size="1" color="gray">Missing punch-out</Text>
                        </Flex>
                    </Flex>
                </Table.Cell>
            );
            return (
                <Table.Cell>
                    <Text size="2" color="gray">—</Text>
                </Table.Cell>
            );
        }

        case 'punch_details':
            return (
                <Table.Cell>
                    <Flex align="center" gap="2">
                        <ClockIcon style={{ color: 'var(--gray-9)', width: 14, flexShrink: 0 }} />
                        <Flex direction="column" gap="0">
                            <Text size="2" weight="medium">
                                {attendance.punch_count || 0} punch{(attendance.punch_count || 0) !== 1 ? 'es' : ''}
                            </Text>
                            {attendance.complete_punches === attendance.punch_count && attendance.punch_count > 0
                                ? <Text size="1" color="green">All complete</Text>
                                : attendance.punch_count > 0
                                    ? <Text size="1" color="amber">{attendance.complete_punches} complete</Text>
                                    : null
                            }
                        </Flex>
                    </Flex>
                </Table.Cell>
            );

        case 'actions':
            return (
                <Table.Cell>
                    <Flex gap="2" align="center">
                        {attendance.punches && attendance.punches.length > 0 ? (
                            attendance.punches.map((p, idx) => (
                                <Flex key={p.id} gap="1" align="center">
                                    <Tooltip content={`History Punch ${idx + 1}`}>
                                        <Button
                                            size="1"
                                            variant="ghost"
                                            color="gray"
                                            style={{ cursor: 'pointer' }}
                                            onClick={() => onHistory(p.id)}
                                        >
                                            <CounterClockwiseClockIcon style={{ width: 14, height: 14 }} />
                                        </Button>
                                    </Tooltip>
                                    <Tooltip content={`Delete Punch ${idx + 1}`}>
                                        <Button
                                            size="1"
                                            variant="ghost"
                                            color="red"
                                            style={{ cursor: 'pointer' }}
                                            onClick={() => onDelete(p.id)}
                                        >
                                            <TrashIcon style={{ width: 14, height: 14 }} />
                                        </Button>
                                    </Tooltip>
                                </Flex>
                            ))
                        ) : (
                            attendance.id && !String(attendance.id).startsWith('user-') && (
                                <Flex gap="1" align="center">
                                    <Tooltip content="History">
                                        <Button
                                            size="1"
                                            variant="ghost"
                                            color="gray"
                                            style={{ cursor: 'pointer' }}
                                            onClick={() => onHistory(attendance.id)}
                                        >
                                            <CounterClockwiseClockIcon style={{ width: 14, height: 14 }} />
                                        </Button>
                                    </Tooltip>
                                    <Tooltip content="Mark Absent">
                                        <Button
                                            size="1"
                                            variant="ghost"
                                            color="red"
                                            style={{ cursor: 'pointer' }}
                                            onClick={() => onDelete(attendance.id)}
                                        >
                                            <TrashIcon style={{ width: 14, height: 14 }} />
                                        </Button>
                                    </Tooltip>
                                </Flex>
                            )
                        )}
                    </Flex>
                </Table.Cell>
            );

        default:
            return <Table.Cell><Text size="2" color="gray">—</Text></Table.Cell>;
    }
};

/* ── main ─────────────────────────────────────────────────── */

const DailyTimesheetTab = ({
    selectedDate,
    onDateChange,
    isActive = true,
    departments = [],
    designations = [],
}) => {
    const { auth, url } = usePage().props;

    const canViewAll = auth.permissions?.includes('attendance.view')   || false;
    const canManage  = auth.permissions?.includes('attendance.correct') || auth.permissions?.includes('attendance.create') || false;
    const canCorrect = auth.permissions?.includes('attendance.correct') || auth.permissions?.includes('attendance.delete') || false;
    const canExport  = auth.permissions?.includes('attendance.export') || canManage;
    const isAdminView = canViewAll && url !== '/attendance-employee';
    const isMobile = useMediaQuery('(max-width: 767px)');

    // Zustand store for shared state
    const { employeeQuery, setEmployeeQuery } = useAttendanceStore();

    /* state */
    const [updateMap,    setUpdateMap]    = useState(false);
    const [downloading,  setDownloading]  = useState('');
    const [sidebarOpen,  setSidebarOpen]  = useState(true);
    const [lastChecked,  setLastChecked]  = useState(null);
    const prevUpdateRef = useRef(null);

    // Pagination state
    const [currentPage,  setCurrentPage]  = useState(1);
    const [perPage,      setPerPage]      = useState(20);

    // Range + filter state (Log mode)
    const [toDate, setToDate] = useState(selectedDate);
    const [preset, setPreset] = useState('today');
    const isGlobalUser = auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Administrator') || auth?.roles?.includes('HR Manager');
    const userDeptId = auth?.user?.department_id;
    const isNonGlobalManager = !isGlobalUser && userDeptId !== null && auth?.roles?.includes('Department Manager');

    const [deptFilter, setDeptFilter] = useState(isNonGlobalManager ? String(userDeptId) : '');
    const [desigFilter, setDesigFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

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
    });

    const { data: presentUsersData, isLoading: isLoadingPresent, refetch: refetchPresent } = usePresentUsers(
        selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null
    );

    const { data: absentUsersData, isLoading: isLoadingAbsent, refetch: refetchAbsent } = useAbsentUsers(
        selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null
    );

    // Live updates: when anyone punches (mobile/web) or is marked present for this date,
    // another user's dashboard refetches presence within ~1s. The actor's own change is
    // filtered out (their mutation already refetched). Past-date views only react to that date.
    const signalDate = selectedDate ? dayjs(selectedDate).format('YYYY-MM-DD') : null;
    useRealtimeSignals({
        path: isActive && signalDate ? `attendance/${signalDate}` : null,
        selfActorId: auth?.user?.id ?? null,
        onSignal: () => { refetchPresent(); refetchAbsent(); refetchTimesheet(); },
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
    const isMutating = updateTimeCorrection.isPending || markAsPresent.isPending || deleteAttendanceCorrection.isPending || exportDailyTimesheet.isPending;

    // Derived state from React Query data
    const attendances = dailyTimesheetData?.attendances || [];
    const totalRows = dailyTimesheetData?.total || 0;
    const lastPage = dailyTimesheetData?.last_page || 1;
    const presentUsers = presentUsersData?.attendances || [];
    const absentUsers = absentUsersData?.absent_users || [];
    const offUsers = absentUsersData?.off_users || [];
    const upcomingUsers = absentUsersData?.upcoming_users || [];
    const upcomingVisible = absentUsersData?.upcoming_visible ?? true;
    const leaves = absentUsersData?.leaves || [];
    const isWeekend = absentUsersData?.is_weekend || false;
    const isLoaded = !isLoadingTimesheet && !isLoadingPresent && !isLoadingAbsent;
    const error = null; // React Query handles errors automatically

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

    /* fetch functions using React Query */
    const fetchPresent = useCallback((page = currentPage, force = false) => {
        if (force) {
            refetchTimesheet();
        }
    }, [currentPage, refetchTimesheet]);

    const fetchAbsent = useCallback(() => {
        refetchAbsent();
    }, [refetchAbsent]);

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
                await Promise.all([refetchTimesheet(), refetchAbsent()]);
            }
            setLastChecked(new Date());
        } catch { /* silent */ }
    }, [selectedDate, refetchTimesheet, refetchAbsent]);

    useEffect(() => {
        setCurrentPage(1);
    }, [selectedDate, toDate, employeeQuery, perPage, deptFilter, desigFilter, statusFilter]);

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

    const getUserLeave = (uid) => leaves.find(l => String(l.user_id) === String(uid));

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
    const handleMarkAsPresent = useCallback(async (user, date) => {
        setMarkingId(user.id);
        try {
            await markAsPresent.mutateAsync({
                userId: user.id,
                date: dayjs(date).format('YYYY-MM-DD'),
            });
            await Promise.all([refetchTimesheet(), refetchAbsent()]);
        } catch (e) {
            const msg = e.response?.data?.message || 'Failed to mark as present.';
            showToast.error(msg);
        } finally {
            setMarkingId(null);
        }
    }, [refetchTimesheet, refetchAbsent, markAsPresent]);

    /* ── render ─────────────────────────────────────────────── */
    return (
        <Box>
            {/* Let our scroll wrapper be the sole scroll container so the sticky header pins correctly */}
            <style>{`.attn-daily-scroll :where(.rt-TableRootTable){overflow:visible}`}</style>
            {/* Toolbar */}
            <Flex
                justify="between"
                align="center"
                gap="3"
                mb="4"
                wrap="wrap"
            >
                {/* left: preset + (custom range) + search + per-page */}
                <Flex gap="3" align="center" wrap="wrap">
                    {isAdminView && (
                        <TextField.Root
                            size="2"
                            placeholder="Search employee…"
                            value={employeeQuery}
                            onChange={e => setEmployeeQuery(e.target.value)}
                            style={{ width: 200 }}
                        >
                            <TextField.Slot>
                                <MagnifyingGlassIcon />
                            </TextField.Slot>
                        </TextField.Root>
                    )}

                    <Select.Root value={preset} onValueChange={applyPreset}>
                        <Select.Trigger size="2" style={{ width: 130 }} />
                        <Select.Content>
                            {RANGE_PRESETS.map(p => (
                                <Select.Item key={p.value} value={p.value}>{p.label}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>

                    {preset === 'custom' && (
                        <DateTimePicker
                            mode="dateRange"
                            size="2"
                            clearable={false}
                            presets={false}
                            placeholder="Select date range…"
                            value={{
                                start: dayjs(selectedDate).format('YYYY-MM-DD'),
                                end: dayjs(toDate).format('YYYY-MM-DD'),
                            }}
                            onChange={({ start, end }) => {
                                if (start) onDateChange({ target: { value: start } });
                                if (end) setToDate(end);
                                setCurrentPage(1);
                            }}
                        />
                    )}

                    {isAdminView && rangeMode && (
                        <>
                            <Select.Root value={statusFilter || 'all'} onValueChange={v => { setStatusFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                                <Select.Trigger size="2" placeholder="Status" style={{ width: 130 }} />
                                <Select.Content>
                                    <Select.Item value="all">All statuses</Select.Item>
                                    <Select.Item value="present">Present</Select.Item>
                                    <Select.Item value="absent">Absent</Select.Item>
                                    <Select.Item value="on_leave">On Leave</Select.Item>
                                    <Select.Item value="incomplete">Incomplete</Select.Item>
                                    <Select.Item value="holiday">Holiday</Select.Item>
                                    <Select.Item value="day_off">Day Off</Select.Item>
                                </Select.Content>
                            </Select.Root>

                            {!isNonGlobalManager && (
                                <Select.Root value={deptFilter || 'all'} onValueChange={v => { setDeptFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                                    <Select.Trigger size="2" placeholder="Department" style={{ width: 150 }} />
                                    <Select.Content>
                                        <Select.Item value="all">All departments</Select.Item>
                                        {departments.map(d => (
                                            <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            )}

                            <Select.Root value={desigFilter || 'all'} onValueChange={v => { setDesigFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                                <Select.Trigger size="2" placeholder="Designation" style={{ width: 150 }} />
                                <Select.Content>
                                    <Select.Item value="all">All designations</Select.Item>
                                    {designations.map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </>
                    )}

                </Flex>

                {/* right: last updated + refresh + export */}
                <Flex gap="2" align="center" wrap="wrap">
                    {lastChecked && (
                        <Text size="1" color="gray">
                            Updated&nbsp;
                            {lastChecked.toLocaleTimeString('en-US', {
                                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
                            })}
                        </Text>
                    )}

                    {isAdminView && !rangeMode && (
                        <Tooltip content={sidebarOpen ? "Hide Absent Sidebar" : "Show Absent Sidebar"}>
                            <Button
                                size="2"
                                variant={sidebarOpen ? "soft" : "solid"}
                                color="red"
                                onClick={() => setSidebarOpen(!sidebarOpen)}
                            >
                                {sidebarOpen ? <ChevronRightIcon /> : <ChevronLeftIcon />}
                                <Text size="2" weight="medium">
                                    {sidebarOpen ? 'Hide Absents' : 'Show Absents'}
                                </Text>
                                {isLoaded && absentUsers?.length > 0 && (
                                    <Badge color="red" variant="solid" size="1" style={{ marginLeft: 4 }}>
                                        {absentUsers.length}
                                    </Badge>
                                )}
                            </Button>
                        </Tooltip>
                    )}

                    <Tooltip content="Refresh">
                        <Button
                            size="2"
                            variant="soft"
                            color="gray"
                            onClick={() => Promise.all([refetchTimesheet(), refetchAbsent()])}
                        >
                            <ReloadIcon />
                        </Button>
                    </Tooltip>

                    {canExport && (
                        <>
                            <Button
                                size="2"
                                variant="soft"
                                color="green"
                                disabled={!isLoaded || downloading !== ''}
                                onClick={() => exportFile('excel')}
                            >
                                <DownloadIcon />
                                {downloading === 'excel' ? 'Exporting…' : 'Excel'}
                            </Button>
                            <Button
                                size="2"
                                variant="soft"
                                color="red"
                                disabled={!isLoaded || downloading !== ''}
                                onClick={() => exportFile('pdf')}
                            >
                                <DownloadIcon />
                                {downloading === 'pdf' ? 'Exporting…' : 'PDF'}
                            </Button>
                        </>
                    )}
                </Flex>
            </Flex>




            {/* Body: range log table OR single-day table + sidebar */}
            {rangeMode ? (
                <Box style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden' }}>
                    <Box className="attn-daily-scroll" style={{ overflow: 'auto', maxHeight: 'calc(100vh - 300px)' }}>
                        <Table.Root size="2" variant="ghost" style={{ minWidth: 720 }}>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell style={STICKY_HEAD}>Date</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={STICKY_HEAD}>Employee</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={STICKY_HEAD}>Clock In</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={STICKY_HEAD}>Clock Out</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={STICKY_HEAD}>Work Hours</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell style={STICKY_HEAD}>Status</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {isLoadingLog ? (
                                    [...Array(8)].map((_, i) => (
                                        <Table.Row key={i}>
                                            {[...Array(6)].map((__, j) => (
                                                <Table.Cell key={j}><Skeleton width="80%" height="16px" /></Table.Cell>
                                            ))}
                                        </Table.Row>
                                    ))
                                ) : (logData?.rows?.length ?? 0) === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={6}>
                                            <Flex direction="column" align="center" py="9" gap="3">
                                                <ClockIcon style={{ color: 'var(--gray-7)', width: 36, height: 36 }} />
                                                <Text size="2" color="gray">No records for this range and filters</Text>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    logData.rows.map((row, idx) => (
                                        <Table.Row key={`${row.user_id}-${row.date}-${idx}`}>
                                            <Table.Cell><Text size="2">{dayjs(row.date).format('MMM D, YYYY')}</Text></Table.Cell>
                                            <Table.Cell><Text size="2" weight="medium">{row.employee_name}</Text></Table.Cell>
                                            <Table.Cell><Text size="2">{row.clock_in ? dayjs(row.clock_in).format('h:mm A') : '—'}</Text></Table.Cell>
                                            <Table.Cell><Text size="2">{row.clock_out ? dayjs(row.clock_out).format('h:mm A') : '—'}</Text></Table.Cell>
                                            <Table.Cell><Text size="2">{row.work_hours}</Text></Table.Cell>
                                            <Table.Cell><Badge variant="soft" color="gray">{row.remarks}</Badge></Table.Cell>
                                        </Table.Row>
                                    ))
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                    {((logData?.last_page ?? 1) > 1 || currentPage > 1) && !isLoadingLog && (
                        <TablePagination
                            pagination={{ currentPage, perPage, total: logData?.total ?? 0 }}
                            onPageChange={setCurrentPage}
                            onRowsPerPageChange={(v) => setPerPage(v)}
                            loading={isLoadingLog}
                        />
                    )}
                </Box>
            ) : error ? (
                <Flex align="center" gap="3" p="4" style={{ border: '1px solid var(--red-a7)', borderRadius: 'var(--radius-3)' }}>
                    <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 20, height: 20 }} />
                    <Text size="2" color="red">{error}</Text>
                </Flex>
            ) : (
                <Flex
                    direction={isMobile ? 'column' : 'row'}
                    gap="0"
                    style={{
                        position: 'relative',
                        border: '1px solid var(--gray-a4)',
                        borderRadius: 'var(--radius-3)',
                        overflow: 'hidden'
                    }}
                >

                    {/* Present table */}
                    <Box
                        style={{
                            flex: '1 1 0',
                            minWidth: 0,
                            overflow: 'hidden',
                            marginRight: (!isMobile && isAdminView && sidebarOpen) ? '320px' : 0
                        }}
                    >
                        <Box className="attn-daily-scroll" style={{ overflow: 'auto', maxHeight: 'calc(100vh - 300px)' }}>
                            <Table.Root size="2" variant="ghost" style={{ minWidth: 520 }}>
                                <Table.Header>
                                    <Table.Row>
                                        {columns.map(c => (
                                            <Table.ColumnHeaderCell key={c.uid} style={STICKY_HEAD}>
                                                <Text size="2" weight="medium">{c.name}</Text>
                                            </Table.ColumnHeaderCell>
                                        ))}
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {!isLoaded
                                        ? [...Array(6)].map((_, i) => (
                                            <Table.Row key={i}>
                                                {columns.map(c => (
                                                    <Table.Cell key={c.uid}>
                                                        <Skeleton width="80%" height="16px" />
                                                    </Table.Cell>
                                                ))}
                                            </Table.Row>
                                          ))
                                        : attendances.length === 0
                                            ? (
                                                <Table.Row>
                                                    <Table.Cell colSpan={columns.length}>
                                                        <Flex direction="column" align="center" py="9" gap="3">
                                                            <ClockIcon style={{ color: 'var(--gray-7)', width: 36, height: 36 }} />
                                                            <Text size="2" color="gray">No attendance records for this date</Text>
                                                        </Flex>
                                                    </Table.Cell>
                                                </Table.Row>
                                              )
                                            : attendances.map(a => (
                                                <Table.Row key={a.id || a.user_id}>
                                                    {columns.map(c => (
                                                        <Cell
                                                            key={c.uid}
                                                            attendance={a}
                                                            colUid={c.uid}
                                                            isAdminView={isAdminView}
                                                            canCorrect={canCorrect}
                                                            editingCell={editingCell}
                                                            onStartEdit={startEdit}
                                                            onCancelEdit={cancelEdit}
                                                            onSaveTime={handleTimeSave}
                                                            onDelete={handleDeleteAttendance}
                                                            onHistory={setHistoryId}
                                                        />
                                                    ))}
                                                </Table.Row>
                                              ))
                                    }
                                </Table.Body>
                            </Table.Root>
                        </Box>

                        {(lastPage > 1 || currentPage > 1) && isLoaded && (
                            <TablePagination
                                pagination={{ currentPage, perPage, total: totalRows }}
                                onPageChange={setCurrentPage}
                                onRowsPerPageChange={(v) => setPerPage(v)}
                                loading={!isLoaded}
                            />
                        )}
                    </Box>

                    {/* Absent sidebar — admin only */}
                    {isAdminView && sidebarOpen && (
                        <Box
                            style={{
                                width: isMobile ? '100%' : '320px',
                                flexShrink: 0,
                                ...(!isMobile ? {
                                    position: 'absolute',
                                    right: 0,
                                    top: 0,
                                    bottom: 0,
                                } : {})
                            }}
                        >
                            <AbsentSidebar
                                absentUsers={absentUsers}
                                offUsers={offUsers}
                                upcomingUsers={upcomingUsers}
                                upcomingVisible={upcomingVisible}
                                getUserLeave={getUserLeave}
                                isLoaded={isLoaded}
                                onMarkAsPresent={handleMarkAsPresent}
                                markingId={markingId}
                                selectedDate={selectedDate}
                                canManage={canManage}
                                isWeekend={isWeekend}
                                presentCount={totalRows}
                                leavesCount={leaves?.length || 0}
                            />
                        </Box>
                    )}
                </Flex>
            )}

            {/* ── Map: admin only, single-day mode only ───────── */}
            {isAdminView && !rangeMode && (
                <Box mt="4">
                    <ErrorBoundary>
                        <UserLocationsCard selectedDate={selectedDate} updateMap={updateMap} />
                    </ErrorBoundary>
                </Box>
            )}

            <AuditHistoryModal
                open={!!historyId}
                attendanceId={historyId}
                onOpenChange={() => setHistoryId(null)}
            />
        </Box>
    );
};

export default DailyTimesheetTab;

