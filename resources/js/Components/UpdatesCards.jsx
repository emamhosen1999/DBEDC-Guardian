import React, { useEffect, useState } from 'react';
import {
    Avatar, Badge, Box, Card, Flex, Grid, Heading,
    Popover, Separator, Skeleton, Text,
} from '@radix-ui/themes';
import {
    CalendarIcon,
    ClockIcon,
    PersonIcon,
    ExclamationTriangleIcon,
    InfoCircledIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    FileTextIcon,
    SunIcon,
    TextAlignLeftIcon,
} from '@radix-ui/react-icons';
import { usePage } from "@inertiajs/react";
import dayjs from 'dayjs';
import isBetween from 'dayjs/plugin/isBetween';
import axios from 'axios';
import ProfileAvatar from '@/Components/ProfileAvatar';

dayjs.extend(isBetween);

const statusColor = (status) => {
    switch (status?.toLowerCase()) {
        case 'approved': return 'green';
        case 'rejected': return 'red';
        default: return 'amber';
    }
};

const statusIcon = (status) => {
    switch (status?.toLowerCase()) {
        case 'approved': return <CheckCircledIcon style={{ color: 'var(--green-9)' }} />;
        case 'rejected': return <CrossCircledIcon style={{ color: 'var(--red-9)' }} />;
        default: return <ClockIcon style={{ color: 'var(--amber-9)' }} />;
    }
};

