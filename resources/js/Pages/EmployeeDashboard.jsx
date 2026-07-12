import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Card, Grid, Badge, Avatar, Progress, Separator } from '@radix-ui/themes';
import { CalendarIcon, CheckCircledIcon, BackpackIcon } from '@radix-ui/react-icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';

import App from '@/Layouts/App.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PunchStatusCard from '@/Components/PunchStatusCard.jsx';
import AttendanceOverview from './Attendance/Components/AttendanceOverview';
import MyRequests from './Attendance/Components/MyRequests';
import SwapResponses from './Attendance/Components/SwapResponses';
import { requestJson } from '@/api/client';

export default function EmployeeDashboard() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const currentMonth = dayjs().format('YYYY-MM');

    // Fetch leave balances/stats
    const { data: leaveStats, isLoading: leavesLoading } = useQuery({
        queryKey: ['leaves-stats-dashboard'],
        queryFn: () => requestJson('get', '/leaves-stats'),
    });

    return (
        <>
            <Head title="Employee Dashboard" />
            <Box p={{ initial: '3', sm: '4', md: '5' }}>
                
                {/* ── Greeting Banner ── */}
                <Card mb="4" style={{ background: 'linear-gradient(135deg, var(--accent-a2) 0%, var(--accent-a1) 100%)', borderRadius: 16 }}>
                    <Flex gap="4" align="center" wrap="wrap">
                        <Avatar
                            size="6"
                            src={user?.profile_image_url}
                            fallback={user?.name?.substring(0, 2).toUpperCase()}
                            style={{ borderRadius: '50%', border: '2px solid var(--accent-9)' }}
                        />
                        <Box style={{ flex: 1, minWidth: 200 }}>
                            <Text size="2" color="gray" style={{ fontFamily: 'monospace' }}>
                                Welcome back,
                            </Text>
                            <Heading size="6" style={{ letterSpacing: '-0.02em' }}>
                                {user?.name}
                            </Heading>
                            <Flex gap="3" mt="1" align="center" wrap="wrap">
                                <Badge color="indigo" variant="soft">{user?.designation?.title || 'Team Member'}</Badge>
                                <Badge color="gray" variant="soft">{user?.department?.name || 'Operations'}</Badge>
                                <Text size="1" color="gray">ID: {user?.employee_id || 'N/A'}</Text>
                            </Flex>
                        </Box>
                        
                        <Box style={{ textAlign: 'right' }}>
                            <Heading size="5" style={{ fontFamily: 'monospace', letterSpacing: '-0.02em' }}>
                                {dayjs().format('dddd')}
                            </Heading>
                            <Text size="2" color="gray">
                                {dayjs().format('MMMM DD, YYYY')}
                            </Text>
                        </Box>
                    </Flex>
                </Card>

                {/* ── Attendance Overview (Stats for current month) ── */}
                <ErrorBoundary>
                    <AttendanceOverview mode="monthly" scope="self" month={currentMonth} />
                </ErrorBoundary>

                {/* ── Main Dashboard Grid ── */}
                <Grid columns={{ initial: '1', lg: '12' }} gap="4">
                    
                    {/* LEFT COLUMN: Punch Status Card & Shift details (6 spans) */}
                    <Box style={{ gridColumn: 'span 6' }}>
                        <Flex direction="column" gap="4">
                            
                            {/* Standalone GPS / Camera Punch Station */}
                            <ErrorBoundary>
                                <PunchStatusCard />
                            </ErrorBoundary>

                            {/* Peer Swap Responses (appears conditionally only if swap requests are pending) */}
                            <ErrorBoundary>
                                <SwapResponses />
                            </ErrorBoundary>

                            {/* Today's Shift/Roster Card */}
                            <Card className="cc-card" style={{ borderRadius: 16 }}>
                                <Flex align="center" gap="2" mb="3">
                                    <CalendarIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                    <Text size="3" weight="bold">Shift Schedule</Text>
                                </Flex>
                                <Text size="1" color="gray">Today's Shift:</Text>
                                <Heading size="4" mb="2" mt="1">09:00 AM - 06:00 PM (General Shift)</Heading>
                                <Badge color="jade" variant="soft">On-Time expected</Badge>
                            </Card>

                            {/* Assigned Daily Works & Tasks Widget */}
                            <Card className="cc-card" style={{ borderRadius: 16 }}>
                                <Flex justify="between" align="center" mb="3">
                                    <Flex align="center" gap="2">
                                        <CheckCircledIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                        <Text size="3" weight="bold">Assigned Daily Tasks</Text>
                                    </Flex>
                                </Flex>

                                <Flex direction="column" gap="2">
                                    <TaskListItem
                                        title="Initial Sub-grade Inspection - Section C"
                                        id="RFI-2026-0422"
                                        status="Pending"
                                        date="Today"
                                    />
                                    <TaskListItem
                                        title="Site Surveying and Alignment Check"
                                        id="RFI-2026-0423"
                                        status="Completed"
                                        date="Yesterday"
                                    />
                                    <TaskListItem
                                        title="Concrete Cube Strength Test Report Upload"
                                        id="RFI-2026-0425"
                                        status="Pending"
                                        date="Tomorrow"
                                    />
                                </Flex>
                            </Card>

                        </Flex>
                    </Box>

                    {/* RIGHT COLUMN: Leave Balances & Self Requests (6 spans) */}
                    <Box style={{ gridColumn: 'span 6' }}>
                        <Flex direction="column" gap="4">
                            
                            {/* Leave Balances Widget */}
                            <Card className="cc-card" style={{ borderRadius: 16 }}>
                                <Flex align="center" gap="2" mb="3">
                                    <BackpackIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                    <Text size="3" weight="bold">Time Off Summary</Text>
                                </Flex>

                                <Grid columns="3" gap="3">
                                    <LeaveTrackerItem
                                        title="Casual Leave"
                                        used={leaveStats?.balances?.casual?.used || 4}
                                        total={leaveStats?.balances?.casual?.total || 12}
                                        color="indigo"
                                    />
                                    <LeaveTrackerItem
                                        title="Sick Leave"
                                        used={leaveStats?.balances?.sick?.used || 2}
                                        total={leaveStats?.balances?.sick?.total || 10}
                                        color="teal"
                                    />
                                    <LeaveTrackerItem
                                        title="Earned Leave"
                                        used={leaveStats?.balances?.earned?.used || 5}
                                        total={leaveStats?.balances?.earned?.total || 15}
                                        color="amber"
                                    />
                                </Grid>
                            </Card>

                            {/* My Requests (Swaps, Regularizations, Overtime) */}
                            <Card className="cc-card" style={{ borderRadius: 16 }}>
                                <Flex align="center" gap="2" mb="3">
                                    <CalendarIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                    <Text size="3" weight="bold">My Requests</Text>
                                </Flex>
                                <ErrorBoundary>
                                    <MyRequests />
                                </ErrorBoundary>
                            </Card>

                        </Flex>
                    </Box>

                </Grid>
            </Box>
        </>
    );
}

// Helper components for visual styling
function LeaveTrackerItem({ title, used, total, color }) {
    const pct = Math.min(100, (used / total) * 10