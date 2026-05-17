import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import {
    Box, Flex, Text, Table, Badge, Avatar, Button,
    TextField, ScrollArea, Skeleton, Tooltip, Select,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon, CalendarIcon, ClockIcon, PersonIcon,
    ExclamationTriangleIcon, CheckCircledIcon, DownloadIcon,
    ChevronLeftIcon, ChevronRightIcon, MobileIcon,
    ReloadIcon, UpdateIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import axios from 'axios';
import AbsentSidebar from './AbsentSidebar';

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

/* ── cell renderer ────────────────────────────────────────── */

const Cell = ({ attendance, colUid, isAdminView }) => {
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

        case 'clockin_time':
            return (
                <Table.Cell>
                    <Flex direction="column" gap="1">
                        {attendance.punches?.length > 0
                            ? attendance.punches.filter(p => p.punch_in).map((p, i) => (
                                <Text key={i} size="2">
                                    <Text size="2" color="gray">{i + 1}.&nbsp;</Text>
                                    {formatTime(p.punch_in, attendance.date) || '—'}
                                </Text>
                              ))
                            : <Text size="2" color="gray">Not clocked in</Text>
                        }
                    </Flex>
                </Table.Cell>
            );

        case 'clockout_time':
            return (
                <Table.Cell>
                    <Flex direction="column" gap="1">
                        {attendance.punches?.length > 0
                            ? attendance.punches.map((p, i) => (
                                <Text key={i} size="2">
                                    <Text size="2" color="gray">{i + 1}.&nbsp;</Text>
                                    {p.punch_out
                                        ? formatTime(p.punch_out, attendance.date)
                                        : <Text size="2" color="gray">—</Text>
                                    }
                                </Text>
                              ))
                            : attendance.punchin_time
                                ? <Text size="2" color="gray">{isToday ? 'Still working' : 'Missing punch-out'}</Text>
                                : <Text size="2" color="gray">Not started</Text>
                        }
                    </Flex>
                </Table.Cell>
            );

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

/* ── pagination ───────────────────────────────────────────── */

const Pagination = ({ currentPage, lastPage, onChange }) => {
    const pages = Array.from({ length: lastPage }, (_, i) => i + 1)
        .filter(p => p === 1 || p === lastPage || Math.abs(p - currentPage) <= 1)
        .reduce((acc, p, i, arr) => {
            if (i > 0 && p - arr[i - 1] > 1) acc.push('…');
            acc.push(p);
            return acc;
        }, []);

    return (
        <Flex justify="center" align="center" gap="1" pt="3" pb="2" wrap="wrap">
            <Button
                variant="ghost" size="1" color="gray"
                disabled={currentPage <= 1}
                onClick={() => onChange(currentPage - 1)}
            >
                <ChevronLeftIcon />
            </Button>
            {pages.map((p, i) =>
                p === '…'
                    ? <Text key={`e${i}`} size="1" color="gray" style={{ padding: '0 2px' }}>…</Text>
                    : <Button
                        key={p}
                        size="1"
                        variant={p === currentPage ? 'solid' : 'ghost'}
                        color={p === currentPage ? 'accent' : 'gray'}
                        onClick={() => onChange(p)}
                      >{p}</Button>
            )}
            <Button
                variant="ghost" size="1" color="gray"
                disabled={currentPage >= lastPage}
                onClick={() => onChange(currentPage + 1)}
            >
                <ChevronRightIcon />
            </Button>
        </Flex>
    );
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
    const canExport  = auth.permissions?.includes('attendance.export') || canManage;
    const isAdminView = canViewAll && url !== '/attendance-employee';

    /* state */
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
    }, [selectedDate, perPage, employee, isAdminView, currentPage]);

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
        // eslint-disable-next-line
    }, [selectedDate, perPage, employee]);

    useEffect(() => {
        if (currentPage > 1) fetchPresent(currentPage);
        // eslint-disable-next-line
    }, [currentPage]);

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
                                                        <Cell key={c.uid} attendance={a} colUid={c.uid} isAdminView={isAdminView} />
                                                    ))}
                                                </Table.Row>
                                              ))
                                    }
                                </Table.Body>
                            </Table.Root>
                        </ScrollArea>

                        {lastPage > 1 && isLoaded && (
                            <Pagination currentPage={currentPage} lastPage={lastPage} onChange={setCurrentPage} />
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
        </Box>
    );
};

export default DailyTimesheetTab;
