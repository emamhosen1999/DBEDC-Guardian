import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useState } from 'react';
import { Flex, Skeleton, Box, Badge, Text } from '@radix-ui/themes';
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
        <Panel style={{ height: '105px', border: pending > 0 ? '1px solid var(--amber-a5)' : undefined }}>
            <Flex direction="column" justify="between" style={{ height: '100%' }}>
                <Flex align="center" justify="between">
                    <Flex align="center" gap="2">
                        <Box style={{
                            padding: 6, borderRadius: 'var(--radius-3)',
                            background: pending > 0 ? 'var(--amber-a3)' : 'var(--gray-a3)', flexShrink: 0,
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                        }}>
                            <ExclamationTriangleIcon style={{ color: pending > 0 ? 'var(--amber-9)' : 'var(--gray-9)', width: 14, height: 14 }} />
                        </Box>
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>Pending Tasks</Text>
                    </Flex>
                    {loading ? (
                        <Skeleton style={{ width: 36, height: 20 }} />
                    ) : (
                        <Badge color={pending > 0 ? 'amber' : 'gray'} variant="soft" size="2" radius="full" style={{ fontWeight: 700 }}>
                            {pending}
                        </Badge>
                    )}
                </Flex>

                <Flex align="center" justify="between">
                    <Text size="1" color="gray">
                        {pending > 0 ? 'Requires attention.' : 'All caught up!'}
                    </Text>
                    <Link href={route('daily-works-unified')} style={{ textDecoration: 'none' }}>
                        <Flex align="center" gap="1" style={{ color: 'var(--accent-9)', cursor: 'pointer' }}>
                            <Text size="1" weight="bold">View Tasks</Text>
                            <DoubleArrowRightIcon style={{ width: 12, height: 12 }} />
                        </Flex>
                    </Link>
                </Flex>
            </Flex>
        </Panel>
    );
}
