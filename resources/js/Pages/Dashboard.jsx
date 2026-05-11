import { Head } from '@inertiajs/react';
import React, { useState } from 'react';
import { Box, Card, Flex, Grid } from '@radix-ui/themes';

import TimeSheetTable        from '@/Tables/TimeSheetTable.jsx';
import UserLocationsCard     from '@/Components/UserLocationsCard.jsx';
import UpdatesCards          from '@/Components/UpdatesCards.jsx';
import PunchStatusCard       from '@/Components/PunchStatusCard.jsx';
import App                   from '@/Layouts/App.jsx';

import GreetingBanner          from '@/Components/Dashboard/GreetingBanner.jsx';
import ClockWidget             from '@/Components/Dashboard/ClockWidget.jsx';
import WeatherWidget           from '@/Components/Dashboard/WeatherWidget.jsx';
import PersonalOverviewCard    from '@/Components/Dashboard/PersonalOverviewCard.jsx';
import AttendanceChartWidget   from '@/Components/Dashboard/AttendanceChartWidget.jsx';

export default function Dashboard({ auth }) {
    const [updateMap,       setUpdateMap]       = useState(false);
    const [updateTimeSheet, setUpdateTimeSheet] = useState(false);
    const [selectedDate,    setSelectedDate]    = useState(
        new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(new Date())
    );

    const perms = auth.permissions ?? [];
    const has   = (p)    => perms.includes(p);
    const hasAny = (ps)  => ps.some(p => has(p));
    const hasAll = (ps)  => ps.every(p => has(p));

    const handlePunchSuccess = () => {
        setUpdateMap(prev => !prev);
        setUpdateTimeSheet(prev => !prev);
    };

    const handleDateChange = (event) => {
        const d = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit',
        }).format(new Date(event.target.value));
        setSelectedDate(d);
        setUpdateTimeSheet(prev => !prev);
        setUpdateMap(prev => !prev);
    };

    return (
        <>
            <Head title="Dashboard" />
            <Flex direction="column" gap={{ initial: '3', sm: '4' }} p={{ initial: '3', sm: '4', md: '5' }}>

                {/* ── Row 1: Greeting + Clock + Weather ───────────────────── */}
                <Grid columns={{ initial: '2', md: '4' }} gap={{ initial: '3', md: '4' }}>
                    {/* Greeting spans 2 cols on md+ */}
                    <Box style={{ gridColumn: 'span 2' }}>
                        <GreetingBanner user={auth.user} />
                    </Box>
                    <ClockWidget />
                    <WeatherWidget />
                </Grid>

                {/* ── Row 2: Punch + Personal Overview ────────────────────── */}
                <Grid columns={{ initial: '1', md: '2' }} gap={{ initial: '3', md: '4' }}>
                    {hasAll(['attendance.own.punch', 'attendance.own.view']) && (
                        <PunchStatusCard handlePunchSuccess={handlePunchSuccess} />
                    )}
                    <PersonalOverviewCard permissions={perms} />
                </Grid>

                {/* ── Row 3: Attendance Charts ─────────────────────────────── */}
                {hasAny(['attendance.own.view', 'attendance.view']) && (
                    <AttendanceChartWidget permissions={perms} />
                )}

                {/* ── Row 4: Admin TimeSheet ───────────────────────────────── */}
                {hasAny(['attendance.view', 'employees.view']) && (
                    <Card>
                        <TimeSheetTable
                            selectedDate={selectedDate}
                            handleDateChange={handleDateChange}
                            updateTimeSheet={updateTimeSheet}
                        />
                    </Card>
                )}

                {/* ── Row 5: Admin Map ─────────────────────────────────────── */}
                {hasAny(['attendance.view', 'employees.view']) && (
                    <UserLocationsCard selectedDate={selectedDate} updateMap={updateMap} />
                )}

                {/* ── Row 6: Leave Updates ─────────────────────────────────── */}
                {has('core.updates.view') && (
                    <UpdatesCards />
                )}

            </Flex>
        </>
    );
}

Dashboard.layout = (page) => <App>{page}</App>;
