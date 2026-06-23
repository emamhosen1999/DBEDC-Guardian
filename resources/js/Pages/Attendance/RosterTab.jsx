import React, { useMemo, useState } from 'react';
import { Box, Flex, Button, Text, Card, TextField, Select } from '@radix-ui/themes';
import { ReloadIcon, ChevronLeftIcon, ChevronRightIcon, CalendarIcon, PersonIcon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import RosterCalendar from './Components/RosterCalendar';
import RosterCellPopover from './Components/RosterCellPopover';

export default function RosterTab({ month, onMonthChange, departments = [], isActive = true }) {
    const { employees = [] } = usePage().props;
    const qc = useQueryClient();
    const [selectedCell, setSelectedCell] = useState(null); // { userId, date }
    const [popoverOpen, setPopoverOpen] = useState(false);
    const [selectedDepartmentId, setSelectedDepartmentId] = useState('all');
    const [employeeQuery, setEmployeeQuery] = useState('');

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
        onSuccess: () => {
            showToast.success('Roster generated.');
            qc.invalidateQueries({ queryKey: ['roster', from, to] });
        },
        onError: (err) => {
            showToast.error(err?.message || 'Failed to generate roster.');
        },
    });

    const updateCell = useMutation({
        mutationFn: ({ userId, date, shiftId }) => requestJson('put', '/attendance/roster/cell', {
            data: { user_id: Number(userId), date, shift_id: shiftId },
        }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['roster', from, to] }),
        onError: (err) => {
            showToast.error(err?.message || 'Failed to update roster cell.');
        },
    });

    const handleCellClick = (userId, date) => {
        setSelectedCell({ userId, date });
        setPopoverOpen(true);
    };

    const handlePick = (shiftId) => {
        if (!selectedCell) return;
        setPopoverOpen(false);
        updateCell.mutate({ userId: selectedCell.userId, date: selectedCell.date, shiftId });
        setSelectedCell(null);
    };

    const goMonth = (delta) => {
        const newMonth = dayjs(month + '-01').add(delta, 'month').format('YYYY-MM');
        onMonthChange?.(newMonth);
    };

    return (
        <Card>
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

                    {departments?.length > 0 && (
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

                {/* Right: Refresh & Generate */}
                <Flex gap="2" align="center" wrap="wrap">
                    <Button
                        variant="soft" color="gray" size="2"
                        onClick={() => refetch()}
                        disabled={isFetching}
                    >
                        <ReloadIcon />
                        Refresh
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

            {isLoading
                ? <Text size="2" color="gray">Loading roster…</Text>
                : (
                    <>
                        <RosterCalendar
                            roster={Object.fromEntries(rows)}
                            days={days}
                            onCellClick={handleCellClick}
                        />
                        <RosterCellPopover
                            open={popoverOpen}
                            onOpenChange={(o) => { if (!o) setPopoverOpen(false); }}
                            anchor={<span />}
                            shifts={shifts}
                            onPick={handlePick}
                        />
                    </>
                )}
        </Card>
    );
}
