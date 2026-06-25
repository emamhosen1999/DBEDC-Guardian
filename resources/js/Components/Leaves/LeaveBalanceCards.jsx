import React, { useEffect, useState } from 'react';
import { Badge, Box, Card, Flex, Grid, Separator, Text } from '@radix-ui/themes';
import { CalendarIcon, BarChartIcon } from '@radix-ui/react-icons';
import axios from 'axios';

/**
 * Leave Balance Cards.
 *
 * Prefers the Phase-3 ledger endpoint (route('leave-balances')) — entitled / accrued /
 * taken / remaining (+ carried) per type. Falls back to the legacy prop-based counts
 * (days_used / remaining_days) when the ledger has no rows for the user/year yet.
 *
 * @param {Array}  leaveTypes      legacy: [{ type }]
 * @param {Array}  userLeaveCounts legacy: [{ leave_type, days_used, remaining_days }]
 * @param {number} [userId]        ledger lookup (defaults to the authenticated user)
 * @param {number} [year]          ledger lookup (defaults to current year)
 */
export function LeaveBalanceCards({ leaveTypes, userLeaveCounts, userId, year }) {
    const [ledger, setLedger] = useState(null);

    useEffect(() => {
        let active = true;
        const params = {};
        if (userId) params.user_id = userId;
        if (year) params.year = year;

        axios
            .get(route('leave-balances'), { params })
            .then((res) => { if (active) setLedger(res.data?.balances ?? []); })
            .catch(() => { if (active) setLedger([]); });

        return () => { active = false; };
    }, [userId, year]);

    // Ledger view (preferred when it has data).
    if (ledger && ledger.length > 0) {
        return (
            <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
                {ledger.map((b) => {
                    const total = (Number(b.entitled) || 0) + (Number(b.accrued) || 0) + (Number(b.carried) || 0);
                    const taken = Number(b.taken) || 0;
                    const pct = total > 0 ? Math.min(100, (taken / total) * 100) : 0;

                    return (
                        <Card key={b.leave_type_id ?? b.type} size="2">
                            <Flex align="center" gap="2" mb="3" justify="between">
                                <Flex align="center" gap="2" style={{ minWidth: 0 }}>
                                    <CalendarIcon style={{ color: 'var(--accent-9)' }} />
                                    <Text weight="medium" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                        {b.type}
                                    </Text>
                                </Flex>
                                {Number(b.carried) > 0 && (
                                    <Badge color="indigo" variant="soft" size="1">+{b.carried} carried</Badge>
                                )}
                            </Flex>
                            <Flex justify="between" align="center" mb="2">
                                <Box style={{ textAlign: 'center' }}>
                                    <Text size="1" color="gray">Taken</Text>
                                    <Text size="5" weight="bold" color="red" style={{ display: 'block' }}>{taken}</Text>
                                </Box>
                                <Separator orientation="vertical" size="2" />
                                <Box style={{ textAlign: 'center' }}>
                                    <Text size="1" color="gray">Remaining</Text>
                                    <Text size="5" weight="bold" color="green" style={{ display: 'block' }}>{b.remaining}</Text>
                                </Box>
                            </Flex>
                            <Text size="1" color="gray" as="div" mb="2">
                                Entitled {b.entitled}{Number(b.accrued) > 0 ? ` · Accrued ${b.accrued}` : ''}
                            </Text>
                            {total > 0 && (
                                <Box style={{ height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                    <Box style={{ height: '100%', width: `${pct}%`, background: 'var(--red-9)', borderRadius: 'var(--radius-1)' }} />
                                </Box>
                            )}
                        </Card>
                    );
                })}
            </Grid>
        );
    }

    // Legacy fallback (ledger not yet seeded for this user/year).
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
                const leaveCount = userLeaveCounts?.find((count) => count.leave_type === type) || {};
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
                                <Text size="5" weight="bold" color="red" style={{ display: 'block' }}>{usedDays}</Text>
                            </Box>
                            <Separator orientation="vertical" size="2" />
                            <Box style={{ textAlign: 'center' }}>
                                <Text size="1" color="gray">Remaining</Text>
                                <Text size="5" weight="bold" color="green" style={{ display: 'block' }}>{remainingDays}</Text>
                            </Box>
                        </Flex>
                        {totalDays > 0 && (
                            <Box style={{ height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                <Box style={{ height: '100%', width: `${(usedDays / totalDays) * 100}%`, background: 'var(--red-9)', borderRadius: 'var(--radius-1)' }} />
                            </Box>
                        )}
                    </Card>
                );
            })}
        </Grid>
    );
}

export default LeaveBalanceCards;
