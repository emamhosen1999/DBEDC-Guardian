import { Head } from '@inertiajs/react';
import React from 'react';
import { Box, Flex, Grid } from '@radix-ui/themes';

import UpdatesCards          from '@/Components/UpdatesCards.jsx';
import PunchStatusCard       from '@/Components/PunchStatusCard.jsx';
import App                   from '@/Layouts/App.jsx';

import GreetingBanner          from '@/Components/Dashboard/GreetingBanner.jsx';
import ClockWidget             from '@/Components/Dashboard/ClockWidget.jsx';
import WeatherWidget           from '@/Components/Dashboard/WeatherWidget.jsx';
import PersonalOverviewCard    from '@/Components/Dashboard/PersonalOverviewCard.jsx';
import AttendanceChartWidget   from '@/Components/Dashboard/AttendanceChartWidget.jsx';

export default function Dashboard({ auth }) {
    const perms = auth.permissions ?? [];
    const has   = (p)    => perms.includes(p);
    const hasAny = (ps)  => ps.some(p => has(p));
    const hasAll = (ps)  => ps.every(p => has(p));

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
                        <PunchStatusCard handlePunchSuccess={() => {}} />
                    )}
                    <PersonalOverviewCard permissions={perms} />
                </Grid>

                {/* ── Row 3: Attendance Charts ─────────────────────────────── */}
                {hasAny(['attendance.own.view', 'attendance.view']) && (
                    <AttendanceChartWidget permissions={perms} />
                )}

                {/* ── Row 4: Leave Updates ─────────────────────────────────── */}
                {has('core.updates.view') && (
                    <UpdatesCards />
                )}

            </Flex>
        </>
    );
}

Dashboard.layout = (page) => <App>{page}</App>;
