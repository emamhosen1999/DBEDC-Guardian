import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useState } from 'react';
import { Badge, Box, Flex, Grid, Heading, Separator, Skeleton, Text } from '@radix-ui/themes';
import {
    CheckCircledIcon, ClockIcon, CrossCircledIcon, ExclamationTriangleIcon,
    FileTextIcon, LapTimerIcon, PersonIcon, StackIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';

function StatBox({ icon: Icon, label, value, color = 'accent', loading }) {
    return (
        <Box 
            p={{ initial: '2', md: '3' }} 
            className="stat-box-interactive"
            style={{
                borderRadius: 'var(--radius-3)',
                background: 'var(--gray-a2)',
                border: '1px solid var(--gray-a4)',
                transition: 'all 0.22s cubic-bezier(0.4, 0, 0.2, 1)',
                cursor: 'default',
            }}
        >
            <Flex align="center" gap="2" mb="1">
                <Box style={{
                    padding: 5, borderRadius: 'var(--radius-2)',
                    background: `var(--${color}-a3)`, flexShrink: 0,
                }}>
                    <Icon style={{ color: `var(--${color}-9)`, display: 'block', width: 13, height: 13 }} />
                </Box>
                <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>{label}</Text>
            </Flex>
            {loading
                ? <Skeleton style={{ width: 44, height: 22, marginTop: 4 }} />
                : <Text size={{ initial: '3', md: '5' }} weight="bold">{value ?? '—'}</Text>
            }
        </Box>
    );
}

export default function PersonalOverviewCard({ permissions = [] }) {
    const [attendance, setAttendance] = useState(null);
    const [tasks, setTasks] = useState(null);
    const [loading, setLoading] = useState(true);

    const canViewAttendance = permissions.includes('attendance.own.view');
    const canViewTasks      = permissions.includes('daily-works.own.view') || permissions.includes('daily-works.view');
    const canViewLeaves     = permissions.includes('leave.own.view');

    useEffect(() => {
        const requests = [];

        if (canViewAttendance) {
            requests.push(
                axios.get(route('attendance.myMonthlyStats'))
                    .then(r => setAttendance(r.data?.data ?? null))
                    .catch(() => setAttendance(null))
            );
        }

        if (canViewTasks) {
            requests.push(
                axios.get(route('stats'))
                    .then(r => setTasks(r.data?.statistics ?? null))
                    .catch(() => setTasks(null))
            );
        }

        if (requests.length === 0) {
            setLoading(false);
            return;
        }

        Promise.allSettled(requests).finally(() => setLoading(false));
    }, [canViewAttendance, canViewTasks]);

    const att = attendance?.attendance;
    const meta = attendance?.meta;

    return (
        <Panel tinted style={{ height: '100%' }}>
            <style dangerouslySetInnerHTML={{__html: `
                .stat-box-interactive:hover {
                    background: var(--gray-a3) !important;
                    border-color: var(--accent-a5) !important;
                    transform: translateY(-1px);
                    box-shadow: var(--shadow-1);
                }
            `}} />
            <Flex direction="column" gap="2" style={{ height: '100%' }}>
                <Flex align="center" justify="between">
                    <Heading size={{ initial: '2', md: '3' }}>My Overview</Heading>
                    {meta?.month && (
                        <Badge variant="soft" color="gray" size="1">{meta.month}</Badge>
                    )}
                </Flex>

                {/* Attendance */}
                {canViewAttendance && (
                    <>
                        <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.06em' }}>
                            Attendance this month
                        </Text>
                        <Grid columns="2" gap={{ initial: '2', md: '2' }}>
                            <StatBox icon={CheckCircledIcon} label="Present"     value={att?.present}      color="green"  loading={loading} />
                            <StatBox icon={CrossCircledIcon} label="Absent"      value={att?.absent}       color="red"    loading={loading} />
                            <StatBox icon={ExclamationTriangleIcon} label="Late" value={att?.lateArrivals} color="amber"  loading={loading} />
                            <StatBox icon={LapTimerIcon}     label="On Leave"    value={att?.leaves}       color="blue"   loading={loading} />
                        </Grid>

                        {att?.percentage != null && (
                            <Flex align="center" gap="2" mt="-1">
                                <Box style={{
                                    flex: 1, height: 6,
                                    borderRadius: 'var(--radius-full)',
                                    background: 'var(--gray-a4)',
                                    overflow: 'hidden',
                                }}>
                                    <Box style={{
                                        width: `${att.percentage}%`,
                                        height: '100%',
                                        background: att.percentage >= 80 
                                            ? 'linear-gradient(90deg, var(--green-9) 0%, var(--green-11) 100%)' 
                                            : att.percentage >= 60 
                                            ? 'linear-gradient(90deg, var(--amber-9) 0%, var(--amber-11) 100%)' 
                                            : 'linear-gradient(90deg, var(--red-9) 0%, var(--red-11) 100%)',
                                        borderRadius: 'var(--radius-full)',
                                        transition: 'width 0.6s ease',
                                        boxShadow: '0 0 6px var(--accent-a3)',
                                    }} />
                                </Box>
                                <Text size="1" weight="bold" style={{ flexShrink: 0, minWidth: 36, color: att.percentage >= 80 ? 'var(--green-11)' : att.percentage >= 60 ? 'var(--amber-11)' : 'var(--red-11)' }}>
                                    {att.percentage}%
                                </Text>
                            </Flex>
                        )}
                    </>
                )}

                {/* Tasks */}
                {canViewTasks && (
                    <>
                        {canViewAttendance && <Separator size="4" />}
                        <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.06em' }}>
                            Tasks
                        </Text>
                        <Grid columns="2" gap={{ initial: '2', md: '2' }}>
                            <StatBox icon={StackIcon}         label="Total"     value={tasks?.total}           color="accent" loading={loading} />
                            <StatBox icon={CheckCircledIcon}  label="Done"      value={tasks?.completed}       color="green"  loading={loading} />
                            <StatBox icon={ClockIcon}         label="Pending"   value={tasks?.pending}         color="amber"  loading={loading} />
                            <StatBox icon={FileTextIcon}      label="RFI"       value={tasks?.rfi_submissions} color="blue"   loading={loading} />
                        </Grid>
                    </>
                )}

                {!canViewAttendance && !canViewTasks && (
                    <Flex align="center" gap="2" mt="2">
                        <PersonIcon style={{ color: 'var(--gray-9)' }} />
                        <Text size="2" color="gray">No data available for your role.</Text>
                    </Flex>
                )}
            </Flex>
        </Panel>
    );
}
