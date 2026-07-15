import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Box, Flex, Text, Table, Badge, Avatar, Button,
    TextField, ScrollArea, Skeleton, Tooltip, Select,
} from '@radix-ui/themes';
import {
    CalendarIcon, ChevronLeftIcon, ChevronRightIcon,
    CheckCircledIcon, CrossCircledIcon, ExclamationTriangleIcon,
    PersonIcon, DownloadIcon, ReloadIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { showToast } from '@/utils/toastUtils';
import { handleExportResponse } from '@/utils/exportUtils';
import { useAttendanceStore } from '@/store/attendanceStore';
import * as useAttendanceQuery from '@/api/queries/useAttendanceQuery';
import MonthlySidebar from './Components/MonthlySidebar';
import TablePagination from '@/Components/TablePagination.jsx';

/* ── status map ───────────────────────────────────────────── */
const STATUS_MAP = {
    '√': { color: 'green',  bg: 'var(--green-a3)',  label: 'Present' },
    '▼': { color: 'red',    bg: 'var(--red-a3)',    label: 'Absent'  },
    '#': { color: 'amber',  bg: 'var(--amber-a3)',  label: 'Holiday' },
    '/': { color: 'blue',   bg: 'var(--blue-a3)',   label: 'Leave'   },
};
const getStatus = s => STATUS_MAP[s] || { color: 'gray', bg: 'var(--gray-a3)', label: 'No data' };

/* ── helpers ──────────────────────────────────────────────── */
const isWeekendDay = (date, weekendDays) => {
    if (!weekendDays?.length) {
        const d = dayjs(date).day();
        return d === 0 || d === 6;
    }
    return weekendDays.includes(dayjs(date).format('dddd').toLowerCase());
};

/* ════════════════════════════════════════════════════════════
   DESKTOP VIEW — horizontal scroll table
   ═══════════════════════════════════════════════════════════ */
const DesktopMonthTable = ({ rows, days, month, year, leaveTypes, leaveCounts, weekendDays, loading }) => {
    return (
        <ScrollArea scrollbars="both" style={{ maxHeight: 'calc(100vh - 320px)' }}>
            <Table.Root size="2" variant="surface" style={{ minWidth: Math.max(900, 200 + days.length * 38 + (leaveTypes?.length || 0) * 64) }}>
                <Table.Header>
                    <Table.Row>
                        {/* sticky employee col */}
                        <Table.ColumnHeaderCell
                            style={{
                                position: 'sticky', left: 0, zIndex: 3,
                                background: 'var(--gray-2)',
                                minWidth: 180, maxWidth: 220,
                            }}
                        >
                            <Flex align="center" gap="1">
                                <PersonIcon style={{ width: 13 }} />
                                <Text size="2">Employee</Text>
                            </Flex>
                        </Table.ColumnHeaderCell>

                        {/* day columns */}
                        {days.map(d => {
                            const date  = dayjs(`${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`);
                            const wknd  = isWeekendDay(date, weekendDays);
                            return (
                                <Table.ColumnHeaderCell
                                    key={d}
                                    style={{
                                        width: 36, textAlign: 'center', padding: '6px 2px',
                                        background: wknd ? 'var(--amber-a2)' : undefined,
                                    }}
                                >
                                    <Flex direction="column" align="center" gap="0">
                                        <Text size="1" weight="medium">{d}</Text>
                                        <Text size="1" color={wknd ? 'amber' : 'gray'} style={{ fontSize: 10 }}>
                                            {date.format('dd')}
                                        </Text>
                                    </Flex>
                                </Table.ColumnHeaderCell>
                            );
                        })}

                        {/* leave type columns */}
                        {(leaveTypes || []).map(t => (
                            <Table.ColumnHeaderCell key={t.type} style={{ width: 60, textAlign: 'center' }}>
                                <Text size="1">{t.type}</Text>
                            </Table.ColumnHeaderCell>
                        ))}
                    </Table.Row>
                </Table.Header>

                <Table.Body>
                    {loading
                        ? [...Array(7)].map((_, i) => (
                            <Table.Row key={i}>
                                <Table.Cell style={{ position: 'sticky', left: 0, background: 'var(--color-surface)' }}>
                                    <Flex align="center" gap="2">
                                        <Skeleton width="28px" height="28px" style={{ borderRadius: '50%' }} />
                                        <Skeleton width="100px" height="14px" />
                                    </Flex>
                                </Table.Cell>
                                {days.map(d => (
                                    <Table.Cell key={d} style={{ textAlign: 'center' }}>
                                        <Skeleton width="20px" height="20px" style={{ borderRadius: '50%', margin: 'auto' }} />
                                    </Table.Cell>
                                ))}
                                {(leaveTypes || []).map(t => (
                                    <Table.Cell key={t.type} style={{ textAlign: 'center' }}>
                                        <Skeleton width="24px" height="16px" style={{ margin: 'auto' }} />
                                    </Table.Cell>
                                ))}
                            </Table.Row>
                          ))
                        : rows.length === 0
                            ? (
                                <Table.Row>
                                    <Table.Cell colSpan={days.length + 1 + (leaveTypes?.length || 0)}>
                                        <Flex direction="column" align="center" py="9" gap="3">
                                            <CalendarIcon style={{ color: 'var(--gray-7)', width: 36, height: 36 }} />
                                            <Text size="2" color="gray">No attendance data for this month</Text>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                              )
                            : rows.map((row, ri) => (
                                <Table.Row key={row.user_id || ri}>
                                    {/* sticky name cell */}
                                    <Table.Cell
                                        style={{
                                            position: 'sticky', left: 0, zIndex: 1,
                                            background: 'var(--color-surface)',
                                            whiteSpace: 'nowrap',
                                            minWidth: 180, maxWidth: 220,
                                        }}
                                    >
                                        <Flex align="center" gap="2">
                                            <Avatar
                                                src={row.profile_image_url || row.profile_image}
                                                fallback={(row.name || '?').charAt(0).toUpperCase()}
                                                size="1" radius="full" style={{ flexShrink: 0 }}
                                            />
                                            <Text
                                                size="2"
                                                style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                                            >
                                                {row.name || 'Unknown'}
                                            </Text>
                                        </Flex>
                                    </Table.Cell>

                                    {/* day cells */}
                                    {days.map(d => {
                                        const dateKey = dayjs(`${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`).format('YYYY-MM-DD');
                                        const cell    = row[dateKey];
                                        const rawSt   = typeof cell === 'object' ? cell?.status || '▼' : '▼';
                                        const st      = getStatus(rawSt);
                                        const wknd    = isWeekendDay(dateKey, weekendDays);

                                        return (
                                            <Table.Cell
                                                key={d}
                                                style={{
                                                    textAlign: 'center',
                                                    padding: '4px 2px',
                                                    background: wknd ? 'var(--amber-a2)' : undefined,
                                                }}
                                            >
                                                <Tooltip
                                                    content={
                                                        <Flex direction="column" gap="1" style={{ fontSize: 12 }}>
                                                            <Text size="1" weight="medium">{dateKey}</Text>
                                                            <Text size="1">Status: {st.label}</Text>
                                                            {cell?.punch_in  && <Text size="1">In: {cell.punch_in}</Text>}
                                                            {cell?.punch_out && <Text size="1">Out: {cell.punch_out}</Text>}
                                                            {cell?.total_work_hours && <Text size="1">Hours: {cell.total_work_hours}</Text>}
                                                            {cell?.remarks  && <Text size="1">Remarks: {cell.remarks}</Text>}
                                                        </Flex>
                                                    }
                                                >
                                                    <Box
                                                        style={{
                                                            width: 24, height: 24, borderRadius: '50%',
                                                            background: st.bg,
                                                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                            margin: 'auto', cursor: 'default',
                                                        }}
                                                    >
                                                        <Text size="1" style={{ color: `var(--${st.color}-11)`, fontWeight: 700, lineHeight: 1 }}>
                                                            {rawSt === '√' ? '✓' : rawSt === '▼' ? '✗' : rawSt === '#' ? 'H' : 'L'}
                                                        </Text>
                                                    </Box>
                                                </Tooltip>
                                            </Table.Cell>
                                        );
                                    })}

                                    {/* leave count cells */}
                                    {(leaveTypes || []).map(t => {
                                        const count = leaveCounts?.[row.user_id]?.[t.type] || 0;
                                        return (
                                            <Table.Cell key={t.type} style={{ textAlign: 'center' }}>
                                                <Badge
                                                    color={count > 0 ? 'amber' : 'gray'}
                                                    variant={count > 0 ? 'soft' : 'outline'}
                                                    size="1"
                                                >
                                                    {count}
                                                </Badge>
                                            </Table.Cell>
                                        );
                                    })}
                                </Table.Row>
                              ))
                    }
                </Table.Body>
            </Table.Root>
        </ScrollArea>
    );
};

/* ════════════════════════════════════════════════════════════
   MOBILE VIEW — grid calendar per employee
   ═══════════════════════════════════════════════════════════ */
const MobileEmployeeCard = ({ row, days, month, year, leaveTypes, leaveCounts, weekendDays }) => {
    const [expanded, setExpanded] = useState(false);

    return (
        <Box
            mb="3"
            style={{
                border: '1px solid var(--gray-a4)',
                borderRadius: 'var(--radius-3)',
                overflow: 'hidden',
            }}
        >
            {/* employee header */}
            <Flex
                align="center"
                justify="between"
                px="3"
                py="2"
                style={{
                    background: 'var(--gray-2)',
                    borderBottom: '1px solid var(--gray-a3)',
                    cursor: 'pointer',
                }}
                onClick={() => setExpanded(e => !e)}
            >
                <Flex align="center" gap="2">
                    <Avatar
                        src={row.profile_image_url || row.profile_image}
                        fallback={(row.name || '?').charAt(0).toUpperCase()}
                        size="1" radius="full"
                    />
                    <Text size="2" weight="medium">{row.name || 'Unknown'}</Text>
                </Flex>
                <Flex align="center" gap="2">
                    {(leaveTypes || []).map(t => {
                        const count = leaveCounts?.[row.user_id]?.[t.type] || 0;
                        if (count === 0) return null;
                        return (
                            <Badge key={t.type} color="amber" variant="soft" size="1">
                                {t.type}: {count}
                            </Badge>
                        );
                    })}
                    <Text size="1" color="gray">{expanded ? '▲' : '▼'}</Text>
                </Flex>
            </Flex>

            {/* collapsible calendar grid */}
            {expanded && (
                <Box p="3">
                    {/* day-of-week header */}
                    <Flex mb="1">
                        {['Su','Mo','Tu','We','Th','Fr','Sa'].map(d => (
                            <Box key={d} style={{ flex: '1 1 0', textAlign: 'center' }}>
                                <Text size="1" color="gray" weight="medium">{d}</Text>
                            </Box>
                        ))}
                    </Flex>

                    {/* calendar grid */}
                    {(() => {
                        const firstDay = dayjs(`${year}-${String(month).padStart(2,'0')}-01`).day();
                        const totalCells = firstDay + days.length;
                        const weeks = Math.ceil(totalCells / 7);
                        return Array.from({ length: weeks }, (_, wi) => (
                            <Flex key={wi} mb="1">
                                {Array.from({ length: 7 }, (_, di) => {
                                    const cellIdx = wi * 7 + di;
                                    const dayNum  = cellIdx - firstDay + 1;
                                    if (dayNum < 1 || dayNum > days.length) {
                                        return <Box key={di} style={{ flex: '1 1 0' }} />;
                                    }
                                    const dateKey = dayjs(`${year}-${String(month).padStart(2,'0')}-${String(dayNum).padStart(2,'0')}`).format('YYYY-MM-DD');
                                    const cell    = row[dateKey];
                                    const rawSt   = typeof cell === 'object' ? cell?.status || '▼' : '▼';
                                    const st      = getStatus(rawSt);
                                    const wknd    = isWeekendDay(dateKey, weekendDays);

                                    return (
                                        <Box key={di} style={{ flex: '1 1 0', textAlign: 'center' }}>
                                            <Tooltip
                                                content={
                                                    <Flex direction="column" gap="1">
                                                        <Text size="1" weight="medium">{dateKey}</Text>
                                                        <Text size="1">Status: {st.label}</Text>
                                                        {cell?.punch_in  && <Text size="1">In: {cell.punch_in}</Text>}
                                                        {cell?.punch_out && <Text size="1">Out: {cell.punch_out}</Text>}
                                                    </Flex>
                                                }
                                            >
                                                <Flex
                                                    direction="column"
                                                    align="center"
                                                    style={{
                                                        padding: '2px',
                                                        borderRadius: 'var(--radius-1)',
                                                        background: wknd ? 'var(--amber-a2)' : st.bg,
                                                        cursor: 'default',
                                                    }}
                                                >
                                                    <Text size="1" color="gray" style={{ fontSize: 9, lineHeight: 1.2 }}>{dayNum}</Text>
                                                    <Text
                                                        size="1"
                                                        style={{
                                                            color: `var(--${st.color}-11)`,
                                                            fontWeight: 700,
                                                            fontSize: 10,
                                                            lineHeight: 1.2,
                                                        }}
                                                    >
                                                        {rawSt === '√' ? '✓' : rawSt === '▼' ? '✗' : rawSt === '#' ? 'H' : 'L'}
                                                    </Text>
                                                </Flex>
                                            </Tooltip>
                                        </Box>
                                    );
                                })}
                            </Flex>
                        ));
                    })()}
                </Box>
            )}
        </Box>
    );
};

const MobileMonthCalendar = ({ rows, days, month, year, leaveTypes, leaveCounts, weekendDays, loading }) => {
    if (loading) return (
        <Flex direction="column" gap="3">
            {[...Array(4)].map((_, i) => (
                <Box key={i} style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden' }}>
                    <Flex align="center" gap="2" p="3" style={{ background: 'var(--gray-2)' }}>
                        <Skeleton width="28px" height="28px" style={{ borderRadius: '50%' }} />
                        <Skeleton width="120px" height="14px" />
                    </Flex>
                </Box>
            ))}
        </Flex>
    );

    if (rows.length === 0) return (
        <Flex direction="column" align="center" py="9" gap="3">
            <CalendarIcon style={{ color: 'var(--gray-7)', width: 36, height: 36 }} />
            <Text size="2" color="gray">No attendance data for this month</Text>
        </Flex>
    );

    return (
        <Box>
            {rows.map((row, i) => (
                <MobileEmployeeCard
                    key={row.user_id || i}
                    row={row}
                    days={days}
                    month={month}
                    year={year}
                    leaveTypes={leaveTypes}
                    leaveCounts={leaveCounts}
                    weekendDays={weekendDays}
                />
            ))}
        </Box>
    );
};

/* ════════════════════════════════════════════════════════════
   MAIN COMPONENT
   ═══════════════════════════════════════════════════════════ */
const MonthlyCalendarTab = ({ selectedMonth, onMonthChange, departments = [] }) => {
    const { auth, url } = usePage().props;

    const canViewAll  = auth.permissions?.includes('attendance.view') || false;
    const isAdminView = canViewAll && url !== '/attendance-employee';

    // Zustand store for shared state
    const { employeeQuery, setEmployeeQuery } = useAttendanceStore();

    const isGlobalUser = auth.roles?.includes('Super Administrator') || auth.roles?.includes('Administrator') || auth.roles?.includes('HR Manager');
    const userDeptId = auth.user?.department_id;
    const isNonGlobalManager = !isGlobalUser && userDeptId !== null && auth.roles?.includes('Department Manager');

    const [selectedDepartmentId, setSelectedDepartmentId] = useState(isNonGlobalManager ? String(userDeptId) : 'all');
    const [downloading, setDownloading] = useState('');
    const [sidebarOpen, setSidebarOpen] = useState(true);

    // Pagination state
    const [currentPage, setCurrentPage] = useState(1);
    const [perPage, setPerPage] = useState(20);

    useEffect(() => {
        setCurrentPage(1);
    }, [selectedMonth, selectedDepartmentId, employeeQuery, perPage]);

    // React Query mutation
    const exportMonthlyCalendar = useAttendanceQuery.useExportMonthlyCalendar();
    const isMutating = exportMonthlyCalendar.isPending;

    /* responsive detection */
    const isMobile = useMediaQuery('(max-width: 767px)');

    /* derived */
    const yearNum  = dayjs(selectedMonth + '-01').year();
    const monthNum = dayjs(selectedMonth + '-01').month() + 1;
    const daysInMonth = dayjs(selectedMonth + '-01').daysInMonth();
    const days = Array.from({ length: daysInMonth }, (_, i) => i + 1);

    // React Query hooks
    const { data: monthlySummaryData, isLoading, refetch } = useAttendanceQuery.useMonthlySummary({
        currentMonth: monthNum,
        currentYear: yearNum,
        departmentId: selectedDepartmentId !== 'all' ? parseInt(selectedDepartmentId) : null,
        employee: employeeQuery,
        page: currentPage,
        perPage: perPage,
    });

    // Derived state from React Query data
    const rows = monthlySummaryData?.data || [];
    const totalRows = monthlySummaryData?.total || 0;
    const lastPage = monthlySummaryData?.last_page || 1;
    const leaveTypes = monthlySummaryData?.leaveTypes || [];
    const leaveCounts = monthlySummaryData?.leaveCounts || {};
    const settings = monthlySummaryData?.settings || null;
    const weekendDays = settings?.weekend_days || [];

    /* export */
    const exportFile = useCallback(async (type) => {
        setDownloading(type);
        try {
            const mime = type === 'pdf'   ? 'application/pdf'                    : undefined;
            const ext  = type === 'excel' ? 'xlsx'                               : 'pdf';
            const data = await exportMonthlyCalendar.mutateAsync({ month: selectedMonth, type });
            const defaultFilename = `Admin_Attendance_${selectedMonth}.${ext}`;
            await handleExportResponse(data, defaultFilename, mime, ext);
        } catch (err) {
            console.error('Export failed:', err);
            showToast.error(`Failed to export ${type}.`);
        } finally { setDownloading(''); }
    }, [selectedMonth, exportMonthlyCalendar]);

    /* month nav */
    const goMonth = (delta) => {
        const newMonth = dayjs(selectedMonth + '-01').add(delta, 'month').format('YYYY-MM');
        onMonthChange(newMonth);
    };

    /* ── render ─────────────────────────────────────────────── */
    return (
        <Box>
            {/* Toolbar */}
            <Flex justify="between" align="center" gap="3" mb="4" wrap="wrap">
                {/* left: month nav + search */}
                <Flex gap="2" align="center" wrap="wrap">
                    <Button variant="ghost" size="2" color="gray" onClick={() => goMonth(-1)}>
                        <ChevronLeftIcon />
                    </Button>

                    <TextField.Root
                        type="month"
                        size="2"
                        value={selectedMonth}
                        onChange={e => onMonthChange(e.target.value)}
                        style={{ width: 160 }}
                    >
                        <TextField.Slot>
                            <CalendarIcon />
                        </TextField.Slot>
                    </TextField.Root>

                    <Button variant="ghost" size="2" color="gray" onClick={() => goMonth(1)}>
                        <ChevronRightIcon />
                    </Button>

                    {isAdminView && (
                        <TextField.Root
                            size="2"
                            placeholder="Search employee…"
                            value={employeeQuery}
                            onChange={e => setEmployeeQuery(e.target.value)}
                            style={{ width: 200 }}
                        >
                            <TextField.Slot>
                                <PersonIcon />
                            </TextField.Slot>
                        </TextField.Root>
                    )}

                    {isAdminView && !isNonGlobalManager && departments?.length > 0 && (
                        <Select.Root
                            value={selectedDepartmentId || 'all'}
                            onValueChange={val => setSelectedDepartmentId(val)}
                        >
                            <Select.Trigger size="2" style={{ minWidth: 150 }} placeholder="All Departments" />
                            <Select.Content>
                                <Select.Item value="all">All Departments</Select.Item>
                                {departments.map(dept => (
                                    <Select.Item key={dept.id} value={String(dept.id)}>
                                        {dept.name}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    )}


                </Flex>

                {/* right: refresh + export (admin only) */}
                <Flex gap="2" align="center" wrap="wrap">
                    {isAdminView && (
                        <Tooltip content={sidebarOpen ? "Hide Stats Sidebar" : "Show Stats Sidebar"}>
                            <Button
                                size="2"
                                variant={sidebarOpen ? "soft" : "solid"}
                                color="blue"
                                onClick={() => setSidebarOpen(!sidebarOpen)}
                            >
                                {sidebarOpen ? <ChevronRightIcon /> : <ChevronLeftIcon />}
                                <Text size="2" weight="medium">
                                    {sidebarOpen ? 'Hide Stats' : 'Show Stats'}
                                </Text>
                            </Button>
                        </Tooltip>
                    )}

                    <Tooltip content="Refresh">
                        <Button size="2" variant="soft" color="gray" onClick={() => refetch()}>
                            <ReloadIcon />
                        </Button>
                    </Tooltip>
                    {isAdminView && (
                        <>
                            <Button
                                size="2" variant="soft" color="green"
                                disabled={isLoading || downloading !== ''}
                                onClick={() => exportFile('excel')}
                            >
                                <DownloadIcon />
                                {downloading === 'excel' ? 'Exporting…' : 'Excel'}
                            </Button>
                            <Button
                                size="2" variant="soft" color="red"
                                disabled={isLoading || downloading !== ''}
                                onClick={() => exportFile('pdf')}
                            >
                                <DownloadIcon />
                                {downloading === 'pdf' ? 'Exporting…' : 'PDF'}
                            </Button>
                        </>
                    )}
                </Flex>
            </Flex>

            {/* Legend */}
            <Flex gap="3" mb="3" wrap="wrap">
                {Object.entries(STATUS_MAP).map(([k, v]) => (
                    <Flex key={k} align="center" gap="1">
                        <Box style={{ width: 12, height: 12, borderRadius: '50%', background: v.bg, border: `1px solid var(--${v.color}-a6)` }} />
                        <Text size="1" color="gray">{v.label}</Text>
                    </Flex>
                ))}
                <Flex align="center" gap="1">
                    <Box style={{ width: 12, height: 12, borderRadius: '50%', background: 'var(--amber-a2)', border: '1px solid var(--amber-a6)' }} />
                    <Text size="1" color="gray">Weekend</Text>
                </Flex>
            </Flex>

            {/* Table / Cards */}
            <Flex direction={isMobile ? 'column' : 'row'} gap="3" align="stretch">
                <Box style={{ flex: '1 1 0', minWidth: 0 }}>
                    {isMobile
                        ? <MobileMonthCalendar
                            rows={rows} days={days} month={monthNum} year={yearNum}
                            leaveTypes={leaveTypes} leaveCounts={leaveCounts}
                            weekendDays={weekendDays} loading={isLoading}
                          />
                        : <DesktopMonthTable
                            rows={rows} days={days} month={monthNum} year={yearNum}
                            leaveTypes={leaveTypes} leaveCounts={leaveCounts}
                            weekendDays={weekendDays} loading={isLoading}
                          />
                    }
                    {(lastPage > 1 || currentPage > 1) && !isLoading && (
                        <Box mt="4">
                            <TablePagination
                                pagination={{ currentPage, perPage, total: totalRows }}
                                onPageChange={setCurrentPage}
                                onRowsPerPageChange={(v) => setPerPage(v)}
                                loading={isLoading}
                            />
                        </Box>
                    )}
                </Box>
                {isAdminView && sidebarOpen && (
                    <Box
                        style={{
                            width: isMobile ? '100%' : '320px',
                            flexShrink: 0,
                            borderRadius: 'var(--radius-3)',
                            border: '1px solid var(--gray-a4)',
                            overflow: 'hidden',
                        }}
                    >
                        <MonthlySidebar
                            rows={rows}
                            days={days}
                            monthNum={monthNum}
                            yearNum={yearNum}
                            leaveTypes={leaveTypes}
                            leaveCounts={leaveCounts}
                            isLoading={isLoading}
                        />
                    </Box>
                )}
            </Flex>
        </Box>
    );
};

export default MonthlyCalendarTab;

