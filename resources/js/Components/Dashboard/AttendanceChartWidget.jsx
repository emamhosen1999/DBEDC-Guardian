import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useState } from 'react';
import { Badge, Box, Flex, Heading, Skeleton, Text } from '@radix-ui/themes';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip,
    ResponsiveContainer, Legend, PieChart, Pie, Cell,
} from 'recharts';
import axios from 'axios';

const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

function buildPersonalChartData(data) {
    if (!data) return [];
    const att = data.attendance;
    return [
        { name: 'Present', value: att.present,      fill: 'var(--green-9)'  },
        { name: 'Absent',  value: att.absent,        fill: 'var(--red-9)'    },
        { name: 'Late',    value: att.lateArrivals,  fill: 'var(--amber-9)'  },
        { name: 'Leave',   value: att.leaves,        fill: 'var(--blue-9)'   },
    ].filter(item => item.value > 0); // Only show positive values in Donut
}

function PersonalChart({ data, loading, height = 180 }) {
    if (loading) {
        return <Skeleton style={{ width: '100%', height }} />;
    }
    if (!data) {
        return <Text size="2" color="gray">No attendance data available.</Text>;
    }

    const chartData = buildPersonalChartData(data);
    const displayData = chartData.length > 0 ? chartData : [{ name: 'No Data', value: 1, fill: 'var(--gray-3)' }];

    return (
        <Flex align="center" justify="between" gap="4" style={{ height }}>
            {/* Left: Donut Chart */}
            <Box style={{ position: 'relative', width: height, height, flexShrink: 0 }}>
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={displayData}
                            cx="50%"
                            cy="50%"
                            innerRadius={Math.round(height * 0.28)}
                            outerRadius={Math.round(height * 0.38)}
                            paddingAngle={3}
                            dataKey="value"
                        >
                            {displayData.map((entry, index) => (
                                <Cell key={`cell-${index}`} fill={entry.fill} />
                            ))}
                        </Pie>
                    </PieChart>
                </ResponsiveContainer>
                {/* Center text */}
                <Flex
                    direction="column"
                    align="center"
                    justify="center"
                    style={{
                        position: 'absolute',
                        top: 0, left: 0, right: 0, bottom: 0,
                    }}
                >
                    <Text size="5" weight="bold" style={{ color: 'var(--indigo-9)' }}>
                        {data.attendance?.percentage != null ? `${Math.round(data.attendance.percentage)}%` : '—'}
                    </Text>
                    <Text size="1" color="gray" style={{ fontSize: 9, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                        Presence
                    </Text>
                </Flex>
            </Box>

            {/* Right: Legend list */}
            <Flex direction="column" gap="1" style={{ flex: 1, minWidth: 0 }}>
                {[
                    { name: 'Present', value: data.attendance?.present, fill: 'var(--green-9)' },
                    { name: 'Absent', value: data.attendance?.absent, fill: 'var(--red-9)' },
                    { name: 'Late', value: data.attendance?.lateArrivals, fill: 'var(--amber-9)' },
                    { name: 'Leave', value: data.attendance?.leaves, fill: 'var(--blue-9)' }
                ].map((item, idx) => (
                    <Flex key={idx} align="center" justify="between" style={{ borderBottom: '1px dashed var(--gray-a3)', paddingBottom: 2 }}>
                        <Flex align="center" gap="2">
                            <Box style={{ width: 8, height: 8, borderRadius: '50%', background: item.fill }} />
                            <Text size="1" color="gray" weight="medium">{item.name}</Text>
                        </Flex>
                        <Text size="1" weight="bold">{item.value ?? 0}d</Text>
                    </Flex>
                ))}
            </Flex>
        </Flex>
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
        { name: 'Present', value: data.attendance?.present ?? 0, fill: 'var(--green-9)' },
        { name: 'Absent',  value: data.attendance?.absent  ?? 0, fill: 'var(--red-9)' },
        { name: 'Late',    value: data.attendance?.lateArrivals ?? 0, fill: 'var(--amber-9)' },
        { name: 'Leave',   value: data.attendance?.leaves   ?? 0, fill: 'var(--blue-9)' },
    ];

    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart data={chartData} margin={{ top: 10, right: 8, left: -20, bottom: 0 }}>
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
                    {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.fill} />
                    ))}
                </Bar>
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
                    <Panel style={{ flex: '1 1 240px', minWidth: 0, height: '260px', display: 'flex', flexDirection: 'column' }}>
                        <Flex align="center" justify="between" mb={{ initial: '2', md: '3' }} style={{ flexShrink: 0 }}>
                            <Heading size={{ initial: '2', md: '3' }}>My Attendance</Heading>
                            <Badge variant="soft" color="gray" size="1">{monthLabel}</Badge>
                        </Flex>
                        <Box style={{ flex: 1, minHeight: 0 }}>
                            <PersonalChart data={personal} loading={loading} height={170} />
                        </Box>
                    </Panel>
                )}

                {canTeam && (
                    <Panel style={{ flex: '1 1 240px', minWidth: 0, height: '260px', display: 'flex', flexDirection: 'column' }}>
                        <Flex align="center" justify="between" mb={{ initial: '2', md: '3' }} style={{ flexShrink: 0 }}>
                            <Heading size={{ initial: '2', md: '3' }}>Team Attendance</Heading>
                            <Badge variant="soft" color="gray" size="1">
                                {team?.meta?.totalEmployees != null ? `${team.meta.totalEmployees} employees` : monthLabel}
                            </Badge>
                        </Flex>
                        <Box style={{ flex: 1, minHeight: 0 }}>
                            <TeamChart data={team} loading={loading} height={170} />
                        </Box>
                    </Panel>
                )}
            </Flex>
        </Box>
    );
}