const LeavePopover = ({ leave, user, children }) => (
    <Popover.Root>
        <Popover.Trigger asChild>
            <button style={{ all: 'unset', display: 'flex', cursor: 'pointer', borderRadius: '50%' }}>
                {children}
            </button>
        </Popover.Trigger>
        <Popover.Content style={{ minWidth: 280 }}>
            <Flex direction="column" gap="3">
                <Flex align="center" justify="between">
                    <Flex align="center" gap="2">
                        <FileTextIcon style={{ color: 'var(--accent-9)' }} />
                        <Text size="3" weight="bold">Leave Details</Text>
                    </Flex>
                    <Popover.Close>
                        <Box as="button" style={{ all: 'unset', cursor: 'pointer', color: 'var(--gray-10)', lineHeight: 1, padding: 2 }}>✕</Box>
                    </Popover.Close>
                </Flex>
                <Separator size="4" />
                <Flex direction="column" gap="2">
                    <Flex align="start" gap="2">
                        <PersonIcon style={{ marginTop: 2, flexShrink: 0, color: 'var(--gray-9)' }} />
                        <Box>
                            <Text size="1" color="gray">Employee</Text>
                            <Text size="2" weight="medium" style={{ display: 'block' }}>{user?.name || 'Unknown'}</Text>
                        </Box>
                    </Flex>
                    <Flex align="start" gap="2">
                        <CalendarIcon style={{ marginTop: 2, flexShrink: 0, color: 'var(--gray-9)' }} />
                        <Box>
                            <Text size="1" color="gray">Duration</Text>
                            <Text size="2" weight="medium" style={{ display: 'block' }}>
                                {leave.from_date !== leave.to_date
                                    ? `${new Date(leave.from_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} – ${new Date(leave.to_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
                                    : new Date(leave.from_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
                                }
                            </Text>
                        </Box>
                    </Flex>
                    <Flex align="start" gap="2">
                        <FileTextIcon style={{ marginTop: 2, flexShrink: 0, color: 'var(--gray-9)' }} />
                        <Box>
                            <Text size="1" color="gray">Reason</Text>
                            <Text size="2" weight="medium" style={{ display: 'block' }}>{leave.reason || 'No reason provided'}</Text>
                        </Box>
                    </Flex>
                    <Flex align="center" gap="2">
                        {statusIcon(leave.status)}
                        <Badge color={statusColor(leave.status)} variant="soft" size="1">
                            {leave.status || 'Pending'}
                        </Badge>
                    </Flex>
                </Flex>
            </Flex>
        </Popover.Content>
    </Popover.Root>
);

const UpdateSection = ({ title, items, users, icon: IconComponent, color }) => (
    <Card style={{ height: '100%' }}>
        <Box pb="2" mb="2" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
            <Flex align="center" gap="3">
                <Box style={{
                    padding: 10,
                    borderRadius: 'var(--radius-3)',
                    background: `var(--${color}-a3)`,
                    border: `1px solid var(--${color}-a6)`,
                    flexShrink: 0,
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    width: 40, height: 40,
                }}>
                    <IconComponent style={{ color: `var(--${color}-9)`, width: 18, height: 18 }} aria-hidden="true" />
                </Box>
                <Heading size="3">{title}</Heading>
            </Flex>
        </Box>
        <Flex direction="column" gap="0">
            {items.map((item, index) => {
                const leaves = item.leaves?.filter((l) => l.leave_type === item.type) ?? [];
                return (
                    <React.Fragment key={item.type ?? index}>
                        <Flex justify="between" align="center" py="2">
                            <Flex direction="column" style={{ flex: 1, marginRight: 8 }}>
                                <Text size="2">{item.text}</Text>
                                {item.leaves && item.leaves.length > 0 && (
                                    <Flex align="center" gap="1" mt="1">
                                        <PersonIcon style={{ color: 'var(--gray-9)', width: 12, height: 12 }} />
                                        <Text size="1" color="gray">
                                            {item.leaves.length} employee{item.leaves.length > 1 ? 's' : ''}
                                        </Text>
                                    </Flex>
                                )}
                            </Flex>
                            {leaves.length > 0 && (
                                <Flex gap="1" style={{ flexShrink: 0 }}>
                                    {leaves.slice(0, 4).map((leave, idx) => {
                                        const user = users.find((u) => String(u.id) === String(leave.user_id));
                                        return user ? (
                                            <LeavePopover key={idx} leave={leave} user={user}>
                                                <ProfileAvatar
                                                    src={user.profile_image_url}
                                                    name={user.name}
                                                    size="sm"
                                                    isInteractive
                                                />
                                            </LeavePopover>
                                        ) : null;
                                    })}
                                    {leaves.length > 4 && (
                                        <Avatar
                                            size="1"
                                            fallback={`+${leaves.length - 4}`}
                                            color="gray"
                                            variant="soft"
                                        />
                                    )}
                                </Flex>
                            )}
                        </Flex>
                        {index < items.length - 1 && <Separator size="4" />}
                    </React.Fragment>
                );
            })}
        </Flex>
    </Card>
);

const UpdatesCards = () => {
    const { auth } = usePage().props;
    
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [users, setUsers] = useState([]);
    const [todayLeaves, setTodayLeaves] = useState([]);
    const [upcomingLeaves, setUpcomingLeaves] = useState([]);
    const [upcomingHoliday, setUpcomingHoliday] = useState(null);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchUpdates = async () => {
            try {
                setLoading(true);
                setError(null);
                
                const response = await axios.get(route('updates'), {
                    signal: controller.signal,
                    timeout: 10000
                });
                
                if (isMounted && response.data) {
                    setUsers(response.data.users || []);
                    setTodayLeaves(response.data.todayLeaves || []);
                    setUpcomingLeaves(response.data.upcomingLeaves || []);
                    setUpcomingHoliday(response.data.upcomingHoliday || null);
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch updates:', err);
                    setError(err.message);
                    setUsers([]);
                    setTodayLeaves([]);
                    setUpcomingLeaves([]);
                    setUpcomingHoliday(null);
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchUpdates();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    // Helper function to group leaves by type and count
    const getLeaveSummary = (day, leaves) => {
        let leavesData = leaves;

        const userLeaveMessage = (type) => {
            const isCurrentUserOnLeave = leaves.some(leave => String(leave.user_id) === String(auth.user.id) && leave.leave_type === type);
            if (isCurrentUserOnLeave) {
                leavesData = leaves.filter(leave => String(leave.user_id) !== String(auth.user.id));
                return `You ${day === 'today' ? 'are' : 'will be'} on ${type} leave.`;
            }
            return null;
        };

        const userMessages = leaves.reduce((acc, leave) => {
            const message = userLeaveMessage(leave.leave_type);
            if (message && !acc.some(msg => msg.type === leave.leave_type)) {
                acc.push({ text: message, type: leave.leave_type });
            }
            return acc;
        }, []);

        const leaveCountByType = leavesData.reduce((summary, leave) => {
            summary[leave.leave_type] = (summary[leave.leave_type] || 0) + 1;
            return summary;
        }, {});

        const messages = Object.entries(leaveCountByType).map(([type, count]) => ({
            text: `${count} person${count > 1 ? 's' : ''} ${day === 'today' ? 'is' : 'will be'} on ${type} leave`,
            type: type,
            leaves: leavesData.filter(leave => leave.leave_type === type),
        }));

        return [...userMessages, ...messages];
    };

    // Dates
    const today = dayjs();
    const tomorrow = today.add(1, 'day');
    const sevenDaysFromNow = tomorrow.add(7, 'day');

    // Filter leaves for today, tomorrow, and within the next seven days
    const todayLeavesFiltered = todayLeaves.filter((leave) =>
        dayjs(today).isBetween(dayjs(leave.from_date), dayjs(leave.to_date), 'day', '[]')
    );
    const tomorrowLeaves = upcomingLeaves.filter((leave) =>
        dayjs(tomorrow).isBetween(dayjs(leave.from_date), dayjs(leave.to_date), 'day', '[]')
    );
    const nextSevenDaysLeaves = upcomingLeaves.filter(
        (leave) =>
            (dayjs(leave.from_date).isBetween(tomorrow, sevenDaysFromNow, 'day', '[]') ||
                dayjs(leave.to_date).isBetween(tomorrow, sevenDaysFromNow, 'day', '[]')) &&
            !/week/i.test(leave.leave_type)
    );

    // Get summary for each category
    const todayItems = getLeaveSummary('today', todayLeavesFiltered);
    const tomorrowItems = getLeaveSummary('tomorrow', tomorrowLeaves);
    const nextSevenDaysItems = getLeaveSummary('nextSevenDays', nextSevenDaysLeaves);

    // If no items, add default messages
    if (todayItems.length === 0) {
        todayItems.push({ text: 'No one is away today.' });
    }
    if (tomorrowItems.length === 0) {
        tomorrowItems.push({ text: 'No one is away tomorrow.' });
    }
    if (nextSevenDaysItems.length === 0) {
        nextSevenDaysItems.push({ text: 'No one is going to be away in the next seven days.' });
    }

    const sectionConfig = [
        {
            title: 'Today',
            items: todayItems,
            icon: CalendarIcon,
            color: 'blue',
        },
        {
            title: 'Tomorrow',
            items: tomorrowItems,
            icon: ClockIcon,
            color: 'green',
        },
        {
            title: 'Next Seven Days',
            items: nextSevenDaysItems,
            icon: PersonIcon,
            color: 'amber',
        }
    ];

    if (loading) {
        return (
            <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="4" aria-label="Employee updates loading">
                {[1, 2, 3].map((_, idx) => (
                    <Card key={idx}>
                        <Skeleton style={{ height: 24, width: '60%', borderRadius: 4, marginBottom: 12 }} />
                        <Skeleton style={{ height: 120, width: '100%', borderRadius: 4 }} />
                    </Card>
                ))}
            </Grid>
        );
    }

    if (error) {
        return (
            <Card style={{ borderColor: 'var(--red-a7)' }}>
                <Flex align="center" gap="3">
                    <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 20, height: 20 }} />
                    <Text size="2" color="red">Failed to load updates: {error}</Text>
                </Flex>
            </Card>
        );
    }

    return (
        <Box aria-label="Employee Updates Dashboard">
            <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="4" mb="4">
                {sectionConfig.map((section) => (
                    <UpdateSection
                        key={section.title}
                        title={section.title}
                        items={section.items}
                        users={users}
                        icon={section.icon}
                        color={section.color}
                    />
                ))}
            </Grid>

            {upcomingHoliday && (
                <Card>
                    <Box pb="2" mb="2" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                        <Flex align="center" gap="3">
                            <Box style={{
                                padding: 10, borderRadius: 'var(--radius-3)',
                                background: 'var(--amber-a3)', border: '1px solid var(--amber-a6)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                width: 40, height: 40, flexShrink: 0,
                            }}>
                                <SunIcon style={{ color: 'var(--amber-9)', width: 18, height: 18 }} />
                            </Box>
                            <Heading size="3">Upcoming Holiday</Heading>
                        </Flex>
                    </Box>
                    <Flex direction="column" gap="1">
                        <Flex align="center" gap="2">
                            <InfoCircledIcon style={{ color: 'var(--gray-9)' }} />
                            <Text size="2" weight="medium">{upcomingHoliday.title}</Text>
                        </Flex>
                        <Flex align="center" gap="2">
                            <CalendarIcon style={{ color: 'var(--gray-9)' }} />
                            <Text size="2" color="gray">
                                {new Date(upcomingHoliday.from_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                                {' – '}
                                {new Date(upcomingHoliday.to_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                            </Text>
                        </Flex>
                        {upcomingHoliday.description && (
                            <Flex align="center" gap="2">
                                <TextAlignLeftIcon style={{ color: 'var(--gray-9)' }} />
                                <Text size="2" color="gray">{upcomingHoliday.description}</Text>
                            </Flex>
                        )}
                    </Flex>
                </Card>
            )}
        </Box>
    );
};

export default UpdatesCards;