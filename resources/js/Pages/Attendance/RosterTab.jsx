import React, { useMemo, useState } from 'react';
import { Box, Flex, Button, Text, Card } from '@radix-ui/themes';
import { ReloadIcon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import RosterCalendar from './Components/RosterCalendar';
import RosterCellPopover from './Components/RosterCellPopover';
import SwapApprovals from './Components/SwapApprovals';

export default function RosterTab({ month, departments = [], isActive = true }) {
    const qc = useQueryClient();
    const [selectedCell, setSelectedCell] = useState(null); // { userId, date }
    const [popoverOpen, setPopoverOpen] = useState(false);

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
        queryKey: ['roster', from, to],
        queryFn: () => requestJson('get', '/attendance/roster', { params: { from, to } }),
        enabled: isActive,
    });

    const roster = data?.roster || {};

    const generate = useMutation({
        mutationFn: () => requestJson('post', '/attendance/roster/generate', {
            data: { user_ids: Object.keys(roster).map(Number), from, to },
        }),
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
    };

    return (
        <Card>
            <Flex justify="between" align="center" mb="3" wrap="wrap" gap="2">
                <Text size="3" weight="bold">Roster — {dayjs(from).format('MMMM YYYY')}</Text>
                <Flex gap="2" align="center">
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
                        disabled={generate.isPending || Object.keys(roster).length === 0}
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
                            roster={roster}
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

            <SwapApprovals />
        </Card>
    );
}
