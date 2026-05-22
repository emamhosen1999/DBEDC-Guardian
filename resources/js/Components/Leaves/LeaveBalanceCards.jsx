import React from 'react';
import { Box, Card, Flex, Grid, Separator, Text } from '@radix-ui/themes';
import { CalendarIcon, BarChartIcon } from '@radix-ui/react-icons';

/**
 * Leave Balance Cards Component
 * Displays leave type balance information with progress bars
 * @param {Object} props
 * @param {Array} props.leaveTypes - Array of leave types
 * @param {Array} props.userLeaveCounts - User's leave counts by type
 */
export function LeaveBalanceCards({ leaveTypes, userLeaveCounts }) {
    if (!leaveTypes?.length) {
        return (
            <Flex direction="column" align="center" py="6" gap="2">
                <BarChartIcon style={{ width: 40, height: 40, color: 'var(--gray-8)' }} />
                <Text color="gray">No leave types available</Text>
            </Flex>
        );
    }

    return (
        <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
            {leaveTypes.map(({ type }) => {
                const leaveCount = userLeaveCounts?.find(count => count.leave_type === type) || {};
                const usedDays = leaveCount.days_used || 0;
                const remainingDays = leaveCount.remaining_days || 0;
                const totalDays = usedDays + remainingDays;

                return (
                    <Card key={type} size="2">
                        <Flex align="center" gap="2" mb="3">
                            <CalendarIcon style={{ color: 'var(--accent-9)' }} />
                            <Text weight="medium" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {type}
                            </Text>
                        </Flex>
                        <Flex justify="between" align="center" mb="2">
                            <Box style={{ textAlign: 'center' }}>
                                <Text size="1" color="gray">Used</Text>
                                <Text size="5" weight="bold" color="red" style={{ display: 'block' }}>
                                    {usedDays}
                                </Text>
                            </Box>
                            <Separator orientation="vertical" size="2" />
                            <Box style={{ textAlign: 'center' }}>
                                <Text size="1" color="gray">Remaining</Text>
                                <Text size="5" weight="bold" color="green" style={{ display: 'block' }}>
                                    {remainingDays}
                                </Text>
                            </Box>
                        </Flex>
                        {totalDays > 0 && (
                            <Box style={{ height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                <Box 
                                    style={{ 
                                        height: '100%', 
                                        width: `${(usedDays / totalDays) * 100}%`, 
                                        background: 'var(--red-9)', 
                                        borderRadius: 'var(--radius-1)' 
                                    }} 
                                />
                            </Box>
                        )}
                    </Card>
                );
            })}
        </Grid>
    );
}

export default LeaveBalanceCards;
