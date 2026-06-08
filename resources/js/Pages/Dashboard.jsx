import { Head } from '@inertiajs/react';
import React from 'react';
import { Box, Flex, Grid } from '@radix-ui/themes';

import UpdatesCards          from '@/Components/UpdatesCards.jsx';
import PunchStatusCard       from '@/Components/PunchStatusCard.jsx';
import App                   from '@/Layouts/App.jsx';
import ErrorBoundary         from '@/Components/ErrorBoundary/ErrorBoundary';

import GreetingBanner          from '@/Components/Dashboard/GreetingBanner.jsx';
import ClockWidget             from '@/Components/Dashboard/ClockWidget.jsx';
import WeatherWidget           from '@/Components/Dashboard/WeatherWidget.jsx';
import PersonalOverviewCard    from '@/Components/Dashboard/PersonalOverviewCard.jsx';
import AttendanceChartWidget   from '@/Components/Dashboard/AttendanceChartWidget.jsx';

import QuickLinksWidget        from '@/Components/Dashboard/QuickLinksWidget.jsx';
import PendingTasksWidget      from '@/Components/Dashboard/PendingTasksWidget.jsx';
import ProjectOverviewWidget   from '@/Components/Dashboard/ProjectOverviewWidget.jsx';
import UpcomingHolidaysWidget  from '@/Components/Dashboard/UpcomingHolidaysWidget.jsx';

export default function Dashboard({ auth }) {
    const perms = auth.permissions ?? [];
    const has   = (p)    => perms.includes(p);
    const hasAny = (ps)  => ps.some(p => has(p));
    const hasAll = (ps)  => ps.every(p => has(p));

    return (
        <>
            <Head title="Dashboard" />
            <Flex direction="column" gap={{ initial: '3', sm: '4' }} p={{ initial: '3', sm: '4', md: '5' }}>
                
                <Grid columns={{ initial: '1', md: '12' }} gap={{ initial: '3', md: '4' }}>
                    
                    {/* ── Row 1: Greeting + Clock + Weather ───────────────────── */}
                    <Box style={{ gridColumn: 'span 1' }} className="md-col-span-6">
                        <GreetingBanner user={auth.user} />
                    </Box>
                    <Box style={{ gridColumn: 'span 1' }} className="md-col-span-3">
                        <ClockWidget />
                    </Box>
                    <Box style={{ gridColumn: 'span 1' }} className="md-col-span-3">
                        <WeatherWidget />
                    </Box>

                    {/* ── Row 2: Punch + Personal Overview + Quick Links ──────── */}
                    {hasAll(['attendance.own.punch', 'attendance.own.view']) && (
                        <Box style={{ gridColumn: 'span 1' }} className="md-col-span-4">
                            <ErrorBoundary>
                                <PunchStatusCard handlePunchSuccess={() => {}} />
                            </ErrorBoundary>
                        </Box>
                    )}
                    <Box style={{ gridColumn: 'span 1' }} className="md-col-span-4">
                        <ErrorBoundary>
                            <PersonalOverviewCard permissions={perms} />
                        </ErrorBoundary>
                    </Box>
                    <Box style={{ gridColumn: 'span 1' }} className="md-col-span-4">
                        <ErrorBoundary>
                            <QuickLinksWidget permissions={perms} />
                        </ErrorBoundary>
                    </Box>

                    {/* ── Row 3: Attendance Charts + Project Overview ─────────── */}
                    {hasAny(['attendance.own.view', 'attendance.view']) && (
                        <Box style={{ gridColumn: 'span 1' }} className="md-col-span-8">
                            <ErrorBoundary>
                                <AttendanceChartWidget permissions={perms} />
                            </ErrorBoundary>
                        </Box>
                    )}
                    <Box style={{ gridColumn: 'span 1' }} className="md-col-span-4">
                        <ErrorBoundary>
                            <ProjectOverviewWidget permissions={perms} />
                        </ErrorBoundary>
                    </Box>

                    {/* ── Row 4: Leave Updates + Sidebar Widgets (Holidays, Tasks) */}
                    {has('core.updates.view') && (
                        <Box style={{ gridColumn: 'span 1' }} className="md-col-span-8">
                            <ErrorBoundary>
                                <UpdatesCards />
                            </ErrorBoundary>
                        </Box>
                    )}
                    <Flex direction="column" gap={{ initial: '3', md: '4' }} style={{ gridColumn: 'span 1' }} className="md-col-span-4">
                        <ErrorBoundary>
                            <PendingTasksWidget permissions={perms} />
                        </ErrorBoundary>
                        <ErrorBoundary>
                            <UpcomingHolidaysWidget permissions={perms} />
                        </ErrorBoundary>
                    </Flex>

                </Grid>

            </Flex>

            {/* Inject a tiny style tag to handle the md grid spans, since inline style gridColumn ignores Radix responsive props for custom spanning without proper CSS */}
            <style dangerouslySetInnerHTML={{__html: `
                @media (min-width: 768px) {
                    .md-col-span-3 { grid-column: span 3 !important; }
                    .md-col-span-4 { grid-column: span 4 !important; }
                    .md-col-span-6 { grid-column: span 6 !important; }
                    .md-col-span-8 { grid-column: span 8 !important; }
                }
            `}} />
        </>
    );
}

Dashboard.layout = (page) => <App>{page}</App>;
