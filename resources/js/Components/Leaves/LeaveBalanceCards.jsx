import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useState } from 'react';
import { Badge, Box, Flex, Grid, Separator, Text } from '@radix-ui/themes';
import axios from 'axios';

const getLeaveTypeConfig = (type) => {
    const t = type?.toLowerCase() || '';
    if (t.includes('sick') || t.includes('medical')) {
        return {
            color: 'red',
            icon: '🩺',
            bg: 'linear-gradient(135deg, var(--red-a2) 0%, var(--red-a1) 100%)',
            border: '1px solid var(--red-a5)',
            accent: 'var(--red-9)',
            accentGlow: '0 0 8px var(--red-a3)',
        };
    }
    if (t.includes('casual') || t.includes('personal')) {
        return {
            color: 'blue',
            icon: '🏖️',
            bg: 'linear-gradient(135deg, var(--blue-a2) 0%, var(--blue-a1) 100%)',
            border: '1px solid var(--blue-a5)',
            accent: 'var(--blue-9)',
            accentGlow: '0 0 8px var(--blue-a3)',
        };
    }
    if (t.includes('earned') || t.includes('annual') || t.includes('vacation')) {
        return {
            color: 'green',
            icon: '🌴',
            bg: 'linear-gradient(135deg, var(--green-a2) 0%, var(--green-a1) 100%)',
            border: '1px solid var(--green-a5)',
            accent: 'var(--green-9)',
            accentGlow: '0 0 8px var(--green-a3)',
        };
    }
    return {
        color: 'amber',
        icon: '📝',
        bg: 'linear-gradient(135deg, var(--amber-a2) 0%, var(--amber-a1) 100%)',
        border: '1px solid var(--amber-a5)',
        accent: 'var(--amber-9)',
        accentGlow: '0 0 8px var(--amber-a3)',
    };
};

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

    if (ledger && ledger.length > 0) {
        return (
            <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
                {ledger.map((b) => {
                    const cfg = getLeaveTypeConfig(b.type);
                    const total = (Number(b.entitled) || 0) + (Number(b.accrued) || 0) + (Number(b.carried) || 0);
                    const taken = Number(b.taken) || 0;
                    const pct = total > 0 ? Math.min(100, (taken / total) * 100) : 0;

                    return (
                        <Panel 
                            key={b.leave_type_id ?? b.type} 
                            size="2"
                            style={{
                                background: cfg.bg,
                                border: cfg.border,
                                transition: 'all 0.25s ease',
                                boxShadow: 'var(--shadow-1)',
                            }}
                        >
                            <Flex align="center" gap="2" mb="3" justify="between">
                                <Flex align="center" gap="2" style={{ minWidth: 0 }}>
                                    <Text size="4" style={{ lineHeight: 1 }}>{cfg.icon}</Text>
                                    <Text weight="bold" size="2" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--gray-12)' }}>
                                        {b.type}
                                    </Text>
                                </Flex>
                                {Number(b.carried) > 0 && (
                                    <Badge color="indigo" variant="soft" size="1">+{b.carried} carried</Badge>
                                )}
                            </Flex>
                            <Flex justify="between" align="center" mb="3">
                                <Box style={{ textAlign: 'center', flex: 1 }}>
                                    <Text size="1" color="gray" weight="medium">Taken</Text>
                                    <Text size="5" weight="bold" color="red" style={{ display: 'block', mt: 1 }}>{taken}</Text>
                                </Box>
                                <Separator orientation="vertical" size="2" />
                                <Box style={{ textAlign: 'center', flex: 1 }}>
                                    <Text size="1" color="gray" weight="medium">Remaining</Text>
                                    <Text size="5" weight="bold" color="green" style={{ display: 'block', mt: 1 }}>{b.remaining}</Text>
                                </Box>
                            </Flex>
                            <Text size="1" color="gray" as="div" mb="2" weight="medium" style={{ opacity: 0.85 }}>
                                Entitled {b.entitled}{Number(b.accrued) > 0 ? ` · Accrued ${b.accrued}` : ''}
                            </Text>
                            {total > 0 && (
                                <Box style={{ height: 6, borderRadius: 'var(--radius-full)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                    <Box style={{ 
                                        height: '100%', 
                                        width: `${pct}%`, 
                                        background: cfg.accent, 
                                        borderRadius: 'var(--radius-full)',
                                        boxShadow: cfg.accentGlow,
                                    }} />
                                </Box>
                            )}
                        </Panel>
                    );
                })}
            </Grid>
        );
    }

    if (!leaveTypes?.length) {
        return (
            <Flex direction="column" align="center" py="6" gap="2">
                <Text size="6">📊</Text>
                <Text color="gray">No leave types available</Text>
            </Flex>
        );
    }

    return (
        <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
            {leaveTypes.map(({ type }) => {
                const cfg = getLeaveTypeConfig(type);
                const leaveCount = userLeaveCounts?.find((count) => count.leave_type === type) || {};
                const usedDays = leaveCount.days_used || 0;
                const remainingDays = leaveCount.remaining_days || 0;
                const totalDays = usedDays + remainingDays;
                const pct = totalDays > 0 ? Math.min(100, (usedDays / totalDays) * 100) : 0;

                return (
                    <Panel 
                        key={type} 
                        size="2"
                        style={{
                            background: cfg.bg,
                            border: cfg.border,
                            transition: 'all 0.25s ease',
                            boxShadow: 'var(--shadow-1)',
                        }}
                    >
                        <Flex align="center" gap="2" mb="3">
                            <Text size="4" style={{ lineHeight: 1 }}>{cfg.icon}</Text>
                            <Text weight="bold" size="2" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--gray-12)' }}>
                                {type}
                            </Text>
                        </Flex>
                        <Flex justify="between" align="center" mb="3">
                            <Box style={{ textAlign: 'center', flex: 1 }}>
                                <Text size="1" color="gray" weight="medium">Used</Text>
                                <Text size="5" weight="bold" color="red" style={{ display: 'block', mt: 1 }}>{usedDays}</Text>
                            </Box>
                            <Separator orientation="vertical" size="2" />
                            <Box style={{ textAlign: 'center', flex: 1 }}>
                                <Text size="1" color="gray" weight="medium">Remaining</Text>
                                <Text size="5" weight="bold" color="green" style={{ display: 'block', mt: 1 }}>{remainingDays}</Text>
                            </Box>
                        </Flex>
                        {totalDays > 0 && (
                            <Box style={{ height: 6, borderRadius: 'var(--radius-full)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                <Box style={{ 
                                    height: '100%', 
                                    width: `${pct}%`, 
                                    background: cfg.accent, 
                                    borderRadius: 'var(--radius-full)',
                                    boxShadow: cfg.accentGlow,
                                }} />
                            </Box>
                        )}
                    </Panel>
                );
            })}
        </Grid>
    );
}

export default LeaveBalanceCards;
