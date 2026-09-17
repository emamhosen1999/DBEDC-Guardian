import { Panel } from '@/Components/ui/Panel';
/**
 * BalancesPanel.jsx
 * Admin view of a selected employee's leave balances (ledger cards) + immutable
 * transaction history. Reads the Phase-3 ledger via /leave-balances + /leave-ledger.
 */
import React, { useEffect, useMemo, useState } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Box, Flex, ScrollArea, Select, Separator, Spinner, Table, Text, TextField } from '@radix-ui/themes';
import { MagnifyingGlassIcon, PersonIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import LeaveBalanceCards from '@/Components/Leaves/LeaveBalanceCards.jsx';

const TXN_COLOR = {
    opening: 'blue', accrual: 'green', consumption: 'red', consumption_reversal: 'teal',
    carry_forward: 'indigo', carry_expiry: 'orange', encashment: 'purple', adjustment: 'gray',
};

export default function BalancesPanel({ allUsers = [], isActive = false }) {
    const thisYear = new Date().getFullYear();
    const years = Array.from({ length: 6 }, (_, i) => thisYear - 4 + i);

    const [userId, setUserId] = useState('');
    /* Year and search live in the URL under a `bal_` prefix. */
    const f = useQueryFilters({
        mode: 'client',
        debounceKeys: ['bal_q'],
        defaults: { bal_year: String(thisYear), bal_q: '' },
    });
    const year = f.values.bal_year;
    const setYear = (v) => f.set('bal_year', v);
    const search = f.values.bal_q;
    const [txns, setTxns] = useState([]);
    const [loading, setLoading] = useState(false);

    const filteredUsers = useMemo(() => {
        const q = search.toLowerCase();
        return (allUsers || []).filter(u =>
            !q || u.name?.toLowerCase().includes(q) || String(u.employee_id ?? '').toLowerCase().includes(q)
        );
    }, [allUsers, search]);

    useEffect(() => {
        if (!userId && allUsers?.length > 0) {
            setUserId(String(allUsers[0].id));
        }
    }, [allUsers, userId]);

    useEffect(() => {
        if (!isActive || !userId) { setTxns([]); return; }
        let active = true;
        setLoading(true);
        axios.get(route('leave-ledger'), { params: { user_id: userId, year } })
            .then(res => { if (active) setTxns(res.data?.transactions ?? []); })
            .catch(() => { if (active) setTxns([]); })
            .finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [isActive, userId, year]);

    return (
        <Flex direction="column" gap="4">
            <Flex gap="3" wrap="wrap" align="end">
                <Box style={{ flex: 1, minWidth: 220 }}>
                    <Text size="2" weight="medium" as="div" mb="1">Employee</Text>
                    <TextField.Root size="2" placeholder="Search name or ID..." value={f.draft.bal_q} onChange={e => f.setDraft('bal_q', e.target.value)} mb="2">
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                    <Select.Root value={userId} onValueChange={setUserId}>
                        <Select.Trigger placeholder="Select an employee" style={{ width: '100%' }} />
                        <Select.Content>
                            {filteredUsers.map(u => (
                                <Select.Item key={u.id} value={String(u.id)}>
                                    {u.name}{u.employee_id ? ` (${u.employee_id})` : ''}
                                </Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Box>
                <Box style={{ width: 120 }}>
                    <Text size="2" weight="medium" as="div" mb="1">Year</Text>
                    <Select.Root value={year} onValueChange={setYear}>
                        <Select.Trigger style={{ width: '100%' }} />
                        <Select.Content>
                            {years.map(y => <Select.Item key={y} value={String(y)}>{y}</Select.Item>)}
                        </Select.Content>
                    </Select.Root>
                </Box>
            </Flex>

            {!userId ? (
                <Flex direction="column" align="center" py="8" gap="2">
                    <PersonIcon style={{ width: 40, height: 40, color: 'var(--gray-8)' }} />
                    <Text color="gray">Select an employee to view their leave balances and ledger.</Text>
                </Flex>
            ) : (
                <>
                    <LeaveBalanceCards userId={Number(userId)} year={Number(year)} leaveTypes={[]} userLeaveCounts={[]} />

                    <Separator size="4" style={{ margin: '24px 0' }} />
                    <Flex align="center" gap="2" mb="3">
                        <Text size="3" weight="bold" style={{ color: 'var(--gray-12)' }}>Ledger Transaction History</Text>
                    </Flex>
                    {loading ? (
                        <Flex justify="center" py="8"><Spinner size="3" /></Flex>
                    ) : txns.length === 0 ? (
                        <Panel tinted style={{ padding: 24, textAlign: 'center' }}>
                            <Text size="2" color="gray">No ledger transactions found for this employee/year.</Text>
                        </Panel>
                    ) : (
                        <Panel p="0" style={{ overflow: 'hidden', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                            <ScrollArea type="auto" scrollbars="horizontal">
                                <Table.Root size="2" style={{ width: '100%', minWidth: 700 }}>
                                    <Table.Header style={{ backgroundColor: 'var(--gray-a2)' }}>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Date</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ minWidth: 130 }}>Leave Type</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Transaction Type</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell justify="end" style={{ textAlign: 'right', minWidth: 100 }}>Amount</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell justify="end" style={{ textAlign: 'right', minWidth: 120 }}>Balance After</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ minWidth: 160 }}>Reason</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {txns.map(t => (
                                            <Table.Row key={t.id} style={{ transition: 'background 0.2s ease' }}>
                                                <Table.Cell style={{ verticalAlign: 'middle' }}>
                                                    <Text size="2" color="gray" style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{t.created_at}</Text>
                                                </Table.Cell>
                                                <Table.Cell style={{ verticalAlign: 'middle' }}>
                                                    <Text size="2" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, color: 'var(--gray-12)', whiteSpace: 'nowrap' }}>{t.type}</Text>
                                                </Table.Cell>
                                                <Table.Cell style={{ verticalAlign: 'middle' }}>
                                                    <Badge color={TXN_COLOR[t.txn_type] || 'gray'} variant="soft" size="1" style={{ textTransform: 'capitalize', fontWeight: 600, borderRadius: 999 }}>
                                                        {t.txn_type.replace(/_/g, ' ')}
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell style={{ verticalAlign: 'middle', textAlign: 'right' }}>
                                                    <Text color={t.amount < 0 ? 'red' : 'green'} weight="bold" size="2" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                                                        {t.amount > 0 ? '+' : ''}{t.amount} {Math.abs(t.amount) === 1 ? 'day' : 'days'}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell style={{ verticalAlign: 'middle', textAlign: 'right' }}>
                                                    <Badge size="1" variant="soft" color="blue" style={{ fontWeight: 700, borderRadius: 999, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums' }}>
                                                        {t.balance_after} {t.balance_after === 1 ? 'day' : 'days'}
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell style={{ verticalAlign: 'middle' }}>
                                                    <Text size="2" color="gray" style={{ fontStyle: t.reason ? 'normal' : 'italic' }}>
                                                        {t.reason || 'No description provided'}
                                                    </Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </ScrollArea>
                        </Panel>
                    )}
                </>
            )}
        </Flex>
    );
}
