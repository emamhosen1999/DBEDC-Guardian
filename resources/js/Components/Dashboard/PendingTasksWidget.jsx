import React, { useEffect, useState } from 'react';
import { Card, Flex, Heading, Text, Skeleton, Box, Badge } from '@radix-ui/themes';
import { ExclamationTriangleIcon, DoubleArrowRightIcon } from '@radix-ui/react-icons';
import { Link } from '@inertiajs/react';
import axios from 'axios';

export default function PendingTasksWidget({ permissions = [] }) {
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);

    const canViewTasks = permissions.includes('daily-works.own.view') || permissions.includes('daily-works.view');

    useEffect(() => {
        if (!canViewTasks) {
            setLoading(false);
            return;
        }
        
        axios.get(route('stats'))
            .then(r => setStats(r.data?.statistics ?? null))
            .catch(() => setStats(null))
            .finally(() => setLoading(false));
    }, [canViewTasks]);

    if (!canViewTasks) return null;

    const pending = stats?.pending || 0;

    return (
        <Card style={{ height: '100%', border: pending > 0 ? '1px solid var(--amber-a6)' : undefined }}>
            <Flex direction="column" gap="3" style={{ height: '100%' }}>
                <Flex align="center" justify="between">
                    <Flex align="center" gap="2">
                        <Box style={{
                            padding: 8, borderRadius: 'var(--radius-3)',
                            background: pending > 0 ? 'var(--amber-a3)' : 'var(--gray-a3)', flexShrink: 0,
                        }}>
                            <ExclamationTriangleIcon style={{ color: pending > 0 ? 'var(--amber-9)' : 'var(--gray-9)' }} />
                        </Box>
                        <Heading size="3">Pending Tasks</Heading>
                    </Flex>
                    {pending > 0 && (
                        <Badge color="amber" variant="soft" size="2" radius="full">
                            {pending} Action{pending !== 1 ? 's' : ''} Needed
                        </Badge>
                    )}
                </Flex>

                {loading ? (
                    <Skeleton style={{ height: 60, width: '100%', borderRadius: 8 }} />
                ) : (
                    <Flex direction="column" justify="center" align="center" style={{ flex: 1, textAlign: 'center', py: 4 }}>
                        <Text size="8" weight="bold" style={{ color: pending > 0 ? 'var(--amber-11)' : 'var(--gray-11)', lineHeight: 1 }}>
                            {pending}
                        </Text>
                        <Text size="2" color="gray" mt="2">
                            {pending > 0 ? 'You have unresolved tasks.' : 'All caught up! No pending tasks.'}
                        </Text>
                    </Flex>
                )}

                <Flex justify="end" mt="auto">
                    <Link href={route('daily-works')} style={{ textDecoration: 'none' }}>
                        <Flex align="center" gap="1" style={{ color: 'var(--accent-9)', cursor: 'pointer' }}>
                            <Text size="2" weight="medium">View Tasks</Text>
                            <DoubleArrowRightIcon />
                        </Flex>
                    </Link>
                </Flex>
            </Flex>
        </Card>
    );
}
