import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import {
    Box, Flex, Text, Table, Badge, Avatar, Button,
    TextField, Skeleton, Tooltip, Select, Tabs, Spinner,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon, CalendarIcon, ClockIcon, PersonIcon,
    ExclamationTriangleIcon, CheckCircledIcon, DownloadIcon,
    MobileIcon, CrossCircledIcon,
    ReloadIcon, UpdateIcon, TrashIcon, CounterClockwiseClockIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { showToast } from '@/utils/toastUtils';
import { handleExportResponse } from '@/utils/exportUtils';
import AuditHistoryModal from './Components/AuditHistoryModal';
import UserLocationsCard from '@/Components/UserLocationsCard.jsx';
import AttendanceTimePicker from '@/Components/AttendanceTimePicker.jsx';
import DateTimePicker from '@/Components/DateTimePicker';
import TablePagination from '@/Components/TablePagination.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
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

/* ── stat band ───────────────────────────────────────────────── */

const STAT_META = [
    { key: 'present',  label: 'Present',   color: 'var(--green-9)'  },
    { key: 'absent',   label: 'Absent',    color: 'var(--red-9)'    },
    { key: 'upcoming', label: 'Upcoming',  color: 'var(--indigo-9)' },
    { key: 'off_leave',label: 'Off / Leave', color: 'var(--gray-8)' },
    { key: 'total',    label: 'Total',     color: 'var(--accent-9)' },
];

const StatBand = ({ counts = {}, isLoading = false }) => (
    <Flex gap="3" mb="4" wrap="wrap">
        {STAT_META.map(({ key, label, color }) => (
            <Box
                key={key}
                p="3"
                style={{
                    flex: '1 1 120px',
                    minWidth: 120,
                    borderRadius: 'var(--radius-3)',
                    background: 'var(--color-surface)',
                    border: '1px solid var(--gray-a4)',
                    boxShadow: 'var(--shadow-1)',
                    borderTop: `2px solid ${color}`,
                }}
            >
                <Text size="1" color="gray" style={{ display: 'block', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    {label}
                </Text>
                {isLoading
                    ? <Skeleton width="40px" height="24px" style={{ marginTop: 4 }} />
                    : <Text size="6" weight="bold" style={{ display: 'block', lineHeight: 1.2 }}>{counts[key] ?? 0}</Text>}
            </Box>
        ))}
    </Flex>
);

/* ── empty state ─────────────────────────────────────────────── */

const EmptyState = ({ icon: Icon = ClockIcon, text }) => (
    <Flex direction="column" align="center" justify="center" py="9" gap="3">
        <Icon style={{ color: 'var(--gray-7)', width: 36, height: 36 }} />
        <Text size="2" color="gray" align="center">{text}</Text>
    </Flex>
);

/* ── partition row card (absent / upcoming / off-leave tabs) ──── */

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
        <Box
            p="3"
            style={{
                borderRadius: 'var(--radius-3)',
                background: 'var(--color-surface)',
                border: '1px solid var(--gray-a4)',
                boxShadow: 'var(--shadow-1)',
                opacity: variant === 'off_leave' ? 0.92 : 1,
            }}
        >
            <Flex align="center" gap="3" wrap="wrap">
                <Avatar
                    src={user.profile_image_url || user.profile_image}
                    fallback={(user.name || '?').charAt(0).toUpperCase()}
                    size="2"
                    radius="full"
                    style={{ flexShrink: 0 }}
                />
                <Flex direction="column" gap="1" style={{ flex: 1, minWidth: 0 }}>
                    <Text size="2" weight="bold" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {user.name || 'Unknown'}
                    </Text>
                    {user.employee_id && (
                        <Text size="1" color="gray">#{user.employee_id}</Text>
                    )}

                    {/* variant-specific detail */}
                    {variant === 'absent' && (
                        <Flex align="center" gap="1" wrap="wrap">
                            {row.shift
                                ? <><CalendarIcon style={{ width: 12, height: 12, color: 'var(--red-9)' }} />
                                    <Text size="1" color="red" weight="medium">{shiftLabel(row.shift)}</Text></>
                                : <><PersonIcon style={{ width: 12, height: 12, color: 'var(--gray-8)' }} />
                                    <Text size="1" color="gray">Rostered, no punch</Text></>}
                        </Flex>
                    )}
                    {variant === 'upcoming' && (
                        <Flex align="center" gap="1" wrap="wrap">
                            <ClockIcon style={{ width: 12, height: 12, color: 'var(--indigo-9)' }} />
                            <Text size="1" color="indigo" weight="medium">
                                {shiftLabel(row.shift) || 'Scheduled'}
                            </Text>
                        </Flex>
                    )}
                    {variant === 'off_leave' && (
                        <Flex align="center" gap="1" wrap="wrap">
                            {row.kind === 'leave'
                                ? <Badge color="blue" variant="soft" size="1">
                                    On Leave{row.leave_type ? ` · ${row.leave_type}` : ''}
                                  </Badge>
                                : <Badge color="gray" variant="soft" size="1">Off</Badge>}
                        </Flex>
                    )}
                </Flex>

                {/* Mark present — absent tab only */}
                {variant === 'absent' && canManage && onMarkAsPresent && (
                    <Button
                        size="2"
                        variant="solid"
                        color="green"
                        style={{ cursor: 'pointer', flexShrink: 0 }}
                        disabled={markingId === uid}
                        onClick={() => onMarkAsPresent(user)}
                    >
                        {markingId === uid ? <Spinner size="1" /> : <CheckCircledIcon width={14} height={14} />}
                        <Text size="1" weight="bold">{markingId === uid ? 'Marking…' : 'Mark present'}</Text>
                    </Button>
                )}
            </Flex>
        </Box>
    );
};

/* Grid list wrapper for a partition tab (with loading + empty states). */
const PartitionList = ({ rows, variant, isLoading, emptyIcon, emptyText, onMarkAsPresent, markingId, canManage }) => {
    if (isLoading) {
        return (
            <Flex direction="column" gap="2">
                {[...Array(5)].map((_, i) => (
                    <Flex key={i} align="center" gap="3" p="3" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)' }}>
                        <Skeleton width="36px" height="36px" style={{ borderRadius: '50%', flexShrink: 0 }} />
                        <Flex direction="column" gap="1" style={{ flex: 1 }}>
                            <Skeleton width="40%" height="14px" />
                            <Skeleton width="25%" height="10px" />
                        </Flex>
                    </Flex>
                ))}
            </Flex>
        );
    }
    if (!rows || rows.length === 0) {
        return <EmptyState icon={emptyIcon} text={emptyText} />;
    }
    return (
        <Box
            style={{
                display: 'grid',
                gap: 'var(--space-2)',
                gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
            }}
        >
            {rows.map((row) => (
                <PartitionRow
                    key={row.user?.id ?? Math.random()}
                    row={row}
                    variant={variant}
                    onMarkAsPresent={onMarkAsPresent}
                    markingId={markingId}
                    canManage={canManage}
                />
            ))}
        </Box>
    );
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

    // Zustand store for shared state
    const { employeeQuery, setEmployeeQuery } = useAttendanceStore();

    /* state */
    const [updateMap,    setUpdateMap]    = useState(false);
    const [downloading,  setDownloading]  = useState('');
    const [activeTab,    setActiveTab]    = useState('present'); // present | absent | upcoming | offleave
    const [lastChecked,  setLastChecked]  = useState(null);
    const prevUpdateRef = useRef(null);

    // Pagination state
    const [currentPage,  setCurrentPage]  = useState(1);
    const [perPage,      setPerPage]      = useState(20);

    // Range + filter state (Log mode)
    const [toDate, setToDate] = useState(selectedDate);
    const [preset, setPreset] = useState('today');
    const isGlobalUser = auth?.isSuperAdmin || auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Administrator') || auth?.roles?.includes('HR Manager') || auth?.permissions?.includes('attendance.settings');
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
        deptFilter || undefined
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

    /* Present-tab / self-view table (shared by the admin Present tab and the
       non-admin single-day view). Full punch / correction / delete / history UI. */
    const presentTable = (
        <>
            <Box className="attn-daily-scroll" style={{ overflow: 'auto', maxHeight: 'calc(100vh - 360px)' }}>
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
                                            <EmptyState text="No attendance records for this date" />
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
        </>
    );

    /* Partition tab definitions — counts + badge colour drive the stat band too. */
    const partitionTabs = [
        { value: 'present',  label: 'Present',    count: counts.present ?? 0,   color: 'green',  icon: <CheckCircledIcon /> },
        { value: 'absent',   label: 'Absent',     count: counts.absent ?? 0,    color: 'red',    icon: <CrossCircledIcon /> },
        { value: 'upcoming', label: 'Upcoming',   count: counts.upcoming ?? 0,  color: 'indigo', icon: <ClockIcon /> },
        { value: 'offleave', label: 'Off / Leave',count: counts.off_leave ?? 0, color: 'gray',   icon: <CalendarIcon /> },
    ];

    const tabPanelStyle = { border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden' };
    const listPanelStyle = { border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', padding: 'var(--space-3)', maxHeight: 'calc(100vh - 360px)', overflow: 'auto' };

    /* ── render ─────────────────────────────────────────────── */
    return (
        <Box>
            {/* Let our scroll wrapper be the sole scroll container so the sticky header pins correctly */}
            <style>{`.attn-daily-scroll :where(.rt-TableRootTable){overflow:visible}`}</style>

            {/* ── Shared toolbar (identical for every tab) ─────────────── */}
            <Flex
                justify="between"
                align="center"
                gap="3"
                mb="4"
                wrap="wrap"
            >
                {/* left: search + date/preset + department + designation + (status in log mode) */}
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

                    {/* Department + Designation: shown for admin in BOTH single-day (tabs)
                        and range (log) mode. Department drives the partition endpoint. */}
                    {isAdminView && !isNonGlobalManager && (
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

                    {isAdminView && (
                        <Select.Root value={desigFilter || 'all'} onValueChange={v => { setDesigFilter(v === 'all' ? '' : v); setCurrentPage(1); }}>
                            <Select.Trigger size="2" placeholder="Designation" style={{ width: 150 }} />
                            <Select.Content>
                                <Select.Item value="all">All designations</Select.Item>
                                {designations.map(d => (
                                    <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    )}

                    {/* Status filter is only meaningful for the ranged log table. */}
                    {isAdminView && rangeMode && (
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

                    <Tooltip content="Refresh">
                        <Button
                            size="2"
                            variant="soft"
                            color="gray"
                            onClick={() => Promise.all([refetchTimesheet(), refetchPartition()])}
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

            {/* Body: (1) ranged log table, (2) non-admin self view, (3) admin tabbed partition */}
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
                                            <EmptyState text="No records for this range and filters" />
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
            ) : !isAdminView ? (
                /* Non-admin: their own single-day timesheet (no partition). */
                <Box style={tabPanelStyle}>
                    {presentTable}
                </Box>
            ) : (
                /* Admin single-day: stat band + Present / Absent / Upcoming / Off-Leave tabs */
                <>
                    <StatBand counts={counts} isLoading={isLoadingPartition} />

                    <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                        <Tabs.List style={{ marginBottom: 'var(--space-3)', overflowX: 'auto' }}>
                            {partitionTabs.map(t => (
                                <Tabs.Trigger key={t.value} value={t.value}>
                                    <Flex align="center" gap="2">
                                        {t.icon}
                                        <Text size="2" weight="medium" style={{ whiteSpace: 'nowrap' }}>{t.label}</Text>
                                        <Badge color={t.color} variant="soft" size="1" radius="full">{t.count}</Badge>
                                    </Flex>
                                </Tabs.Trigger>
                            ))}
                        </Tabs.List>

                        <Tabs.Content value="present">
                            <Box style={tabPanelStyle}>
                                {presentTable}
                            </Box>
                        </Tabs.Content>

                        <Tabs.Content value="absent">
                            <Box style={listPanelStyle}>
                                <PartitionList
                                    rows={absentRows}
                                    variant="absent"
                                    isLoading={isLoadingPartition}
                                    emptyIcon={CheckCircledIcon}
                                    emptyText="No absentees — everyone rostered has punched in."
                                    onMarkAsPresent={handleMarkAsPresent}
                                    markingId={markingId}
                                    canManage={canManage}
                                />
                            </Box>
                        </Tabs.Content>

                        <Tabs.Content value="upcoming">
                            <Box style={listPanelStyle}>
                                <PartitionList
                                    rows={upcomingRows}
                                    variant="upcoming"
                                    isLoading={isLoadingPartition}
                                    emptyIcon={ClockIcon}
                                    emptyText="No upcoming shifts — no one is scheduled to start later today."
                                />
                            </Box>
                        </Tabs.Content>

                        <Tabs.Content value="offleave">
                            <Box style={listPanelStyle}>
                                <PartitionList
                                    rows={offLeaveRows}
                                    variant="off_leave"
                                    isLoading={isLoadingPartition}
                                    emptyIcon={CalendarIcon}
                                    emptyText="No one is off or on leave for this date."
                                />
                            </Box>
                        </Tabs.Content>
                    </Tabs.Root>
                </>
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

