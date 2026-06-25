/**
 * BalancesPanel.jsx
 * Admin view of a selected employee's leave balances (ledger cards) + immutable
 * transaction history. Reads the Phase-3 ledger via /leave-balances + /leave-ledger.
 */
import React, { useEffect, useMemo, useState } from 'react';
import {
    Badge, Box, Card, Flex, Select, Separator, Spinner, Table, Text, TextField,
} from '@radix-ui/themes';
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
    const [year, setYear] = useState(String(thisYear));
    const [search, setSearch] = useState('');
    const [txns, setTxns] = useState([]);
    const [loading, setLoading] = useState(false);

    const filteredUsers = useMemo(() => {
        const q = search.toLowerCase();
        return (allUsers || []).filter(u =>
            !q || u.name?.toLowerCase().includes(q) || String(u.employee_id ?? '').toLowerCase().includes(q)
        );
    }, [allUsers, search]);

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
                    <TextField.Root size="2" placeholder="Search name or ID..." value={search} onChange={e => setSearch(e.target.value)} mb="2">
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

                    <Separator size="4" />
                    <Text size="3" weight="bold">Ledger history</Text>
                    {loading ? (
                        <Flex justify="center" py="6"><Spinner size="3" /></Flex>
                    ) : txns.length === 0 ? (
                        <Text size="2" color="gray">No ledger transactions for this employee/year.</Text>
                    ) : (
                        <Card>
                            <Table.Root variant="surface" size="1">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Transaction</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell justify="end">Amount</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell justify="end">Balance</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {txns.map(t => (
                                        <Table.Row key={t.id}>
                                            <Table.Cell>{t.created_at}</Table.Cell>
                                            <Table.Cell>{t.type}</Table.Cell>
                                            <Table.Cell>
                                                <Badge color={TXN_COLOR[t.txn_type] || 'gray'} variant="soft" size="1">
                                                    {t.txn_type.replace(/_/g, ' ')}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell justify="end">
                                                <Text color={t.amount < 0 ? 'red' : 'green'}>{t.amount > 0 ? '+' : ''}{t.amount}</Text>
                                            </Table.Cell>
                                            <Table.Cell justify="end"><Text weight="medium">{t.balance_after}</Text></Table.Cell>
                                            <Table.Cell><Text size="1" color="gray">{t.reason}</Text></Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card>
                    )}
                </>
            )}
        </Flex>
    );
}
