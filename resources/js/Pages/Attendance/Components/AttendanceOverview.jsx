import React, { useState, useEffect } from 'react';
import { Card, Flex, Grid, Heading, Box, Text, Skeleton } from '@radix-ui/themes';
import { CheckCircledIcon, CrossCircledIcon, ExclamationTriangleIcon, CalendarIcon } from '@radix-ui/react-icons';
import axios from 'axios';

const StatCard = ({ title, value, icon: Icon, color, loading }) => (
    <Card style={{ height: '100%' }}>
        <Flex direction="column" gap="3">
            <Flex align="center" gap="3">
                <Box style={{
                    padding: 8, 
                    borderRadius: 'var(--radius-3)',
                    background: `var(--${color}-a3)`,
                    border: `1px solid var(--${color}-a5)`,
                    display: 'flex', alignItems: 'center', justifyContent: 'center'
                }}>
                    <Icon style={{ color: `var(--${color}-9)`, width: 20, height: 20 }} />
                </Box>
                <Heading size="2" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    {title}
                </Heading>
            </Flex>
            {loading ? (
                <Skeleton width="60px" height="32px" />
            ) : (
                <Flex align="baseline" gap="2">
                    <Text size="7" weight="bold" style={{ color: `var(--${color}-11)` }}>
                        {value ?? 0}
                    </Text>
                </Flex>
            )}
        </Flex>
    </Card>
);

export default function AttendanceOverview({ date, mode = 'daily', month, scope = 'all' }) {
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const isMonthly = mode === 'monthly';
    const isSelf = scope === 'self';

    useEffect(() => {
        let isMounted = true;
        setLoading(true);

        const request = isMonthly
            ? (() => {
                const [year, m] = (month || '').split('-');
                const params = {
                    currentMonth: parseInt(m, 10) || (new Date().getMonth() + 1),
                    currentYear: parseInt(year, 10) || new Date().getFullYear(),
                };
                // self scope → the current user's own monthly stats; otherwise org-wide
                return isSelf
                    ? axios.get('/attendance/my-monthly-stats', { params })
                    : axios.get(route('attendance.monthlyStats', params));
            })()
            : axios.get(route('attendance.dailyOverview', { date }));

        request
            .then(res => {
                if (!isMounted) return;
                if (isMonthly) {
                    const a = res.data?.stats?.attendance || res.data?.data?.attendance || {};
                    setStats({ present: a.present, absent: a.absent, late: a.lateArrivals, on_leave: a.leaves });
                } else {
                    setStats(res.data);
                }
                setLoading(false);
            })
            .catch(err => {
                console.error('Failed to fetch attendance overview:', err);
                if (isMounted) setLoading(false);
            });

        return () => { isMounted = false; };
    }, [date, mode, month, isMonthly, isSelf]);

    return (
        <Box mb="5">
            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="4">
                <StatCard 
                    title="Present" 
                    value={stats?.present} 
                    icon={CheckCircledIcon} 
                    color="green" 
                    loading={loading}
                />
                <StatCard 
                    title="Absent" 
                    value={stats?.absent} 
                    icon={CrossCircledIcon} 
                    color="red" 
                    loading={loading}
                />
                <StatCard
                    title="Late Arrivals"
                    value={stats?.late}
                    icon={ExclamationTriangleIcon}
                    color="amber"
                    loading={loading}
                />
                <StatCard 
                    title="On Leave" 
                    value={stats?.on_leave} 
                    icon={CalendarIcon} 
                    color="cyan" 
                    loading={loading}
                />
            </Grid>
        </Box>
    );
}
