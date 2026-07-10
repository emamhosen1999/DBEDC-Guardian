import React, { useEffect, useState } from 'react';
import { Card, Flex, Heading, Text, Skeleton, Box, Separator } from '@radix-ui/themes';
import { SunIcon, CalendarIcon, InfoCircledIcon, TextAlignLeftIcon } from '@radix-ui/react-icons';
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
        <Card style={{ height: '100%' }}>
            <Flex direction="column" gap="3" style={{ height: '100%' }}>
                <Flex align="center" gap="2">
                    <Box style={{
                        padding: 8, borderRadius: 'var(--radius-3)',
                        background: 'var(--amber-a3)', border: '1px solid var(--amber-a6)',
                        flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
                    }}>
                        <SunIcon style={{ color: 'var(--amber-9)' }} />
                    </Box>
                    <Heading size="3">Upcoming Holidays</Heading>
                </Flex>

                {loading ? (
                    <Flex direction="column" gap="3">
                        <Skeleton style={{ height: 40, width: '100%', borderRadius: 8 }} />
                        <Skeleton style={{ height: 40, width: '100%', borderRadius: 8 }} />
                    </Flex>
                ) : holidays.length > 0 ? (
                    <Flex direction="column" gap="4">
                        {holidays.map((holiday) => (
                            <Box key={holiday.id} className="holiday-timeline-item">
                                <Flex direction="column" gap="1">
                                    <Flex align="center" justify="between" gap="2">
                                        <Text size="2" weight="bold" style={{ color: 'var(--amber-11)', letterSpacing: '-0.01em' }}>
                                            {holiday.title}
                                        </Text>
                                        <Badge size="1" color="gray" variant="soft" style={{ flexShrink: 0 }}>
                                            {holiday.from_date === holiday.to_date 
                                                ? new Date(holiday.from_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                                                : `${new Date(holiday.from_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} – ${new Date(holiday.to_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`
                                            }
                                        </Badge>
                                    </Flex>
                                    {holiday.description && (
                                        <Text size="1" color="gray" style={{ lineHeight: 1.3, marginTop: 2 }}>
                                            {holiday.description}
                                        </Text>
                                    )}
                                </Flex>
                            </Box>
                        ))}
                    </Flex>
                ) : (
                    <Flex align="center" justify="center" style={{ flex: 1 }}>
                        <Text size="2" color="gray">No upcoming holidays scheduled.</Text>
                    </Flex>
                )}
            </Flex>
        </Card>
    );
}
