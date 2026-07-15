import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useState } from 'react';
import { Flex, Text, Skeleton, Box, Badge } from '@radix-ui/themes';
import { SunIcon } from '@radix-ui/react-icons';
import axios from 'axios';

export default function UpcomingHolidaysWidget({ permissions = [] }) {
    const [holidays, setHolidays] = useState([]);
    const [loading, setLoading] = useState(true);

    const canViewHolidays = permissions.includes('holidays.view');

    useEffect(() => {
        if (!canViewHolidays) {
            setLoading(false);
            return;
        }

        const fetchUpdates = async () => {
            try {
                setLoading(true);
                const response = await axios.get(route('updates'));
                if (response.data && response.data.upcomingHolidays) {
                    setHolidays(response.data.upcomingHolidays);
                }
            } catch (err) {
                console.error('Failed to fetch holidays:', err);
            } finally {
                setLoading(false);
            }
        };

        fetchUpdates();
    }, [canViewHolidays]);

    if (!canViewHolidays) return null;

    return (
        <Panel style={{ height: '105px' }}>
            <Flex direction="column" gap="2" style={{ height: '100%' }}>
                <Flex align="center" justify="between" mb="1" style={{ flexShrink: 0 }}>
                    <Flex align="center" gap="2">
                        <Box style={{
                            padding: 6, borderRadius: 'var(--radius-3)',
                            background: 'var(--amber-a3)', border: '1px solid var(--amber-a5)',
                            flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
                        }}>
                            <SunIcon style={{ color: 'var(--amber-9)', width: 14, height: 14 }} />
                        </Box>
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>Upcoming Holidays</Text>
                    </Flex>
                </Flex>

                <Box style={{ flex: 1, overflowY: 'auto', paddingRight: 4 }}>
                    {loading ? (
                        <Skeleton style={{ height: 24, width: '100%', borderRadius: 4 }} />
                    ) : holidays.length > 0 ? (
                        <Flex direction="column" gap="2">
                            {holidays.map((holiday) => (
                                <Box key={holiday.id} className="holiday-timeline-item" style={{ paddingLeft: 8 }}>
                                    <Flex align="center" justify="between" gap="2">
                                        <Text size="1" weight="bold" color="amber" truncate style={{ maxWidth: '140px' }}>
                                            {holiday.title}
                                        </Text>
                                        <Text size="1" color="gray" style={{ flexShrink: 0 }}>
                                            {holiday.from_date === holiday.to_date 
                                                ? new Date(holiday.from_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                                                : `${new Date(holiday.from_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
                                            }
                                        </Text>
                                    </Flex>
                                </Box>
                            ))}
                        </Flex>
                    ) : (
                        <Text size="1" color="gray">No upcoming holidays scheduled.</Text>
                    )}
                </Box>
            </Flex>
        </Panel>
    );
}
