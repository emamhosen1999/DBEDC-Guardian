import { Panel } from '@/Components/ui/Panel';
import React, { useMemo, useState, useEffect } from 'react';
import { Box, Flex, Button, Text, TextField, Select, SegmentedControl, Callout, IconButton } from '@radix-ui/themes';
import { ReloadIcon, ChevronLeftIcon, ChevronRightIcon, CalendarIcon, PersonIcon, GridIcon, ExclamationTriangleIcon, Cross2Icon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import { useOptimisticMutation } from '@/api/useOptimisticMutation';
import RosterCalendar from './Components/RosterCalendar';
import RosterEmployeeView from './Components/RosterEmployeeView';
import RosterLegend from './Components/RosterLegend';
import CoveragePanel from './Components/CoveragePanel';
import CoverageRequirementsDialog from './Components/CoverageRequirementsDialog';
import RosterCellPopover from './Components/RosterCellPopover';
import { handleCellConflict } from './rosterCellConflict';
import { violationsFromResult, groupViolationsByEmployee, keyEmployeesById } from './complianceViolations';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';
import TablePagination from '@/Components/TablePagination.jsx';

export default function RosterTab({ month, onMonthChange, departments = [], isActive = true }) {
    const { employees = [], auth } = usePage().props;
    const qc = useQueryClient();
    const authUserId = auth?.user?.id ?? null;
    const [selectedCell, setSelectedCell] = useState(null); // { userId, date }
    const [popoverOpen, setPopoverOpen] = useState(false);
    const [cellViolations, setCellViolations] = useState(null); // { userId, date, blocked, violations }
    const [generateViolations, setGenerateViolations] = useState([]); // grouped-by-employee, dismissible

    const employeesById = useMemo(() => keyEmployeesById(employees), [employees]);

    const isGlobalUser = auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Administrator') || auth?.roles?.includes('HR Manager');
    const userDeptId = auth?.user?.department_id;
    const isNonGlobalManager = !isGlobalUser && userDeptId !== null && auth?.roles?.includes('Department Manager');

    const [selectedDepartmentId, setSelectedDepartmentId] = useState(isNonGlobalManager ? String(userDeptId) : 'all');
    const [employeeQuery, setEmployeeQuery] = useState('');
    const [coverageDialogOpen, setCoverageDialogOpen] = useState(false);
    const [viewMode, setViewMode] = useState('grid'); // 'grid' | 'employee'
    const [selectedEmployeeId, setSelectedEmployeeId] = useState(null);

    // Pagination states
    const [currentPage, setCurrentPage] = useState(1);
    const [perPage, setPerPage] = useState(20);

    useEffect(() => {
        setCurrentPage(1);
    }, [month, selectedDepartmentId, employeeQuery, perPage, viewMode]);

    const from = useMemo(() => dayjs(month + '-01').startOf('month').format('YYYY-MM-DD'), [month]);
    const to = useMemo(() => dayjs(month + '-01').endOf('month').format('YYYY-MM-DD'), [month]);

    const days = useMemo(() => {
        const out = [];
        let d = dayjs(from);
        const end = dayjs(to);
        while (d.isBefore(end) || d.isSame(end, 'day')) {
            out.push(d.format('YYYY-MM-DD'));
            d = d.add(1, 'day');
        }
        return out;
    }, [from, to]);

    const { data: shiftsData } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
        enabled: isActive,
    });
    const shifts = shiftsData?.shifts || [];

    const { data: locData } = useQuery({
        queryKey: ['work-locations'],
        queryFn: () => requestJson('get', '/attendance/work-locations'),
        enabled: isActive,
    });
    const workLocations = locData?.work_locations || [];

    const { data, isLoading, isFetching, refetch } = useQuery({
        queryKey: ['roster', from, to, selectedDepartmentId],
        queryFn: () => requestJson('get', '/attendance/roster', {
            params: {
                from,
                to,
                department_id: selectedDepartmentId !== 'all' ? Number(selectedDepartmentId) : null
            }
        }),
        enabled: isActive,
    });

    const roster = data?.roster || {};
    const holidays = data?.holidays || {};

    const selectedNotice = useMemo(() => {
        if (!selectedCell) return null;
        const { userId, date } = selectedCell;
        if (holidays[date]) return `Company holiday — ${holidays[date]}`;
        const lv = roster?.[userId]?.days?.[date]?.leave;
        if (lv?.status === 'approved') return `On approved leave — ${lv.type}`;
        if (lv?.status === 'pending') return `Has a pending leave request — ${lv.type}`;
        return null;
    }, [selectedCell, holidays, roster]);

    // Live cross-user updates: when ANOTHER user changes this month's roster,
    // refetch just this grid (the actor's own change is filtered out by selfActorId).
    useRealtimeSignals({
        path: `roster/${month}`,
        selfActorId: authUserId,
        onSignal: () => qc.invalidateQueries({ queryKey: ['roster', from, to, selectedDepartmentId] }),
    });

    const filteredEmployees = useMemo(() => {
        return employees.filter(e => {
            if (selectedDepartmentId !== 'all' && Number(e.department_id) !== Number(selectedDepartmentId)) {
                return false;
            }
            if (employeeQuery && !e.name?.toLowerCase().includes(employeeQuery.toLowerCase())) {
                return false;
            }
            return true;
        });
    }, [employees, selectedDepartmentId, employeeQuery]);

    const rows = useMemo(() => {
        return Object.entries(roster).filter(([userId, row]) => {
            if (!employeeQuery) return true;
            return row.name?.toLowerCase().includes(employeeQuery.toLowerCase());
        });
    }, [roster, employeeQuery]);

    const paginatedRows = useMemo(() => {
        return rows.slice((currentPage - 1) * perPage, currentPage * perPage);
    }, [rows, currentPage, perPage]);

    // Employee options + default selection for the per-employee calendar view.
    const employeeOptions = useMemo(
        () => rows.map(([id, row]) => ({ id: Number(id), name: row.name || 'Unknown' })),
        [rows]
    );
    const selectedIsAvailable = employeeOptions.some(e => String(e.id) === String(selectedEmployeeId));
    const effectiveEmployeeId = selectedIsAvailable
        ? String(selectedEmployeeId)
        : (employeeOptions[0] ? String(employeeOptions[0].id) : null);

    // Shift legend: only the shifts actually present in the current roster view.
    const legendShifts = useMemo(() => {
        const present = new Set();
        rows.forEach(([, row]) => Object.values(row.days || {}).forEach(c => {
            if (c && !c.off && c.code) present.add(c.code);
        }));
        return shifts.filter(s => present.has(s.code));
    }, [rows, shifts]);
    const hasHoliday = Object.keys(holidays).length > 0;

    const generate = useMutation({
        mutationFn: () => {
            const userIds = filteredEmployees.map(e => e.id);
            if (userIds.length === 0) {
                showToast.error('No employees match the current filters.');
                return Promise.reject(new Error('No employees selected'));
            }
            return requestJson('post', '/attendance/roster/generate', {
                data: { user_ids: userIds, from, to },
            });
        },
        onSuccess: (data) => {
            showToast.success('Roster generated.');
            qc.invalidateQueries({ queryKey: ['roster', from, to] });

            const violations = violationsFromResult(data);
            setGenerateViolations(violations.length > 0 ? groupViolationsByEmployee(violations, employeesById) : []);
        },
        onError: (err) => {
            showToast.error(err?.message || 'Failed to generate roster.');
        },
    });

    const updateCell = useOptimisticMutation({
        mutationFn: ({ userId, date, shiftId, workLocationId, expectedUpdatedAt }) => requestJson('put', '/attendance/roster/cell', {
            data: {
                user_id: Number(userId),
                date,
                shift_id: shiftId,
                work_location_id: workLocationId ?? null,
                expected_updated_at: expectedUpdatedAt ?? null,
            },
        }),
        queryKey: ['roster', from, to, selectedDepartmentId],
        updateFn: (oldData, { userId, date, shiftId, workLocationId }) => {
            if (!oldData || !oldData.roster) return oldData;

            const shift = shifts.find(s => s.id === shiftId);
            const updatedCell = {
                code: shift ? shift.code : null,
                color: shift ? shift.color : null,
                off: !shiftId,
                work_location_id: workLocationId ?? null,
            };

            const newRoster = { ...oldData.roster };
            if (newRoster[userId]) {
                const userRow = { ...newRoster[userId] };
                userRow.days = {
                    ...userRow.days,
                    [date]: updatedCell,
                };
                newRoster[userId] = userRow;
            }

            return {
                ...oldData,
                roster: newRoster,
            };
        },
        onSuccess: (data, variables) => {
            // Working-time compliance: never blocks here (a 200 always means the
            // write happened) but surfaces non-blocking warnings for review.
            const violations = violationsFromResult(data);
            if (violations.length > 0) {
                setCellViolations({ userId: variables.userId, date: variables.date, blocked: false, violations });
                setSelectedCell({ userId: variables.userId, date: variables.date });
                setPopoverOpen(true);
            }
        },
        onError: (err, variables) => {
            if (handleCellConflict(err, showToast)) return; // 409 → warning + auto-revert + revalidate

            // 422 = blocked by working-time compliance enforcement; the write did not happen.
            const violations = violationsFromResult(err);
            if (err?.status === 422 && violations.length > 0) {
                setCellViolations({ userId: variables.userId, date: variables.date, blocked: true, violations });
                setSelectedCell({ userId: variables.userId, date: variables.date });
                setPopoverOpen(true);
                return;
            }

            showToast.error(err?.message || 'Failed to update roster cell.');
        },
    });

    const handleCellClick = (userId, date) => {
        setSelectedCell({ userId, date });
        setCellViolations(null);
        setPopoverOpen(true);
    };

    const handlePick = (shiftId, workLocationId) => {
        if (!selectedCell) return;
        setPopoverOpen(false);
        setCellViolations(null);
        const { userId, date } = selectedCell;
        const expectedUpdatedAt = roster?.[userId]?.days?.[date]?.updated_at ?? null;
        updateCell.mutate({ userId, date, shiftId, workLocationId, expectedUpdatedAt });
        setSelectedCell(null);
    };

    const goMonth = (delta) => {
        const newMonth = dayjs(month + '-01').add(delta, 'month').format('YYYY-MM');
        onMonthChange?.(newMonth);
    };

    return (
        <Panel>
            {/* Toolbar */}
            <Flex justify="between" align="center" mb="4" wrap="wrap" gap="3">
                {/* Left: Month Nav, Search, Department */}
                <Flex gap="2" align="center" wrap="wrap">
                    <Button variant="ghost" size="2" color="gray" onClick={() => goMonth(-1)}>
                        <ChevronLeftIcon />
                    </Button>

                    <TextField.Root
                        type="month"
                        size="2"
                        value={month}
                        onChange={e => onMonthChange?.(e.target.value)}
                        style={{ width: 160 }}
                    >
                        <TextField.Slot>
                            <CalendarIcon />
                        </TextField.Slot>
                    </TextField.Root>

                    <Button variant="ghost" size="2" color="gray" onClick={() => goMonth(1)}>
                        <ChevronRightIcon />
                    </Button>

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

                    {!isNonGlobalManager && departments?.length > 0 && (
                        <Select.Root
                            value={selectedDepartmentId}
                            onValueChange={setSelectedDepartmentId}
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

                {/* Right: View toggle, Refresh & Generate */}
                <Flex gap="2" align="center" wrap="wrap">
                    <SegmentedControl.Root size="2" value={viewMode} onValueChange={setViewMode}>
                        <SegmentedControl.Item value="grid">
                            <Flex align="center" gap="1"><GridIcon /> Grid</Flex>
                        </SegmentedControl.Item>
                        <SegmentedControl.Item value="employee">
                            <Flex align="center" gap="1"><PersonIcon /> Per employee</Flex>
                        </SegmentedControl.Item>
                    </SegmentedControl.Root>
                    <Button
                        variant="soft" color="gray" size="2"
                        onClick={() => refetch()}
                        disabled={isFetching}
                    >
                        <ReloadIcon />
                        Refresh
                    </Button>
                    <Button variant="soft" color="gray" size="2" onClick={() => setCoverageDialogOpen(true)}>
                        Coverage rules
                    </Button>
                    <Button
                        size="2"
                        onClick={() => generate.mutate()}
                        disabled={generate.isPending || filteredEmployees.length === 0}
                    >
                        {generate.isPending ? 'Generating…' : 'Generate roster'}
                    </Button>
                </Flex>
            </Flex>

            {generateViolations.length > 0 && (
                <Callout.Root color="amber" size="1" mb="3">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Flex justify="between" align="start" gap="3" width="100%">
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">
                                Working-time compliance warnings ({generateViolations.reduce((n, g) => n + g.violations.length, 0)})
                            </Text>
                            <Flex direction="column" gap="1">
                                {generateViolations.map((group) => (
                                    <Box key={group.userId ?? group.name}>
                                        {group.violations.map((v, i) => (
                                            <Text key={i} as="div" size="1" color="gray">
                                                <Text weight="medium">{group.name}</Text>: {v.date} — {v.message}
                                            </Text>
                                        ))}
                                    </Box>
                                ))}
                            </Flex>
                        </Box>
                        <IconButton size="1" variant="ghost" color="gray" onClick={() => setGenerateViolations([])} aria-label="Dismiss">
                            <Cross2Icon />
                        </IconButton>
                    </Flex>
                </Callout.Root>
            )}

            {isLoading
                ? <Text size="2" color="gray">Loading roster…</Text>
                : (
                    <>
                        <RosterLegend shifts={legendShifts} showHoliday={hasHoliday} />
                        {viewMode === 'grid' && <CoveragePanel from={from} to={to} isActive={isActive} />}
                        <CoverageRequirementsDialog open={coverageDialogOpen} onOpenChange={setCoverageDialogOpen} />
                        {viewMode === 'employee' ? (
                            <RosterEmployeeView
                                employees={employeeOptions}
                                roster={Object.fromEntries(rows)}
                                days={days}
                                holidays={holidays}
                                shifts={shifts}
                                selectedUserId={effectiveEmployeeId}
                                onSelectUser={setSelectedEmployeeId}
                                onCellClick={handleCellClick}
                            />
                        ) : (
                            <>
                                <RosterCalendar
                                    roster={Object.fromEntries(paginatedRows)}
                                    days={days}
                                    holidays={holidays}
                                    shifts={shifts}
                                    onCellClick={handleCellClick}
                                />
                                {rows.length > perPage && (
                                    <Box mt="3">
                                        <TablePagination
                                            pagination={{ currentPage, perPage, total: rows.length }}
                                            onPageChange={setCurrentPage}
                                            onRowsPerPageChange={setPerPage}
                                            loading={isLoading}
                                        />
                                    </Box>
                                )}
                            </>
                        )}
                        <RosterCellPopover
                            open={popoverOpen}
                            onOpenChange={(o) => { if (!o) { setPopoverOpen(false); setCellViolations(null); } }}
                            anchor={<span />}
                            shifts={shifts}
                            notice={selectedNotice}
                            violations={
                                selectedCell && cellViolations
                                    && String(cellViolations.userId) === String(selectedCell.userId)
                                    && cellViolations.date === selectedCell.date
                                    ? cellViolations.violations
                                    : null
                            }
                            violationsBlocked={cellViolations?.blocked}
                            workLocations={workLocations}
                            selectedLocationId={selectedCell ? (roster?.[selectedCell.userId]?.days?.[selectedCell.date]?.work_location_id ?? null) : null}
                            onPick={handlePick}
                        />
                    </>
                )}
        </Panel>
    );
}
