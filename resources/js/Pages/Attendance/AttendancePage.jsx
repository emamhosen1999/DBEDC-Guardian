import React, { useState, useCallback, lazy, Suspense } from 'react';
import { Head, usePage } from '@inertiajs/react';
import App from '@/Layouts/App';
import {
    Box, Flex, Text, Card, Tabs, Skeleton,
} from '@radix-ui/themes';
import {
    ClockIcon, CalendarIcon, GearIcon, LayersIcon, CheckCircledIcon,
    DesktopIcon,
} from '@radix-ui/react-icons';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import dayjs from 'dayjs';

import DailyTimesheetTab  from './DailyTimesheetTab';
const MonthlyCalendarTab = lazy(() => import('./MonthlyCalendarTab'));
const RosterTab          = lazy(() => import('./RosterTab'));
const SettingsTab        = lazy(() => import('./SettingsTab'));
const ApprovalsInbox     = lazy(() => import('./Components/ApprovalsInbox'));
const BiometricPanel     = lazy(() => import('@/Components/AdminUnified/BiometricPanel'));
import ErrorBoundary      from '@/Components/ErrorBoundary/ErrorBoundary';


/* ── optional: mark-as-present modals (keep your existing) ── */
// import MarkAsPresentForm     from '@/Forms/MarkAsPresentForm';
// import BulkMarkAsPresentForm from '@/Forms/BulkMarkAsPresentForm';

const AttendancePage = ({ title, departments = [], designations = [], devices = [] }) => {
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

    /* permissions — Super Administrator bypasses all gates unconditionally (matches the
       backend Gate::before bypass), even for abilities that don't exist as permission records. */
    const isSuperAdmin = auth.isSuperAdmin || false;
    const canSettings = isSuperAdmin || auth.permissions?.includes('attendance.settings') || false;
    const canManage   = isSuperAdmin || auth.permissions?.includes('attendance.manage')   || false;

    /* tab definitions */
    const tabs = [
        { value: 'timesheet', label: 'Daily Timesheet', icon: <ClockIcon />    },
        { value: 'monthly',   label: 'Monthly Calendar', icon: <CalendarIcon /> },
        ...(canManage
            ? [{ value: 'approvals', label: 'Approvals', icon: <CheckCircledIcon /> }]
            : []
        ),
        ...(canSettings
            ? [{ value: 'roster',   label: 'Roster',   icon: <LayersIcon /> }]
            : []
        ),
        ...(canSettings
            ? [{ value: 'settings', label: 'Settings', icon: <GearIcon /> }]
            : []
        ),
        ...(canSettings
            ? [{ value: 'biometric', label: 'Biometric Devices', icon: <DesktopIcon /> }]
            : []
        ),
    ];

    /* ── render ───────────────────────────────────────────── */
    return (
        <>
            <Head title={title || 'Attendance'} />

            <Flex justify="center" p={{ initial: '3', md: '4' }}>
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card style={{
                        }}>

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
                                    {activeTab !== 'settings' && activeTab !== 'biometric' && (
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



                        {/* ══ TABS ═══════════════════════════════════════ */}
                        <Tabs.Root
                            value={activeTab}
                            onValueChange={setActiveTab}
                        >
                            <Tabs.List
                                style={{
                                    marginBottom: 'var(--space-4)',
                                    overflowX: 'auto',
                                    display: 'flex',
                                    flexWrap: 'nowrap',
                                    scrollbarWidth: 'none', // hide scrollbar Firefox
                                    msOverflowStyle: 'none', // hide scrollbar IE/Edge
                                }}
                                className="hide-scrollbar"
                            >
                                {tabs.map(tab => (
                                    <Tabs.Trigger key={tab.value} value={tab.value}>
                                        <Flex align="center" gap="2">
                                            {tab.icon}
                                            <Text size="2" weight="medium" style={{ whiteSpace: 'nowrap' }}>
                                                {tab.label}
                                            </Text>
                                        </Flex>
                                    </Tabs.Trigger>
                                ))}
                            </Tabs.List>

                            {/* ── Daily Timesheet Tab ───────────────────── */}
                            <Box mt="4" style={{ display: activeTab === 'timesheet' ? 'block' : 'none' }}>
                                <ErrorBoundary>
                                    <DailyTimesheetTab
                                        selectedDate={selectedDate}
                                        onDateChange={handleDateChange}
                                        isActive={activeTab === 'timesheet'}
                                        departments={departments}
                                        designations={designations}
                                    />
                                </ErrorBoundary>
                            </Box>

                            {/* ── Monthly Calendar Tab ──────────────────── */}
                            <Box mt="4" style={{ display: activeTab === 'monthly' ? 'block' : 'none' }}>
                                <ErrorBoundary>
                                    <Suspense fallback={<Skeleton height="400px" />}>
                                        <MonthlyCalendarTab
                                            selectedMonth={selectedMonth}
                                            onMonthChange={handleMonthChange}
                                            departments={departments}
                                        />
                                    </Suspense>
                                </ErrorBoundary>
                            </Box>

                            {/* ── Approvals Tab ─────────────────────────── */}
                            {canManage && (
                                <Box mt="4" style={{ display: activeTab === 'approvals' ? 'block' : 'none' }}>
                                    <ErrorBoundary>
                                        <Suspense fallback={<Skeleton height="400px" />}>
                                            <ApprovalsInbox />
                                        </Suspense>
                                    </ErrorBoundary>
                                </Box>
                            )}

                            {/* ── Roster Tab ────────────────────────────── */}
                            {canSettings && (
                                <Box mt="4" style={{ display: activeTab === 'roster' ? 'block' : 'none' }}>
                                    <ErrorBoundary>
                                        <Suspense fallback={<Skeleton height="400px" />}>
                                            <RosterTab
                                                departments={departments}
                                                month={selectedMonth}
                                                onMonthChange={handleMonthChange}
                                                isActive={activeTab === 'roster'}
                                            />
                                        </Suspense>
                                    </ErrorBoundary>
                                </Box>
                            )}

                            {/* ── Settings Tab ──────────────────────────── */}
                            {canSettings && (
                                <Box mt="4" style={{ display: activeTab === 'settings' ? 'block' : 'none' }}>
                                    <ErrorBoundary>
                                        <Suspense fallback={<Skeleton height="400px" />}>
                                            <SettingsTab />
                                        </Suspense>
                                    </ErrorBoundary>
                                </Box>
                            )}

                            {/* ── Biometric Devices Tab ───────────────── */}
                            {canSettings && (
                                <Box mt="4" style={{ display: activeTab === 'biometric' ? 'block' : 'none' }}>
                                    <ErrorBoundary>
                                        <Suspense fallback={<Skeleton height="400px" />}>
                                            <BiometricPanel
                                                initialDevices={devices}
                                                employees={[]}
                                                isMobile={isMobile}
                                                tick={0}
                                                onCountChange={() => {}}
                                                onSetHeaderActions={() => {}}
                                                isActive={activeTab === 'biometric'}
                                            />
                                        </Suspense>
                                    </ErrorBoundary>
                                </Box>
                            )}
                        </Tabs.Root>

                    </Card>
                </Box>
            </Flex>
            <style dangerouslySetInnerHTML={{__html: `
                .hide-scrollbar::-webkit-scrollbar {
                    display: none;
                }
            `}} />
        </>
    );
};

AttendancePage.layout = page => <App>{page}</App>;

export default AttendancePage;
