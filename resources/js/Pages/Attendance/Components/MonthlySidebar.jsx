import React from 'react';
import { Box, Flex, Text, Card, Skeleton } from '@radix-ui/themes';
import { CalendarIcon, BarChartIcon, InfoCircledIcon } from '@radix-ui/react-icons';
import { PieChart, Pie, Cell, AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer, BarChart, Bar } from 'recharts';
import dayjs from 'dayjs';

export default function MonthlySidebar({
    rows = [],
    days = [],
    monthNum,
    yearNum,
    leaveTypes = [],
    leaveCounts = {},
    isLoading = false,
}) {
    if (isLoading) {
        return (
            <Flex
                direction="column"
                gap="3"
                style={{
                    height: '100%',
                    borderLeft: '1px solid var(--gray-a4)',
                    background: 'var(--gray-1)',
                    padding: '16px',
                }}
            >
                <Skeleton width="40%" height="16px" />
                <Skeleton width="100%" height="120px" />
                <Skeleton width="60%" height="16px" style={{ marginTop: '16px' }} />
                <Skeleton width="100%" height="150px" />
            </Flex>
        );
    }

    // 1. Calculate general stats
    let totalPresents = 0;
    let totalAbsents = 0;

    days.forEach(d => {
        const dateKey = dayjs(`${yearNum}-${String(monthNum).padStart(2, '0')}-${String(d).padStart(2, '0')}`).format('YYYY-MM-DD');
        rows.forEach(row => {
            const cell = row[dateKey];
            const status = typeof cell === 'object' ? cell?.status : cell;
            if (status === '√') {
                totalPresents++;
            } else if (status === '▼') {
                totalAbsents++;
            }
        });
    });

    const totalActions = totalPresents + totalAbsents;
    const avgAttendanceRate = totalActions > 0 ? Math.round((totalPresents / totalActions) * 100) : 0;

    // 2. Attendance Trend Data
    const trendData = days.map(d => {
        const dateKey = dayjs(`${yearNum}-${String(monthNum).padStart(2, '0')}-${String(d).padStart(2, '0')}`).format('YYYY-MM-DD');
        let present = 0;
        rows.forEach(row => {
            const cell = row[dateKey];
            const status = typeof cell === 'object' ? cell?.status : cell;
            if (status === '√') present++;
        });
        return { day: d, Present: present };
    });

    // 3. Leave Breakdown Data
    const leaveBreakdown = (leaveTypes || []).map(t => {
        let count = 0;
        Object.keys(leaveCounts || {}).forEach(userId => {
            count += leaveCounts[userId]?.[t.type] || 0;
        });
        return { name: t.type, Count: count };
    }).filter(item => item.Count > 0);

    return (
        <Flex
            direction="column"
            gap="4"
            style={{
                height: '100%',
                borderLeft: '1px solid var(--gray-a4)',
                background: 'var(--gray-1)',
                padding: '16px',
            }}
        >
            {/* Heading */}
            <Flex align="center" gap="2" style={{ borderBottom: '1px solid var(--gray-a4)', pb: '8px' }}>
                <BarChartIcon style={{ color: 'var(--accent-9)', width: 16, height: 16 }} />
                <Text size="2" weight="bold">Monthly Analytics</Text>
            </Flex>

            {/* Attendance Rate */}
            <Card style={{ padding: '12px' }}>
                <Text size="1" color="gray" weight="bold" mb="2" style={{ display: 'block', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    Attendance Rate
                </Text>
                <Flex align="center" gap="3">
                    <Box style={{ width: 90, height: 90, position: 'relative', flexShrink: 0 }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={[
                                        { name: 'Present', value: totalPresents, fill: 'var(--green-9)' },
                                        { name: 'Absent', value: totalAbsents, fill: 'var(--red-9)' },
                                    ].filter(d => d.value > 0)}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={28}
                                    outerRadius={40}
                                    paddingAngle={2}
                                    dataKey="value"
                                >
                                    {/* fills supplied */}
                                </Pie>
                                <Tooltip
                                    contentStyle={{
                                        background: 'var(--color-panel-solid)',
                                        border: '1px solid var(--gray-a6)',
                                        borderRadius: 'var(--radius-2)',
                                        fontSize: 10,
                                        padding: '2px 6px',
                                    }}
                                />
                            </PieChart>
                        </ResponsiveContainer>
                        <Flex
                            direction="column"
                            align="center"
                            justify="center"
                            style={{
                                position: 'absolute',
                                top: 0, left: 0, right: 0, bottom: 0,
                            }}
                        >
                            <Text size="2" weight="bold" style={{ color: 'var(--green-11)' }}>{avgAttendanceRate}%</Text>
                        </Flex>
                    </Box>
                    <Flex direction="column" gap="1" style={{ flex: 1 }}>
                        <Flex align="center" justify="between">
                            <Flex align="center" gap="1">
                                <Box style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--green-9)' }} />
                                <Text size="1" color="gray">Present</Text>
                            </Flex>
                            <Text size="1" weight="bold">{totalPresents}</Text>
                        </Flex>
                        <Flex align="center" justify="between">
                            <Flex align="center" gap="1">
                                <Box style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--red-9)' }} />
                                <Text size="1" color="gray">Absent</Text>
                            </Flex>
                            <Text size="1" weight="bold">{totalAbsents}</Text>
                        </Flex>
                    </Flex>
                </Flex>
            </Card>

            {/* Attendance Trend */}
            <Card style={{ padding: '12px' }}>
                <Text size="1" color="gray" weight="bold" mb="2" style={{ display: 'block', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    Attendance Trend (Daily Presents)
                </Text>
                {rows.length > 0 ? (
                    <Box style={{ width: '100%', height: 120 }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={trendData} margin={{ top: 5, right: 5, left: -25, bottom: 0 }}>
                                <defs>
                                    <linearGradient id="colorPresent" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor="var(--green-9)" stopOpacity={0.4}/>
                                        <stop offset="95%" stopColor="var(--green-9)" stopOpacity={0.0}/>
                                    </linearGradient>
                                </defs>
                                <XAxis dataKey="day" tick={{ fontSize: 9, fill: 'var(--gray-9)' }} axisLine={false} tickLine={false} />
                                <YAxis tick={{ fontSize: 9, fill: 'var(--gray-9)' }} axisLine={false} tickLine={false} />
                                <Tooltip
                                    contentStyle={{
                                        background: 'var(--color-panel-solid)',
                                        border: '1px solid var(--gray-a6)',
                                        borderRadius: 'var(--radius-2)',
                                        fontSize: 10,
                                        padding: '4px 8px',
                                    }}
                                />
                                <Area type="monotone" dataKey="Present" stroke="var(--green-9)" strokeWidth={2} fillOpacity={1} fill="url(#colorPresent)" />
                            </AreaChart>
                        </ResponsiveContainer>
                    </Box>
                ) : (
                    <Text size="1" color="gray">No data to display trend</Text>
                )}
            </Card>

            {/* Leave Breakdown */}
            {leaveBreakdown.length > 0 && (
                <Card style={{ padding: '12px' }}>
                    <Text size="1" color="gray" weight="bold" mb="2" style={{ display: 'block', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                        Leaves Taken
                    </Text>
                    <Box style={{ width: '100%', height: 120 }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={leaveBreakdown} margin={{ top: 5, right: 5, left: -25, bottom: 0 }}>
                                <XAxis dataKey="name" tick={{ fontSize: 9, fill: 'var(--gray-9)' }} axisLine={false} tickLine={false} />
                                <YAxis tick={{ fontSize: 9, fill: 'var(--gray-9)' }} axisLine={false} tickLine={false} />
                                <Tooltip
                                    contentStyle={{
                                        background: 'var(--color-panel-solid)',
                                        border: '1px solid var(--gray-a6)',
                                        borderRadius: 'var(--radius-2)',
                                        fontSize: 10,
                                        padding: '4px 8px',
                                    }}
                                />
                                <Bar dataKey="Count" fill="var(--blue-9)" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </Box>
                </Card>
            )}
        </Flex>
    );
}
