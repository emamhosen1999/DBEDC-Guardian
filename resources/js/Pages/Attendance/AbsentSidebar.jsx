import React from 'react';
import {
    Box, Flex, Text, Badge, Avatar, Button,
    ScrollArea, Skeleton, Card, Spinner,
} from '@radix-ui/themes';
import {
    CrossCircledIcon,
    PersonIcon,
    CheckCircledIcon,
    CalendarIcon,
} from '@radix-ui/react-icons';

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
    getUserLeave,
    isLoaded = true,
    onMarkAsPresent,
    markingId = null,
    selectedDate,
    canManage = false,
}) => {
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
                    ) : absentUsers.length === 0 ? (
                        <Flex direction="column" align="center" justify="center" py="8" gap="2">
                            <CheckCircledIcon style={{ color: 'var(--green-9)', width: 28, height: 28 }} />
                            <Text size="2" color="gray" align="center">
                                All employees accounted for
                            </Text>
                        </Flex>
                    ) : (
                        <Flex direction="column" gap="1">
                            {absentUsers.map((user) => {
                                const leave = getUserLeave?.(user.id || user.user_id);
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

                                                {leave ? (
                                                    <Flex align="center" gap="1">
                                                        <CalendarIcon style={{ width: 10, height: 10, color: 'var(--gray-9)' }} />
                                                        <Badge color={getLeaveColor(leave.status)} variant="soft" size="1">
                                                            {leave.leave_type || 'Leave'} · {leave.status}
                                                        </Badge>
                                                    </Flex>
                                                ) : (
                                                    <Flex align="center" gap="1">
                                                        <PersonIcon style={{ width: 10, height: 10, color: 'var(--gray-8)' }} />
                                                        <Text size="1" color="gray">No leave filed</Text>
                                                    </Flex>
                                                )}

                                                {canManage && onMarkAsPresent && (
                                                    <Button
                                                        size="1"
                                                        variant="soft"
                                                        color="green"
                                                        style={{ marginTop: 2, width: '100%' }}
                                                        disabled={markingId === user.id}
                                                        onClick={() => onMarkAsPresent(user, selectedDate)}
                                                    >
                                                        {markingId === user.id
                                                            ? <Spinner size="1" />
                                                            : <CheckCircledIcon width={11} height={11} />}
                                                        {markingId === user.id ? 'Marking…' : 'Mark Present'}
                                                    </Button>
                                                )}
                                            </Flex>
                                        </Flex>
                                    </Box>
                                );
                            })}
                        </Flex>
                    )}
                </Box>
            </ScrollArea>
        </Flex>
    );
};

export default AbsentSidebar;
