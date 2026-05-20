import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import {
    Box, Flex, Text, Table, Badge, Avatar, Button,
    TextField, ScrollArea, Skeleton, Tooltip, Select,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon, CalendarIcon, ClockIcon, PersonIcon,
    ExclamationTriangleIcon, CheckCircledIcon, DownloadIcon,
    MobileIcon,
    ReloadIcon, UpdateIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import axios from 'axios';
import AbsentSidebar from './AbsentSidebar';
import UserLocationsCard from '@/Components/UserLocationsCard.jsx';
import AttendanceTimePicker from '@/Components/AttendanceTimePicker.jsx';
import TablePagination from '@/Components/TablePagination.jsx';

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
            const timeStr = formatTime(attendance.punchin_time, attendance.date);
            const punchinRecordId = attendance.punchin_id ?? attendance.id;
            const isEditing = editingCell?.attendanceId === punchinRecordId && editingCell?.field === 'punchin';
            
            return (
                <Table.Cell>
                    {canCorrect && isEditing ? (
                        <AttendanceTimePicker
                            value={attendance.punchin_time ? dayjs(attendance.punchin_time).format('HH:mm') : ''}
                            onSave={(time) => onSaveTime(punchinRecordId, 'punchin', time)}
                            onCancel={onCancelEdit}
                            label="In"
                        />
                    ) : (
                        <Flex align="center" gap="2">
                            <ClockIcon style={{ color: 'var(--green-9)', width: 14, flexShrink: 0 }} />
                            {canCorrect ? (
                                <Text 
                                    size="2" 
                                    weight="medium"
                                    style={{ cursor: 'pointer', color: 'var(--accent-11)' }}
                                    onClick={() => onStartEdit(punchinRecordId, 'punchin')}
                                >
                                    {timeStr || <Text size="2" color="gray">—</Text>}
                                </Text>
                            ) : (
                                <Text size="2" weight="medium">
                                    {timeStr || <Text size="2" color="gray">—</Text>}
                                </Text>
                            )}
                        </Flex>
                    )}
                </Table.Cell>
            );
        }

        case 'clockout_time': {
            const hasPunches = attendance.punches && attendance.punches.length > 0;
            const punchoutRecordId = attendance.punchout_id ?? attendance.id;
            const isEditing = editingCell?.attendanceId === punchoutRecordId && editingCell?.field === 'punchout';
            
            return (
                <Table.Cell>
                    {canCorrect && isEditing ? (
                        <AttendanceTimePicker
                            value={attendance.punchout_time ? dayjs(attendance.punchout_time).format('HH:mm') : ''}
                            onSave={(time) => onSaveTime(punchoutRecordId, 'punchout', time)}
                            onCancel={onCancelEdit}
                            label="Out"
                        />
                    ) : (
                        <Flex align="center" gap="2">
                            {hasPunches ? (
                                attendance.punches.map((p, idx) => (
                                    <Flex key={idx} align="center" gap="2">
                                        <ClockIcon style={{ color: 'var(--red-9)', width: 14, flexShrink: 0 }} />
                                        <Text size="2" weight="medium">
                                            {p.punch_out
                                                ? formatTime(p.punch_out, attendance.date)
                                                : <Text size="2" color="gray">—</Text>
                                            }
                                        </Text>
                                    </Flex>
                                  ))
                            ) : attendance.punchout_time ? (
                                <Text size="2" color="gray">{isToday ? 'Still working' : 'Missing punch-out'}</Text>
                            ) : (
                                <Text size="2" color="gray">Not started</Text>
                            )}
                        </Flex>
                    )}
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

    /* state */
    const [updateMap,    setUpdateMap]    = useState(false);
    const [attendances,  setAttendances]  = useState([]);
    const [absentUsers,  setAbsentUsers]  = useState([]);
    const [leaves,       setLeaves]       = useState([]);
    const [isLoaded,     setIsLoaded]     = useState(false);
    const [error,        setError]        = useState('');
    const [employee,     setEmployee]     = useState('');
    const [perPage,      setPerPage]      = useState(25);
    const [currentPage,  setCurrentPage]  = useState(1);
    const [totalRows,    setTotalRows]    = useState(0);
    const [lastPage,     setLastPage]     = useState(1);
    const [downloading,  setDownloading]  = useState('');
    const [lastChecked,  setLastChecked]  = useState(null);
    const prevUpdateRef = useRef(null);

    // Editing state for inline correction
    const [editingCell, setEditingCell] = useState(null); // { attendanceId, field: 'punchin' | 'punchout' }

    /* columns */
    const columns = useMemo(() => [
        ...(!isAdminView            ? [{ uid: 'date',            name: 'Date'       }] : []),
        ...(isAdminView             ? [{ uid: 'employee',        name: 'Employee'   }] : []),
        { uid: 'clockin_time',        name: 'Clock In'   },
        { uid: 'clockout_time',       name: 'Clock Out'  },
        { uid: 'production_time',     name: 'Work Hours' },
        { uid: 'punch_details',       name: 'Punches'    },
    ], [isAdminView]);

    /* fetch present */
    const fetchPresent = useCallback(async (page = currentPage, force = false) => {
        if (!selectedDate) return;
        const ep = isAdminView
            ? route('admin.getPresentUsersForDate')
            : route('getCurrentUserAttendanceForDate');
        try {
            setIsLoaded(false);
            const { data } = await axios.get(ep, {
                params: {
                    page,
                    perPage,
                    employee,
                    date: dayjs(selectedDate).format('YYYY-MM-DD'),
                    _t: force ? Date.now() : undefined,
                },
            });
            setAttendances(data.attendances || []);
            setTotalRows(data.total        || 0);
            setLastPage(data.last_page     || 1);
            setCurrentPage(data.current_page || 1);
            setError('');
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to load attendance data.');
            setAttendances([]);
        } finally {
            setIsLoaded(true);
        }
    }, [selectedDate, perPage, employee, isAdminView]);

    /* fetch absent */
    const fetchAbsent = useCallback(async () => {
        if (!isAdminView || !selectedDate) return;
        try {
            const { data } = await axios.get(route('admin.getAbsentUsersForDate'), {
                params: {
                    date: dayjs(selectedDate).format('YYYY-MM-DD'),
                    employee,
                    _t: Date.now(),
                },
            });
            setAbsentUsers(data.absent_users || []);
            setLeaves(data.leaves            || []);
        } catch {
            setAbsentUsers([]);
            setLeaves([]);
        }
    }, [isAdminView, selectedDate, employee]);

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
                await Promise.all([fetchPresent(currentPage, true), fetchAbsent()]);
            }
            setLastChecked(new Date());
        } catch { /* silent */ }
    }, [selectedDate, currentPage, fetchPresent, fetchAbsent]);

    useEffect(() => {
        setCurrentPage(1);
    }, [selectedDate, employee, perPage]);

    useEffect(() => {
        Promise.all([fetchPresent(1), fetchAbsent()]);
    }, [selectedDate, perPage, employee, fetchPresent, fetchAbsent]);

    useEffect(() => {
        if (currentPage > 1) fetchPresent(currentPage);
    }, [currentPage, fetchPresent]);

    useEffect(() => {
        const id = setInterval(checkUpdates, 5000);
        return () => clearInterval(id);
    }, [checkUpdates]);

    /* export */
    const exportFile = useCallback(async (type) => {
        setDownloading(type);
        try {
            const ep   = type === 'excel' ? route('attendance.exportExcel') : route('attendance.exportPdf');
            const mime = type === 'pdf'   ? 'application/pdf'               : undefined;
            const ext  = type === 'excel' ? 'xlsx'                          : 'pdf';
            const { data } = await axios.get(ep, { params: { date: selectedDate }, responseType: 'blob' });
            const blobUrl = window.URL.createObjectURL(new Blob([data], mime ? { type: mime } : undefined));
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = `Daily_Timesheet_${dayjs(selectedDate).format('YYYY_MM_DD')}.${ext}`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(blobUrl);
        } catch { alert(`Failed to download ${type}.`); }
        finally { setDownloading(''); }
    }, [selectedDate]);

    const getUserLeave = (uid) => leaves.find(l => String(l.user_id) === String(uid));

    // Handle time correction save
    const handleTimeSave = async (attendanceId, field, time) => {
        try {
            const formattedTime = dayjs(`${selectedDate} ${time}`).format('YYYY-MM-DD HH:mm:ss');

            if (!attendanceId) {
                alert('Cannot create new attendance record from this view. Please use mark as present.');
                return;
            }

            await axios.post(route('attendance.correct.update', attendanceId), {
                [field]: formattedTime,
            });

            setEditingCell(null);

            // Update the matching grouped row in local state — no full reload needed
            const timeField = field === 'punchin' ? 'punchin_time' : 'punchout_time';
            const idField   = field === 'punchin' ? 'punchin_id'   : 'punchout_id';
            setAttendances(prev =>
                prev.map(a => a[idField] === attendanceId ? { ...a, [timeField]: formattedTime } : a)
            );
        } catch (error) {
            console.error('Error updating attendance:', error);
            alert(error.response?.data?.error || 'Failed to update attendance');
        }
    };

    // Handle delete attendance
    const handleDeleteAttendance = async (attendanceId) => {
        if (!confirm('Are you sure you want to delete this attendance record?')) {
            return;
        }

        try {
            await axios.delete(route('attendance.correct.delete', attendanceId));
            await fetchPresent(currentPage, true);
        } catch (error) {
            console.error('Error deleting attendance:', error);
            alert('Failed to delete attendance record');
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
            await axios.post(route('attendance.mark-as-present'), {
                user_id: user.id,
                date: dayjs(date).format('YYYY-MM-DD'),
            });
            await Promise.all([fetchPresent(currentPage, true), fetchAbsent()]);
        } catch (e) {
            const msg = e.response?.data?.message || 'Failed to mark as present.';
            alert(msg);
        } finally {
            setMarkingId(null);
        }
    }, [fetchPresent, fetchAbsent, currentPage]);

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
                            value={employee}
                            onChange={e => setEmployee(e.target.value)}
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
                            onClick={() => Promise.all([fetchPresent(currentPage, true), fetchAbsent()])}
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
                        <Text size="2" color="gray">Absent: {isLoaded ? absentUsers.length : '…'}</Text>
                    </Flex>
                )}
                {isAdminView && (
                    <Flex align="center" gap="1">
                        <PersonIcon style={{ color: 'var(--accent-9)', width: 14 }} />
                        <Text size="2" color="gray">
                            Total: {isLoaded ? totalRows + absentUsers.length : '…'}
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
                            />
                        </Box>
                    )}
                </Flex>
            )}

            {/* ── Map: admin only ─────────────────────────────── */}
            {isAdminView && (
                <Box mt="4">
                    <UserLocationsCard selectedDate={selectedDate} updateMap={updateMap} />
                </Box>
            )}
        </Box>
    );
};

export default DailyTimesheetTab;
