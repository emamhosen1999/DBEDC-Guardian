import React, { useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import App from '@/Layouts/App';
import {
    Box, Flex, Text, Card, Tabs, Separator,
} from '@radix-ui/themes';
import {
    ClockIcon, CalendarIcon, GearIcon,
} from '@radix-ui/react-icons';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import dayjs from 'dayjs';

import DailyTimesheetTab  from './DailyTimesheetTab';
import MonthlyCalendarTab from './MonthlyCalendarTab';
import SettingsTab        from './SettingsTab';

/* ── optional: mark-as-present modals (keep your existing) ── */
// import MarkAsPresentForm     from '@/Forms/MarkAsPresentForm';
// import BulkMarkAsPresentForm from '@/Forms/BulkMarkAsPresentForm';

const AttendancePage = ({ title }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    /* ── shared state ─────────────────────────────────────── */
    const [activeTab,    setActiveTab]    = useState('timesheet');
    const [selectedDate, setSelectedDate] = useState(dayjs().format('YYYY-MM-DD'));
    const [selectedMonth,setSelectedMonth]= useState(dayjs().format('YYYY-MM'));

    /* date change — keep daily and monthly in sync */
    const handleDateChange = useCallback(e => {
        const val = e.target.value;
        setSelectedDate(val);
        setSelectedMonth(dayjs(val).format('YYYY-MM'));
    }, []);

    const handleMonthChange = useCallback(val => {
        setSelectedMonth(val);
    }, []);

    /* permissions */
    const canSettings = auth.permissions?.includes('attendance.settings') || false;

    /* tab definitions */
    const tabs = [
        { value: 'timesheet', label: 'Daily Timesheet', icon: <ClockIcon />    },
        { value: 'monthly',   label: 'Monthly Calendar', icon: <CalendarIcon /> },
        ...(canSettings
            ? [{ value: 'settings', label: 'Settings', icon: <GearIcon /> }]
            : []
        ),
    ];

    /* ── render ───────────────────────────────────────────── */
    return (
        <>
            <Head title={title || 'Attendance'} />

            <Flex justify="center" p={{ initial: '3', md: '4' }}>
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>

                        {/* ══ PAGE HEADER ════════════════════════════════ */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', md: 'row' }}
                                align={{ initial: 'start', md: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                {/* title + subtitle */}
                                <Flex align="center" gap="3">
                                    <Box
                                        p={{ initial: '2', md: '3' }}
                                        style={{
                                            background: 'var(--accent-a3)',
                                            borderRadius: 'var(--radius-2)',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                        }}
                                    >
                                        <ClockIcon
                                            width={isDesktop ? 28 : 20}
                                            height={isDesktop ? 28 : 20}
                                            color="var(--accent-9)"
                                        />
                                    </Box>
                                    <Box>
                                        <Text
                                            size={{ initial: '4', sm: '5', md: '6' }}
                                            weight="bold"
                                            as="div"
                                        >
                                            Attendance
                                        </Text>
                                        <Text
                                            size={{ initial: '1', md: '2' }}
                                            color="gray"
                                            as="div"
                                        >
                                            Daily timesheet, monthly calendar and settings
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* header action buttons */}
                                <Flex align="center" gap="2" wrap="wrap">
                                    {/* context-aware date badge */}
                                    {activeTab !== 'settings' && (
                                        <Flex
                                            align="center"
                                            gap="1"
                                            px="2"
                                            py="1"
                                            style={{
                                                background: 'var(--gray-a3)',
                                                borderRadius: 'var(--radius-2)',
                                            }}
                                        >
                                            <CalendarIcon style={{ color: 'var(--gray-9)', width: 13 }} />
                                            <Text size="1" color="gray">
                                                {activeTab === 'monthly'
                                                    ? dayjs(selectedMonth + '-01').format('MMM YYYY')
                                                    : dayjs(selectedDate).format('MMM D, YYYY')}
                                            </Text>
                                        </Flex>
                                    )}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ══ TABS ═══════════════════════════════════════ */}
                        <Tabs.Root
                            value={activeTab}
                            onValueChange={setActiveTab}
                        >
                            <Tabs.List
                                style={{
                                    marginBottom: 'var(--space-4)',
                                }}
                            >
                                {tabs.map(tab => (
                                    <Tabs.Trigger key={tab.value} value={tab.value}>
                                        <Flex align="center" gap="2">
                                            {tab.icon}
                                            {!isMobile && tab.label}
                                        </Flex>
                                    </Tabs.Trigger>
                                ))}
                            </Tabs.List>

                            {/* ── Daily Timesheet Tab ───────────────────── */}
                            <Tabs.Content value="timesheet">
                                <Box mt="4">
                                    <DailyTimesheetTab
                                        selectedDate={selectedDate}
                                        onDateChange={handleDateChange}
                                        isActive={activeTab === 'timesheet'}
                                    />
                                </Box>
                            </Tabs.Content>

                            {/* ── Monthly Calendar Tab ──────────────────── */}
                            <Tabs.Content value="monthly">
                                <Box mt="4">
                                    <MonthlyCalendarTab
                                        selectedMonth={selectedMonth}
                                        onMonthChange={handleMonthChange}
                                    />
                                </Box>
                            </Tabs.Content>

                            {/* ── Settings Tab ──────────────────────────── */}
                            {canSettings && (
                                <Tabs.Content value="settings">
                                    <Box mt="4">
                                        <SettingsTab />
                                    </Box>
                                </Tabs.Content>
                            )}
                        </Tabs.Root>

                    </Card>
                </Box>
            </Flex>
        </>
    );
};

AttendancePage.layout = page => <App>{page}</App>;

export default AttendancePage;
