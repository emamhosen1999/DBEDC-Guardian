import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import {
    Box, Flex, Text, Table, Badge, Avatar, Button,
    TextField, ScrollArea, Skeleton, Tooltip, Select,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon, CalendarIcon, ClockIcon, PersonIcon,
    ExclamationTriangleIcon, CheckCircledIcon, DownloadIcon,
    MobileIcon,
    ReloadIcon, UpdateIcon, TrashIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { showToast } from '@/utils/toastUtils';
import { handleExportResponse } from '@/utils/exportUtils';
import AbsentSidebar from './AbsentSidebar';
import UserLocationsCard from '@/Components/UserLocationsCard.jsx';
import AttendanceTimePicker from '@/Components/AttendanceTimePicker.jsx';
import TablePagination from '@/Components/TablePagination.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { useAttendanceStore } from '@/store/attendanceStore';
import { useDailyTimesheet, usePresentUsers, useAbsentUsers, useUpdateTimeCorrection, useMarkAsPresent, useDeleteAttendanceCorrection, useExportDailyTimesheet } from '@/api/queries/useAttendanceQuery';

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

/* ── table cell renderer ─────────────────────────────────────── */

const Cell = ({ attendance, colUid, isAdminView, canCorrect, editingCell, onStartEdit, onCancelEdit, onSaveTime, onDelete }) => {
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
                                <Tooltip content={`Delete Punch ${idx + 1}`} key={p.id}>
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
                            ))
                        ) : (
                            attendance.id && !String(attendance.id).startsWith('user-') && (
                                <Tooltip content="Delete Record">
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
}) => {
    const { auth, url } = usePage().props;

    const canViewAll = auth.permissions?.includes('attendance.view')   || false;
    const canManage  = auth.permissions?.includes('attendance.manage') || false;
    const canCorrect = auth.permissions?.includes('attendance.correct') || false;
    const canExport  = auth.permissions?.includes('attendance.export') || canManage;
    const isAdminView = canViewAll && url !== '/attendance-employee';

    // Zustand store for shared state
    const { employeeQuery, setEmployeeQuery } = useAttendanceStore();

    /* state */
    const [updateMap,    setUpdateMap]    = useState(false);
    const [downloading,  setDownloading]  = useState('');
    const [lastChecked,  setLastChecked]  = useState(null);
    const prevUpdateRef = useRef(null);

    // Pagination state
    const [currentPage,  setCurrentPage]  = useState(1);
    const [perPage,      setPerPage]      = useState(25);

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
    }, [selectedDate, employeeQuery, perPage]);

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
            const mime = type === 'pdf'   ? 'application/pdf'               : undefined;
            const ext  = type === 'excel' ? 'xlsx'                          : 'pdf';
            const data = await exportDailyTimesheet.mutateAsync({ date: selectedDate, type });
            const defaultFilename = `Daily_Timesheet_${dayjs(selectedDate).format('YYYY_MM_DD')}.${ext}`;
            await handleExportResponse(data, defaultFilename, mime, ext);
        } catch (err) {
            console.error('Export failed:', err);
            showToast.error(`Failed to download ${type}.`);
        } finally { setDownloading(''); }
    }, [selectedDate, exportDailyTimesheet]);

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

            setEditingCell(null);
            refetchTimesheet();
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
            {/* Toolbar */}
            <Flex
                justify="between"
                align="center"
                gap="3"
                mb="4"
                wrap="wrap"
            >
                {/* left: date + search + per-page */}
                <Flex gap="3" align="center" wrap="wrap">
                    <TextField.Root
                        type="date"
                        size="2"
                        value={dayjs(selectedDate).format('YYYY-MM-DD')}
                        onChange={onDateChange}
                        style={{ width: 160 }}
                    >
                        <TextField.Slot>
                            <CalendarIcon />
                        </TextField.Slot>
                    </TextField.Root>

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

                    <Select.Root
                        value={String(perPage)}
                        onValueChange={v => setPerPage(Number(v))}
                    >
                        <Select.Trigger size="2" style={{ width: 110 }} />
                        <Select.Content>
                            {[10, 25, 50, 100].map(n => (
                                <Select.Item key={n} value={String(n)}>{n} / page</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
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

            {/* Stats row */}
            <Flex align="center" gap="4" mb="3">
                <Flex align="center" gap="1">
                    <CheckCircledIcon style={{ color: 'var(--green-9)', width: 14 }} />
                    <Text size="2" color="gray">Present: {isLoaded ? totalRows : '…'}</Text>
                </Flex>
                {isAdminView && (
                    <Flex align="center" gap="1">
                        <ExclamationTriangleIcon style={{ color: 'var(--amber-9)', width: 14 }} />
                        <Text size="2" color="gray">Absent: {isLoaded ? (absentUsers?.length || 0) : '…'}</Text>
                    </Flex>
                )}
                {isAdminView && (
                    <Flex align="center" gap="1">
                        <PersonIcon style={{ color: 'var(--accent-9)', width: 14 }} />
                        <Text size="2" color="gray">
                            Total: {isLoaded ? totalRows + (absentUsers?.length || 0) : '…'}
                        </Text>
                    </Flex>
                )}
            </Flex>

            {/* Body: table + sidebar */}
            {error ? (
                <Flex align="center" gap="3" p="4" style={{ border: '1px solid var(--red-a7)', borderRadius: 'var(--radius-3)' }}>
                    <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 20, height: 20 }} />
                    <Text size="2" color="red">{error}</Text>
                </Flex>
            ) : (
                <Flex gap="0" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden', minHeight: 400 }}>

                    {/* Present table */}
                    <Box style={{ flex: '1 1 0', minWidth: 0, overflow: 'hidden' }}>
                        <ScrollArea scrollbars="both" style={{ maxHeight: 'calc(100vh - 340px)' }}>
                            <Table.Root size="2" variant="ghost" style={{ minWidth: 520 }}>
                                <Table.Header>
                                    <Table.Row>
                                        {columns.map(c => (
                                            <Table.ColumnHeaderCell key={c.uid}>
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
                                                        />
                                                    ))}
                                                </Table.Row>
                                              ))
                                    }
                                </Table.Body>
                            </Table.Root>
                        </ScrollArea>

                        {lastPage > 1 && isLoaded && (
                            <TablePagination
                                pagination={{ currentPage, perPage, total: totalRows }}
                                onPageChange={setCurrentPage}
                                onRowsPerPageChange={(v) => setPerPage(v)}
                                loading={!isLoaded}
                            />
                        )}
                    </Box>

                    {/* Absent sidebar — admin only */}
                    {isAdminView && (
                        <Box style={{ width: 260, flexShrink: 0 }}>
                            <AbsentSidebar
                                absentUsers={absentUsers}
                                getUserLeave={getUserLeave}
                                isLoaded={isLoaded}
                                onMarkAsPresent={handleMarkAsPresent}
                                markingId={markingId}
                                selectedDate={selectedDate}
                                canManage={canManage}
                                isWeekend={isWeekend}
                            />
                        </Box>
                    )}
                </Flex>
            )}

            {/* ── Map: admin only ─────────────────────────────── */}
            {isAdminView && (
                <Box mt="4">
                    <ErrorBoundary>
                        <UserLocationsCard selectedDate={selectedDate} updateMap={updateMap} />
                    </ErrorBoundary>
                </Box>
            )}
        </Box>
    );
};

export default DailyTimesheetTab;

