import React, { useState, useMemo } from 'react';
import {
    Box, Card, Flex, Text, Heading, Badge, Avatar, Button, Spinner, TextField,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon,
    CalendarIcon,
    ClockIcon,
    PersonIcon,
    ExclamationTriangleIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    ChevronDownIcon,
} from '@radix-ui/react-icons';
import dayjs from "dayjs";


// Inline AbsentUsersCard component for the combined layout
export const AbsentUsersInlineCard = React.memo(({ absentUsers, selectedDate, getUserLeave, isLoaded = false, onMarkAsPresent }) => {
    const [visibleUsersCount, setVisibleUsersCount] = useState(5);
    const [searchTerm, setSearchTerm] = useState('');

    const filteredAbsentUsers = useMemo(() => {
        if (!searchTerm.trim()) return absentUsers;
        const s = searchTerm.toLowerCase();
        return absentUsers.filter(u =>
            (u.name?.toLowerCase() || '').includes(s) ||
            (u.employee_id?.toString() || '').includes(s) ||
            (u.email?.toLowerCase() || '').includes(s) ||
            (u.phone?.toString() || '').includes(s)
        );
    }, [absentUsers, searchTerm]);

    const handleLoadMore = () => setVisibleUsersCount(p => p + 5);
    const handleSearchChange = (e) => { setSearchTerm(e.target.value); setVisibleUsersCount(5); };

    const leaveColor = (status) => ({ approved: 'green', rejected: 'red', pending: 'amber' }[status?.toLowerCase()] || 'blue');
    const leaveIcon = (status) => {
        switch (status?.toLowerCase()) {
            case 'approved': return <CheckCircledIcon style={{ color: 'var(--green-9)' }} />;
            case 'rejected': return <CrossCircledIcon style={{ color: 'var(--red-9)' }} />;
            default: return <ClockIcon style={{ color: 'var(--amber-9)' }} />;
        }
    };

    const totalRows = filteredAbsentUsers.length;

    // Loading state
    if (!isLoaded) {
        return (
            <Flex direction="column" gap="3">
                <Flex align="center" gap="2">
                    <ClockIcon style={{ color: 'var(--gray-9)', width: 18, height: 18 }} />
                    <Heading size="3" color="gray">Loading Attendance Data...</Heading>
                </Flex>
                <Flex direction="column" align="center" justify="center" gap="3" p="6"
                    style={{ height: 300, border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)' }}>
                    <Spinner size="3" />
                    <Text size="2" color="gray">Checking attendance records...</Text>
                </Flex>
            </Flex>
        );
    }

    // Perfect attendance state
    if (isLoaded && absentUsers.length === 0) {
        return (
            <Flex direction="column" align="center" justify="center" gap="3" p="6"
                style={{ height: 300, border: '1px solid var(--green-a4)', borderRadius: 'var(--radius-3)', background: 'var(--green-a2)' }}
                role="region" aria-label="No absent employees today">
                <CheckCircledIcon style={{ color: 'var(--green-9)', width: 40, height: 40 }} />
                <Heading size="3" color="green">Perfect Attendance!</Heading>
                <Text size="2" color="gray" align="center">
                    No employees are absent on {dayjs(selectedDate).format('MMMM D, YYYY')}.
                </Text>
                <Text size="1" color="gray" align="center">All employees are either present or on approved leave.</Text>
            </Flex>
        );
    }

    return (
        <Flex direction="column" gap="2" style={{ height: '100%' }}>
            {/* Header */}
            <Flex align="center" gap="2" mb="1">
                <CrossCircledIcon style={{ color: 'var(--red-9)', width: 18, height: 18 }} />
                <Heading size="3" color="red">Absent Employees ({totalRows})</Heading>
            </Flex>

            {/* Search */}
            <TextField.Root
                size="2"
                placeholder="Search absent employees..."
                value={searchTerm}
                onChange={handleSearchChange}
                aria-label="Search absent employees"
                mb="2"
            >
                <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
            </TextField.Root>

            {/* Search count */}
            {searchTerm && (
                <Text size="1" color="gray" mb="1">
                    {filteredAbsentUsers.length} of {absentUsers.length} employees found
                </Text>
            )}

            {/* No search results */}
            {searchTerm && filteredAbsentUsers.length === 0 && (
                <Flex direction="column" align="center" justify="center" gap="2" p="4"
                    style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)' }}>
                    <MagnifyingGlassIcon style={{ color: 'var(--gray-7)', width: 28, height: 28 }} />
                    <Text size="2" color="gray">No employees found matching "{searchTerm}"</Text>
                </Flex>
            )}

            {/* User list */}
            <Box style={{ flex: 1, overflowY: 'auto', minHeight: 0, maxHeight: 'calc(100vh - 400px)' }}
                role="region" aria-label="Absent employees list">
                <Flex direction="column" gap="2">
                    {filteredAbsentUsers.slice(0, visibleUsersCount).map((user) => {
                        const userLeave = getUserLeave(user.id);
                        return (
                            <Card key={user.id} variant="surface" style={{ padding: 0 }}>
                                <Box p="3">
                                    <Flex direction="column" gap="2">
                                        <Flex align="start" justify="between" gap="2">
                                            <Flex align="center" gap="2" style={{ flex: 1, minWidth: 0 }}>
                                                <Avatar
                                                    src={user.profile_image_url || user.profile_image}
                                                    fallback={(user.name || '?').charAt(0).toUpperCase()}
                                                    size="2"
                                                    radius="full"
                                                    style={{ flexShrink: 0 }}
                                                />
                                                <Box style={{ flex: 1, minWidth: 0 }}>
                                                    <Text size="2" weight="medium" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                        {user.name}
                                                    </Text>
                                                    {user.employee_id && (
                                                        <Text size="1" color="gray" style={{ display: 'block' }}>ID: {user.employee_id}</Text>
                                                    )}
                                                    {userLeave ? (
                                                        <Flex direction="column" gap="1" mt="1">
                                                            <Flex align="center" gap="1">
                                                                <CalendarIcon style={{ color: 'var(--gray-9)', width: 12, flexShrink: 0 }} />
                                                                <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                    {userLeave.from_date === userLeave.to_date
                                                                        ? userLeave.from_date
                                                                        : `${userLeave.from_date} – ${userLeave.to_date}`}
                                                                </Text>
                                                            </Flex>
                                                            <Flex align="center" gap="1">
                                                                {leaveIcon(userLeave.status)}
                                                                <Text size="1" color={leaveColor(userLeave.status)} style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                    {userLeave.leave_type} Leave
                                                                </Text>
                                                            </Flex>
                                                        </Flex>
                                                    ) : (
                                                        <Flex align="center" gap="1" mt="1">
                                                            <ExclamationTriangleIcon style={{ color: 'var(--red-9)', width: 12, flexShrink: 0 }} />
                                                            <Text size="1" color="red">Absent without leave</Text>
                                                        </Flex>
                                                    )}
                                                </Box>
                                            </Flex>
                                            {userLeave && (
                                                <Badge color={leaveColor(userLeave.status)} variant="soft" size="1" style={{ flexShrink: 0 }}>
                                                    {userLeave.status}
                                                </Badge>
                                            )}
                                        </Flex>
                                        {onMarkAsPresent && !userLeave && (
                                            <Flex justify="end">
                                                <Button size="1" color="blue" variant="soft" onClick={() => onMarkAsPresent(user, selectedDate)}>
                                                    <PersonIcon /> Mark Present
                                                </Button>
                                            </Flex>
                                        )}
                                    </Flex>
                                </Box>
                            </Card>
                        );
                    })}
                </Flex>
                {visibleUsersCount < filteredAbsentUsers.length && (
                    <Box mt="3" pb="2">
                        <Button size="2" variant="outline" color="amber" onClick={handleLoadMore} style={{ width: '100%' }}>
                            <ChevronDownIcon /> Show More ({filteredAbsentUsers.length - visibleUsersCount} remaining)
                        </Button>
                    </Box>
                )}
            </Box>
        </Flex>
    );
});