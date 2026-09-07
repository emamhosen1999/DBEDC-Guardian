import React, { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Dialog, Select, Card, Tooltip } from '@radix-ui/themes';
import {
    CalendarDaysIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ClockIcon,
    WrenchScrewdriverIcon,
    ExclamationTriangleIcon,
    UserGroupIcon,
    MapPinIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function WorkOrderCalendar({ auth, workOrders = [], currentMonth = '' }) {
    useOperationsRealtimeRefresh();

    const [selectedDate, setSelectedDate] = useState(null);
    const [selectedWo, setSelectedWo] = useState(null);
    const [filterStatus, setFilterStatus] = useState('all');
    const [filterPriority, setFilterPriority] = useState('all');

    // Parse current month
    const currentDate = useMemo(() => {
        return currentMonth ? new Date(`${currentMonth}-01T00:00:00`) : new Date();
    }, [currentMonth]);

    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const monthName = currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });

    const handlePrevMonth = () => {
        const prev = new Date(year, month - 1, 1);
        const y = prev.getFullYear();
        const m = String(prev.getMonth() + 1).padStart(2, '0');
        router.get('/om/work-orders-calendar', { month: `${y}-${m}` }, { preserveState: true });
    };

    const handleNextMonth = () => {
        const next = new Date(year, month + 1, 1);
        const y = next.getFullYear();
        const m = String(next.getMonth() + 1).padStart(2, '0');
        router.get('/om/work-orders-calendar', { month: `${y}-${m}` }, { preserveState: true });
    };

    const handleToday = () => {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        router.get('/om/work-orders-calendar', { month: `${y}-${m}` }, { preserveState: true });
    };

    // Filter work orders
    const filteredOrders = useMemo(() => {
        return workOrders.filter(wo => {
            if (filterStatus !== 'all' && wo.status !== filterStatus) return false;
            if (filterPriority !== 'all' && wo.priority !== filterPriority) return false;
            return true;
        });
    }, [workOrders, filterStatus, filterPriority]);

    // Build calendar grid days
    const calendarDays = useMemo(() => {
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);

        // Day of week: 0 (Sun) to 6 (Sat). We'll align Monday as first day (0 = Mon, 6 = Sun)
        let startingDay = firstDayOfMonth.getDay() - 1;
        if (startingDay === -1) startingDay = 6;

        const daysInMonth = lastDayOfMonth.getDate();

        // Days from previous month
        const prevMonthLastDay = new Date(year, month, 0).getDate();
        const prevDays = [];
        for (let i = startingDay - 1; i >= 0; i--) {
            const d = prevMonthLastDay - i;
            const dateStr = `${month === 0 ? year - 1 : year}-${String(month === 0 ? 12 : month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            prevDays.push({ day: d, isCurrentMonth: false, dateStr });
        }

        // Current month days
        const curDays = [];
        for (let i = 1; i <= daysInMonth; i++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
            curDays.push({ day: i, isCurrentMonth: true, dateStr });
        }

        // Days from next month to fill complete grid of 35 or 42
        const totalSoFar = prevDays.length + curDays.length;
        const remaining = totalSoFar % 7 === 0 ? 0 : 7 - (totalSoFar % 7);
        const nextDays = [];
        for (let i = 1; i <= remaining; i++) {
            const dateStr = `${month === 11 ? year + 1 : year}-${String(month === 11 ? 1 : month + 2).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
            nextDays.push({ day: i, isCurrentMonth: false, dateStr });
        }

        return [...prevDays, ...curDays, ...nextDays];
    }, [year, month]);

    // Group work orders by date string (YYYY-MM-DD)
    const workOrdersByDate = useMemo(() => {
        const map = {};
        filteredOrders.forEach(wo => {
            const rawDate = wo.target_start_at || wo.target_end_at || wo.created_at;
            if (rawDate) {
                const dStr = rawDate.substring(0, 10);
                if (!map[dStr]) map[dStr] = [];
                map[dStr].push(wo);
            }
        });
        return map;
    }, [filteredOrders]);

    const priorityBadgeColor = (p) => ({
        critical: 'red',
        high: 'orange',
        medium: 'amber',
        low: 'blue',
    }[p] || 'gray');

    const statusBadgeColor = (s) => ({
        completed: 'green',
        in_progress: 'blue',
        approved: 'cyan',
        pending_review: 'amber',
        assigned: 'purple',
        rejected: 'red',
    }[s] || 'gray');

    const todayStr = new Date().toISOString().substring(0, 10);

    return (
        <App>
            <Head title="Work Orders Interactive Calendar - DBEDC O&M" />

            <Box p="6">
                {/* Header */}
                <Flex justify="between" align="center" mb="5" wrap="wrap" gap="3">
                    <Box>
                        <Flex align="center" gap="2">
                            <CalendarDaysIcon style={{ width: 28, height: 28, color: '#3b82f6' }} />
                            <Heading size="6">Maintenance Work Orders Calendar</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            Visual dispatch planner for routine, corrective, and preventive work orders
                        </Text>
                    </Box>

                    {/* Controls & Navigation */}
                    <Flex align="center" gap="3" wrap="wrap">
                        <Select.Root value={filterStatus} onValueChange={setFilterStatus}>
                            <Select.Trigger placeholder="Filter Status" />
                            <Select.Content>
                                <Select.Item value="all">All Statuses</Select.Item>
                                <Select.Item value="assigned">Assigned</Select.Item>
                                <Select.Item value="in_progress">In Progress</Select.Item>
                                <Select.Item value="pending_review">Pending Review</Select.Item>
                                <Select.Item value="completed">Completed</Select.Item>
                            </Select.Content>
                        </Select.Root>

                        <Select.Root value={filterPriority} onValueChange={setFilterPriority}>
                            <Select.Trigger placeholder="Filter Priority" />
                            <Select.Content>
                                <Select.Item value="all">All Priorities</Select.Item>
                                <Select.Item value="critical">Critical</Select.Item>
                                <Select.Item value="high">High</Select.Item>
                                <Select.Item value="medium">Medium</Select.Item>
                                <Select.Item value="low">Low</Select.Item>
                            </Select.Content>
                        </Select.Root>

                        <Flex align="center" gap="1" style={{ background: 'var(--gray-3)', borderRadius: 8, padding: 3 }}>
                            <Button variant="ghost" size="2" onClick={handlePrevMonth}>
                                <ChevronLeftIcon style={{ width: 16, height: 16 }} />
                            </Button>
                            <Text size="2" weight="bold" style={{ minWidth: 140, textAlign: 'center' }}>
                                {monthName}
                            </Text>
                            <Button variant="ghost" size="2" onClick={handleNextMonth}>
                                <ChevronRightIcon style={{ width: 16, height: 16 }} />
                            </Button>
                        </Flex>

                        <Button variant="soft" color="blue" size="2" onClick={handleToday}>
                            Today
                        </Button>
                    </Flex>
                </Flex>

                {/* Calendar Grid */}
                <Panel>
                    {/* Days of Week Header */}
                    <div style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
                        gap: '1px',
                        background: 'var(--gray-4)',
                        borderBottom: '1px solid var(--gray-5)',
                        textAlign: 'center',
                        fontWeight: 600,
                        fontSize: '0.85rem',
                        padding: '10px 0',
                    }}>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                        <div>Sun</div>
                    </div>

                    {/* Month Day Cells */}
                    <div style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
                        gap: '1px',
                        background: 'var(--gray-4)',
                    }}>
                        {calendarDays.map((item, idx) => {
                            const dayOrders = workOrdersByDate[item.dateStr] || [];
                            const isToday = item.dateStr === todayStr;

                            return (
                                <div
                                    key={idx}
                                    style={{
                                        minHeight: '125px',
                                        background: isToday ? 'var(--blue-2)' : item.isCurrentMonth ? 'var(--color-panel-solid)' : 'var(--gray-2)',
                                        padding: '8px',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        justifyContent: 'space-between',
                                        border: isToday ? '1px solid var(--blue-7)' : 'none',
                                    }}
                                >
                                    <Flex justify="between" align="center" mb="1">
                                        <Text
                                            size="2"
                                            weight={isToday ? 'bold' : 'medium'}
                                            style={{
                                                color: isToday ? '#2563eb' : item.isCurrentMonth ? 'inherit' : 'var(--gray-9)',
                                                borderRadius: '50%',
                                                width: 24,
                                                height: 24,
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'center',
                                                background: isToday ? 'var(--blue-4)' : 'transparent',
                                            }}
                                        >
                                            {item.day}
                                        </Text>
                                        {dayOrders.length > 0 && (
                                            <Badge size="1" color="gray" variant="soft">
                                                {dayOrders.length}
                                            </Badge>
                                        )}
                                    </Flex>

                                    {/* Work Orders in this day */}
                                    <Flex direction="column" gap="1" style={{ overflowY: 'auto', maxHeight: '90px' }}>
                                        {dayOrders.slice(0, 3).map(wo => (
                                            <div
                                                key={wo.id}
                                                onClick={() => setSelectedWo(wo)}
                                                style={{
                                                    background: 'var(--gray-3)',
                                                    borderLeft: `3px solid var(--${priorityBadgeColor(wo.priority)}-9)`,
                                                    padding: '3px 6px',
                                                    borderRadius: '4px',
                                                    fontSize: '0.72rem',
                                                    cursor: 'pointer',
                                                    lineHeight: 1.2,
                                                }}
                                            >
                                                <Flex justify="between" align="center">
                                                    <Text weight="bold" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                                        {wo.work_order_number}
                                                    </Text>
                                                    <Badge size="1" color={statusBadgeColor(wo.status)} variant="surface">
                                                        {wo.status?.replace(/_/g, ' ')}
                                                    </Badge>
                                                </Flex>
                                                <Text size="1" color="gray" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block' }}>
                                                    {wo.title || wo.description}
                                                </Text>
                                            </div>
                                        ))}
                                        {dayOrders.length > 3 && (
                                            <Text
                                                size="1"
                                                color="blue"
                                                style={{ cursor: 'pointer', textAlign: 'center', fontWeight: 600 }}
                                                onClick={() => setSelectedDate({ date: item.dateStr, orders: dayOrders })}
                                            >
                                                +{dayOrders.length - 3} more
                                            </Text>
                                        )}
                                    </Flex>
                                </div>
                            );
                        })}
                    </div>
                </Panel>

                {/* Day Details Modal */}
                <Dialog.Root open={!!selectedDate} onOpenChange={(open) => !open && setSelectedDate(null)}>
                    <Dialog.Content maxWidth="550px">
                        <Dialog.Title>Work Orders on {selectedDate?.date}</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            {selectedDate?.orders?.length} scheduled maintenance tasks
                        </Dialog.Description>
                        <Flex direction="column" gap="2" style={{ maxHeight: '400px', overflowY: 'auto' }}>
                            {selectedDate?.orders?.map(wo => (
                                <Card key={wo.id} style={{ cursor: 'pointer' }} onClick={() => { setSelectedWo(wo); setSelectedDate(null); }}>
                                    <Flex justify="between" align="center">
                                        <Text weight="bold" size="2">{wo.work_order_number} - {wo.title}</Text>
                                        <Badge color={priorityBadgeColor(wo.priority)}>{wo.priority}</Badge>
                                    </Flex>
                                    <Text size="2" color="gray" mt="1">{wo.description}</Text>
                                    <Flex gap="3" mt="2" align="center">
                                        <Badge color={statusBadgeColor(wo.status)} variant="soft">{wo.status}</Badge>
                                        {wo.assigned_crew && (
                                            <Flex align="center" gap="1">
                                                <UserGroupIcon style={{ width: 14, height: 14 }} />
                                                <Text size="1" color="gray">{wo.assigned_crew}</Text>
                                            </Flex>
                                        )}
                                    </Flex>
                                </Card>
                            ))}
                        </Flex>
                        <Flex justify="end" mt="3">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Work Order Detail Modal */}
                <Dialog.Root open={!!selectedWo} onOpenChange={(open) => !open && setSelectedWo(null)}>
                    <Dialog.Content maxWidth="600px">
                        <Dialog.Title>
                            <Flex justify="between" align="center">
                                <span>{selectedWo?.work_order_number}</span>
                                <Badge color={priorityBadgeColor(selectedWo?.priority)} size="2">
                                    {selectedWo?.priority?.toUpperCase()}
                                </Badge>
                            </Flex>
                        </Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            {selectedWo?.title || 'Maintenance Work Order'}
                        </Dialog.Description>

                        <Flex direction="column" gap="3">
                            <Box style={{ background: 'var(--gray-3)', padding: 12, borderRadius: 8 }}>
                                <Text size="2" weight="bold">Description:</Text>
                                <Text size="2" as="p" mt="1">{selectedWo?.description || 'No detailed description provided.'}</Text>
                            </Box>

                            <Flex gap="4" wrap="wrap">
                                <Box>
                                    <Text size="1" color="gray">Status</Text>
                                    <Badge color={statusBadgeColor(selectedWo?.status)} size="2" mt="1">
                                        {selectedWo?.status?.replace(/_/g, ' ')}
                                    </Badge>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray">Assigned Crew</Text>
                                    <Text size="2" weight="medium" mt="1">{selectedWo?.assigned_crew || 'Unassigned'}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray">Target Window</Text>
                                    <Text size="2" mt="1">
                                        {selectedWo?.target_start_at?.substring(0, 10) || 'N/A'} ~ {selectedWo?.target_end_at?.substring(0, 10) || 'N/A'}
                                    </Text>
                                </Box>
                            </Flex>

                            {selectedWo?.asset && (
                                <Box style={{ borderTop: '1px solid var(--gray-4)', paddingTop: 8 }}>
                                    <Text size="1" color="gray">Linked Expressway Asset</Text>
                                    <Text size="2" weight="bold">{selectedWo.asset.asset_code} — {selectedWo.asset.name}</Text>
                                    <Text size="1" color="gray">Chainage: {selectedWo.asset.chainage_start || 'N/A'}</Text>
                                </Box>
                            )}

                            {selectedWo?.defect && (
                                <Box style={{ borderTop: '1px solid var(--gray-4)', paddingTop: 8 }}>
                                    <Text size="1" color="gray">Originating Defect</Text>
                                    <Text size="2" weight="bold">{selectedWo.defect.defect_number} — {selectedWo.defect.title}</Text>
                                </Box>
                            )}
                        </Flex>

                        <Flex justify="end" gap="2" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                            <Button color="blue" onClick={() => router.get('/om/work-orders')}>
                                View in Work Orders
                            </Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
