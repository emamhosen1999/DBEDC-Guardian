import React from 'react';
import { Box, Flex, Text, Badge, Avatar, Button, ScrollArea, Skeleton, Spinner } from '@radix-ui/themes';
import {
    CrossCircledIcon,
    PersonIcon,
    CheckCircledIcon,
    CalendarIcon,
} from '@radix-ui/react-icons';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip as RechartsTooltip } from 'recharts';

/**
 * AbsentSidebar
 *
 * Props:
 *   absentUsers     – array
 *   getUserLeave    – fn(userId) → leave | undefined
 *   isLoaded        – bool
 *   onMarkAsPresent – fn(user, date)
 *   selectedDate    – string YYYY-MM-DD
 *   canManage       – bool
 */

const getLeaveColor = (status) => {
    switch (status?.toLowerCase()) {
        case 'approved': return 'green';
        case 'rejected': return 'red';
        default:         return 'amber';
    }
};

const AbsentSidebar = ({
    absentUsers = [],
    offUsers = [],
    upcomingUsers = [],
    upcomingVisible = true,
    getUserLeave,
    isLoaded = true,
    onMarkAsPresent,
    markingId = null,
    selectedDate,
    canManage = false,
    isWeekend = false,
    presentCount = 0,
    leavesCount = 0,
}) => {
    // Exclude leaves count from offUsers for Rest Day count
    const restDayCount = Math.max(0, offUsers.length - leavesCount);

    return (
        <Flex
            direction="column"
            style={{
                height: '100%',
                borderLeft: '1px solid var(--gray-a4)',
                background: 'var(--gray-1)',
            }}
        >
            {/* Header */}
            <Flex
                align="center"
                justify="between"
                px="3"
                py="2"
                style={{
                    borderBottom: '1px solid var(--gray-a4)',
                    background: 'var(--gray-2)',
                    flexShrink: 0,
                }}
            >
                <Flex align="center" gap="2">
                    <CrossCircledIcon style={{ color: 'var(--red-9)', width: 14, height: 14 }} />
                    <Text size="2" weight="medium">Absent</Text>
                </Flex>
                <Badge color="red" variant="soft" size="1">
                    {isLoaded ? absentUsers.length : '…'}
                </Badge>
            </Flex>

            {/* List */}
            <ScrollArea style={{ flex: 1 }}>
                <Box p="2">
                    {/* Chart breakdown */}
                    {isLoaded && (presentCount > 0 || absentUsers.length > 0 || leavesCount > 0 || restDayCount > 0) && (
                        <Box
                            mb="3"
                            p="3"
                            style={{
                                borderRadius: 'var(--radius-3)',
                                background: 'var(--color-surface)',
                                border: '1px solid var(--gray-a4)',
                                boxShadow: 'var(--shadow-1)',
                            }}
                        >
                            <Text size="1" color="gray" weight="bold" mb="2" style={{ display: 'block', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                                Daily Stats
                            </Text>
                            <Flex align="center" gap="3">
                                <Box style={{ width: 100, height: 100, position: 'relative', flexShrink: 0 }}>
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={[
                                                    { name: 'Present', value: presentCount, fill: 'var(--green-9)' },
                                                    { name: 'Absent', value: absentUsers.length, fill: 'var(--red-9)' },
                                                    { name: 'On Leave', value: leavesCount, fill: 'var(--blue-9)' },
                                                    { name: 'Rest Day/Wknd', value: restDayCount, fill: 'var(--gray-7)' },
                                                ].filter(d => d.value > 0)}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={30}
                                                outerRadius={45}
                                                paddingAngle={2}
                                                dataKey="value"
                                            >
                                                {/* fills already supplied */}
                                            </Pie>
                                            <RechartsTooltip
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
                                    {(() => {
                                        const denominator = presentCount + absentUsers.length;
                                        const rate = denominator > 0 ? Math.round((presentCount / denominator) * 100) : 0;
                                        return (
                                            <Flex
                                                direction="column"
                                                align="center"
                                                justify="center"
                                                style={{
                                                    position: 'absolute',
                                                    top: 0, left: 0, right: 0, bottom: 0,
                                                }}
                                            >
                                                <Text size="2" weight="bold" style={{ color: 'var(--green-11)' }}>{rate}%</Text>
                                                <Text size="1" color="gray" style={{ fontSize: 8 }}>Rate</Text>
                                            </Flex>
                                        );
                                    })()}
                                </Box>
                                <Flex direction="column" gap="1" style={{ flex: 1, minWidth: 0 }}>
                                    <Flex align="center" justify="between" gap="1">
                                        <Flex align="center" gap="1" style={{ minWidth: 0 }}>
                                            <Box style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--green-9)', flexShrink: 0 }} />
                                            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>Present</Text>
                                        </Flex>
                                        <Text size="1" weight="bold">{presentCount}</Text>
                                    </Flex>
                                    <Flex align="center" justify="between" gap="1">
                                        <Flex align="center" gap="1" style={{ minWidth: 0 }}>
                                            <Box style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--red-9)', flexShrink: 0 }} />
                                            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>Absent</Text>
                                        </Flex>
                                        <Text size="1" weight="bold">{absentUsers.length}</Text>
                                    </Flex>
                                    <Flex align="center" justify="between" gap="1">
                                        <Flex align="center" gap="1" style={{ minWidth: 0 }}>
                                            <Box style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--blue-9)', flexShrink: 0 }} />
                                            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>On Leave</Text>
                                        </Flex>
                                        <Text size="1" weight="bold">{leavesCount}</Text>
                                    </Flex>
                                    <Flex align="center" justify="between" gap="1">
                                        <Flex align="center" gap="1" style={{ minWidth: 0 }}>
                                            <Box style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--gray-7)', flexShrink: 0 }} />
                                            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>Rest/Wknd</Text>
                                        </Flex>
                                        <Text size="1" weight="bold">{restDayCount}</Text>
                                    </Flex>
                                </Flex>
                            </Flex>
                        </Box>
                    )}

                    {!isLoaded ? (
                        <Flex direction="column" gap="2">
                            {[...Array(5)].map((_, i) => (
                                <Flex key={i} align="center" gap="2" p="2">
                                    <Skeleton width="28px" height="28px" style={{ borderRadius: '50%', flexShrink: 0 }} />
                                    <Flex direction="column" gap="1" style={{ flex: 1 }}>
                                        <Skeleton width="70%" height="12px" />
                                        <Skeleton width="50%" height="10px" />
                                    </Flex>
                                </Flex>
                            ))}
                        </Flex>
                    ) : absentUsers.length === 0 && offUsers.length === 0 && upcomingUsers.length === 0 ? (
                        <Flex direction="column" align="center" justify="center" py="8" gap="2">
                            <CheckCircledIcon style={{ color: 'var(--green-9)', width: 28, height: 28 }} />
                            <Text size="2" color="gray" align="center">
                                All employees accounted for
                            </Text>
                        </Flex>
                    ) : (
                        <Flex direction="column" gap="4">
                            {/* 1. Absent Employees */}
                            {absentUsers.length > 0 && (
                                <Flex direction="column" gap="1">
                                    <Text size="1" color="red" weight="bold" mb="1" px="1">
                                        ABSENT ({absentUsers.length})
                                    </Text>
                                    {absentUsers.map((user) => {
                                        const leave = getUserLeave?.(user.id || user.user_id);
                                        return (
                                            <Box
                                                key={user.id || user.user_id}
                                                p="3"
                                                style={{
                                                    borderRadius: 'var(--radius-3)',
                                                    background: 'var(--color-surface)',
                                                    border: '1px solid var(--gray-a4)',
                                                    boxShadow: 'var(--shadow-1)',
                                                }}
                                            >
                                                <Flex align="start" gap="3">
                                                    <Avatar
                                                        src={user.profile_image_url || user.profile_image}
                                                        fallback={(user.name || '?').charAt(0).toUpperCase()}
                                                        size="2"
                                                        radius="full"
                                                        style={{ flexShrink: 0, marginTop: 2 }}
                                                    />
                                                    <Flex direction="column" gap="1" style={{ flex: 1, minWidth: 0 }}>
                                                        <Text
                                                            size="2"
                                                            weight="bold"
                                                            style={{
                                                                overflow: 'hidden',
                                                                textOverflow: 'ellipsis',
                                                                whiteSpace: 'nowrap',
                                                            }}
                                                        >
                                                            {user.name || 'Unknown'}
                                                        </Text>

                                                        {leave ? (
                                                            <Flex align="center" gap="1">
                                                                <CalendarIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                                                <Badge color={getLeaveColor(leave.status)} variant="soft" size="1">
                                                                    {leave.leave_type || 'Leave'} · {leave.status}
                                                                </Badge>
                                                            </Flex>
                                                        ) : (
                                                            <Flex align="center" gap="1">
                                                                <PersonIcon style={{ width: 12, height: 12, color: 'var(--gray-8)' }} />
                                                                <Text size="1" color="gray">No leave filed</Text>
                                                            </Flex>
                                                        )}

                                                        {canManage && onMarkAsPresent && (
                                                            <Button
                                                                size="2"
                                                                variant="solid"
                                                                color="green"
                                                                style={{ marginTop: 6, width: '100%', cursor: 'pointer' }}
                                                                disabled={markingId === user.id}
                                                                onClick={() => onMarkAsPresent(user, selectedDate)}
                                                            >
                                                                {markingId === user.id
                                                                    ? <Spinner size="1" />
                                                                    : <CheckCircledIcon width={14} height={14} />}
                                                                <Text size="1" weight="bold">{markingId === user.id ? 'Marking…' : 'Mark Present'}</Text>
                                                            </Button>
                                                        )}
                                                    </Flex>
                                                </Flex>
                                            </Box>
                                        );
                                    })}
                                </Flex>
                            )}

                            {/* 2. Scheduled / Upcoming Employees */}
                            {upcomingVisible && upcomingUsers.length > 0 && (
                                <Flex direction="column" gap="1">
                                    <Text size="1" color="indigo" weight="bold" mb="1" px="1">
                                        UPCOMING SHIFTS ({upcomingUsers.length})
                                    </Text>
                                    {upcomingUsers.map((user) => {
                                        return (
                                            <Box
                                                key={user.id || user.user_id}
                                                p="2"
                                                style={{
                                                    borderRadius: 'var(--radius-2)',
                                                    background: 'var(--color-surface)',
                                                    border: '1px solid var(--gray-a3)',
                                                }}
                                            >
                                                <Flex align="start" gap="2">
                                                    <Avatar
                                                        src={user.profile_image_url || user.profile_image}
                                                        fallback={(user.name || '?').charAt(0).toUpperCase()}
                                                        size="1"
                                                        radius="full"
                                                        style={{ flexShrink: 0, marginTop: 2 }}
                                                    />
                                                    <Flex direction="column" gap="1" style={{ flex: 1, minWidth: 0 }}>
                                                        <Text
                                                            size="1"
                                                            weight="medium"
                                                            style={{
                                                                overflow: 'hidden',
                                                                textOverflow: 'ellipsis',
                                                                whiteSpace: 'nowrap',
                                                            }}
                                                        >
                                                            {user.name || 'Unknown'}
                                                        </Text>
                                                        <Flex align="center" gap="1" wrap="wrap">
                                                            <CalendarIcon style={{ width: 10, height: 10, color: 'var(--indigo-9)' }} />
                                                            <Text size="1" color="indigo" weight="medium">
                                                                {user.shift_code ? `[${user.shift_code}] ` : ''}
                                                                {user.shift_name || 'Scheduled'}
                                                            </Text>
                                                        </Flex>
                                                        <Text size="1" color="gray" style={{ paddingLeft: 14 }}>
                                                            {user.shift_start ? `${user.shift_start} - ${user.shift_end}` : `Starts at ${user.shift_start_time || 'scheduled time'}`}
                                                        </Text>
                                                    </Flex>
                                                </Flex>
                                            </Box>
                                        );
                                    })}
                                </Flex>
                            )}

                            {/* 3. Rostered Off / Weekend Employees */}
                            {offUsers.length > 0 && (
                                <Flex direction="column" gap="1">
                                    <Text size="1" color="gray" weight="bold" mb="1" px="1">
                                        ROSTERED OFF / WEEKEND ({offUsers.length})
                                    </Text>
                                    {offUsers.map((user) => {
                                        return (
                                            <Box
                                                key={user.id || user.user_id}
                                                p="2"
                                                style={{
                                                    borderRadius: 'var(--radius-2)',
                                                    background: 'var(--color-surface)',
                                                    border: '1px solid var(--gray-a3)',
                                                    opacity: 0.8,
                                                }}
                                            >
                                                <Flex align="start" gap="2">
                                                    <Avatar
                                                        src={user.profile_image_url || user.profile_image}
                                                        fallback={(user.name || '?').charAt(0).toUpperCase()}
                                                        size="1"
                                                        radius="full"
                                                        style={{ flexShrink: 0, marginTop: 2 }}
                                                    />
                                                    <Flex direction="column" gap="1" style={{ flex: 1, minWidth: 0 }}>
                                                        <Text
                                                            size="1"
                                                            weight="medium"
                                                            style={{
                                                                overflow: 'hidden',
                                                                textOverflow: 'ellipsis',
                                                                whiteSpace: 'nowrap',
                                                            }}
                                                        >
                                                            {user.name || 'Unknown'}
                                                        </Text>
                                                        <Flex align="center" gap="1">
                                                            <CalendarIcon style={{ width: 10, height: 10, color: 'var(--gray-8)' }} />
                                                            <Text size="1" color="gray">Rest Day / Weekend</Text>
                                                        </Flex>
                                                    </Flex>
                                                </Flex>
                                            </Box>
                                        );
                                    })}
                                </Flex>
                            )}
                        </Flex>
                    )}
                </Box>
            </ScrollArea>
        </Flex>
    );
};

export default AbsentSidebar;
