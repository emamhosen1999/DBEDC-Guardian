import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useState } from 'react';
import { Flex, Heading, Text, Skeleton, Box } from '@radix-ui/themes';
import { CheckCircledIcon, LightningBoltIcon, ExclamationTriangleIcon } from '@radix-ui/react-icons';
import axios from 'axios';

export default function ProjectOverviewWidget({ permissions = [] }) {
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

    const total = stats?.total || 0;
    const completed = stats?.completed || 0;
    const percentage = total > 0 ? Math.round((completed / total) * 100) : 0;

    return (
        <Panel style={{ height: '260px', display: 'flex', flexDirection: 'column' }}>
            <Flex direction="column" gap="3" style={{ height: '100%', justifyContent: 'space-between' }}>
                <Flex align="center" gap="2">
                    <Box style={{
                        padding: 8, borderRadius: 'var(--radius-3)',
                        background: 'var(--blue-a3)', flexShrink: 0,
                    }}>
                        <LightningBoltIcon style={{ color: 'var(--blue-9)' }} />
                    </Box>
                    <Heading size="3">Project Progress</Heading>
                </Flex>

                {loading ? (
                    <Skeleton style={{ height: 60, width: '100%', borderRadius: 8 }} />
                ) : (
                    <>
                        <Flex justify="between" align="end">
                            <Text size="2" color="gray">Overall Completion</Text>
                            <Text size="6" weight="bold" style={{ color: 'var(--blue-11)' }}>{percentage}%</Text>
                        </Flex>

                        <Box style={{
                            width: '100%', height: 10,
                            borderRadius: 'var(--radius-full)',
                            background: 'var(--gray-a4)',
                            overflow: 'hidden',
                        }}>
                            <Box style={{
                                width: `${percentage}%`,
                                height: '100%',
                                background: 'linear-gradient(90deg, var(--blue-9) 0%, var(--indigo-9) 100%)',
                                borderRadius: 'var(--radius-full)',
                                transition: 'width 1s ease-in-out',
                                boxShadow: '0 0 10px var(--blue-a4)',
                            }} />
                        </Box>

                        <Flex gap="4" mt="2">
                            <Flex align="center" gap="2">
                                <CheckCircledIcon style={{ color: 'var(--green-9)' }} />
                                <Text size="2" color="gray">{completed} Completed</Text>
                            </Flex>
                            <Flex align="center" gap="2">
                                <ExclamationTriangleIcon style={{ color: 'var(--amber-9)' }} />
                                <Text size="2" color="gray">{total - completed} Pending</Text>
                            </Flex>
                        </Flex>
                    </>
                )}
            </Flex>
        </Panel>
    );
}
