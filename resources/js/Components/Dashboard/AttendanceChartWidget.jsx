import React, { useEffect, useState } from 'react';
import { Badge, Box, Card, Flex, Heading, Skeleton, Text } from '@radix-ui/themes';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip,
    ResponsiveContainer, Legend,
} from 'recharts';
import axios from 'axios';

const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function buildPersonalChartData(data) {
    if (!data) return [];
    const att = data.attendance;
    const meta = data.meta;
    return [
        { name: 'Present', value: att.present,      fill: 'var(--green-9)'  },
        { name: 'Absent',  value: att.absent,        fill: 'var(--red-9)'    },
        { name: 'Late',    value: att.lateArrivals,  fill: 'var(--amber-9)'  },
        { name: 'Leave',   value: att.leaves,        fill: 'var(--blue-9)'   },
    ];
}

function PersonalChart({ data, loading, height = 180 }) {
    if (loading) {
        return <Skeleton style={{ width: '100%', height }} />;
    }
    if (!data) {
        return <Text size="2" color="gray">No attendance data available.</Text>;
    }

    const chartData = buildPersonalChartData(data);

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={chartData} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--gray-a4)" vertical={false} />
                <XAxis dataKey="name" tick={{ fontSize: 11, fill: 'var(--gray-10)' }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--gray-10)' }} axisLine={false} tickLine={false} />
                <Tooltip
                    contentStyle={{
                        background: 'var(--color-panel-solid)',
                        border: '1px solid var(--gray-a6)',
                        borderRadius: 'var(--radius-2)',
                        fontSize: 12,
                    }}
                    cursor={{ fill: 'var(--gray-a3)' }}
                />
                <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                    {chartData.map((entry, i) => (
                        <rect key={i} fill={entry.fill} />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

function TeamChart({ data, loading, height = 180 }) {
    if (loading) {
        return <Skeleton style={{ width: '100%', height }} />;
    }
    if (!data) {
        return <Text size="2" color="gray">No team attendance data available.</Text>;
    }

    const chartData = [
        { name: 'Present', value: data.attendance?.present ?? 0 },
        { name: 'Absent',  value: data.attendance?.absent  ?? 0 },
        { name: 'Late',    value: data.attendance?.lateArrivals ?? 0 },
        { name: 'Leave',   value: data.attendance?.leaves   ?? 0 },
    ];

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={chartData} margin={{ top: 4, right: 8, left: -20, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--gray-a4)" vertical={false} />
                <XAxis dataKey="name" tick={{ fontSize: 11, fill: 'var(--gray-10)' }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--gray-10)' }} axisLine={false} tickLine={false} />
                <Tooltip
                    contentStyle={{
                        background: 'var(--color-panel-solid)',
                        border: '1px solid var(--gray-a6)',
                        borderRadius: 'var(--radius-2)',
                        fontSize: 12,
                    }}
                    cursor={{ fill: 'var(--gray-a3)' }}
                />
                <Bar dataKey="value" fill="var(--accent-9)" radius={[4, 4, 0, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}

export default function AttendanceChartWidget({ permissions = [] }) {
    const [personal, setPersonal]   = useState(null);
    const [team, setTeam]           = useState(null);
    const [loading, setLoading]     = useState(true);
    const isSmall = useMediaQuery('(max-width: 640px)');
    const chartHeight = isSmall ? 130 : 170;

    const canPersonal = permissions.includes('attendance.own.view');
    const canTeam     = permissions.includes('attendance.view');

    const now = new Date();
    const monthLabel = `${MONTH_NAMES[now.getMonth()]} ${now.getFullYear()}`;

    useEffect(() => {
        const reqs = [];

        if (canPersonal) {
            reqs.push(
                axios.get(route('attendance.myMonthlyStats'))
                    .then(r => setPersonal(r.data?.data ?? null))
                    .catch(() => setPersonal(null))
            );
        }

        if (canTeam) {
            reqs.push(
                axios.get(route('attendance.monthlyStats'))
                    .then(r => setTeam(r.data?.data ?? null))
                    .catch(() => setTeam(null))
            );
        }

        if (reqs.length === 0) {
            setLoading(false);
            return;
        }

        Promise.allSettled(reqs).finally(() => setLoading(false));
    }, [canPersonal, canTeam]);

    if (!canPersonal && !canTeam) return null;

    return (
        <Box>
            <Flex gap={{ initial: '3', md: '4' }} style={{ flexWrap: 'wrap' }}>
                {canPersonal && (
                    <Card style={{ flex: '1 1 240px', minWidth: 0 }}>
                        <Flex align="center" justify="between" mb={{ initial: '2', md: '3' }}>
                            <Heading size={{ initial: '2', md: '3' }}>My Attendance</Heading>
                            <Badge variant="soft" color="gray" size="1">{monthLabel}</Badge>
                        </Flex>
                        <PersonalChart data={personal} loading={loading} height={chartHeight} />
                    </Card>
                )}

                {canTeam && (
                    <Card style={{ flex: '1 1 240px', minWidth: 0 }}>
                        <Flex align="center" justify="between" mb={{ initial: '2', md: '3' }}>
                            <Heading size={{ initial: '2', md: '3' }}>Team Attendance</Heading>
                            <Badge variant="soft" color="gray" size="1">
                                {team?.meta?.totalEmployees != null ? `${team.meta.totalEmployees} employees` : monthLabel}
                            </Badge>
                        </Flex>
                        <TeamChart data={team} loading={loading} height={chartHeight} />
                    </Card>
                )}
            </Flex>
        </Box>
    );
}
