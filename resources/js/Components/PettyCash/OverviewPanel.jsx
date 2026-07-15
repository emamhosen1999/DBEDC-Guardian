/**
 * OverviewPanel.jsx
 * Displays petty cash fund summary with stats cards.
 * Supports multiple funds. Currency: BDT (৳).
 */
import React, { useState } from 'react';
import { Box, Card, Flex, Grid, Text, Badge, Button, Separator } from '@radix-ui/themes';
import {
    DotsHorizontalIcon, ArrowDownIcon, ArrowUpIcon, CheckIcon,
    PlusIcon, FileTextIcon
} from '@radix-ui/react-icons';
import axios from 'axios';

const OverviewPanel = ({ activeLoan, pendingLoans = [], allActiveLoans = [], isMobile, onCreateLoan, onRefresh, onSelectFund }) => {
    const [closing, setClosing] = useState(false);

    const handleCloseLoan = async () => {
        if (!window.confirm('Are you sure you want to close this petty cash fund? This will freeze transaction logging for this fund.')) return;

        try {
            setClosing(true);
            const response = await axios.post('/petty-cash/loan/close', {
                loan_id: activeLoan.id
            });
            if (response.data.success) {
                if (onRefresh) onRefresh();
            } else {
                alert(response.data.error || 'Failed to close fund');
            }
        } catch (error) {
            alert(error.response?.data?.error || 'Failed to close fund');
        } finally {
            setClosing(false);
        }
    };

    // Show pending loans
    if (!activeLoan && pendingLoans.length > 0) {
        return (
            <Box p="6">
                {pendingLoans.map(pendingLoan => (
                    <Card key={pendingLoan.id} style={{ padding: '24px', maxWidth: '600px', margin: '0 auto 16px', textAlign: 'center' }}>
                        <Flex direction="column" gap="4" align="center">
                            <Box p="3" style={{ background: 'var(--orange-a3)', borderRadius: 'var(--radius-3)' }}>
                                <DotsHorizontalIcon style={{ width: 48, height: 48, color: 'var(--orange-9)' }} />
                            </Box>
                            <Box>
                                <Text size="5" weight="bold" mb="1" as="div">
                                    {pendingLoan.fund_name || 'Loan'} — Pending Review
                                </Text>
                                <Text size="2" color="gray" as="div">
                                    Your petty cash request is currently pending review by an administrator.
                                </Text>
                            </Box>

                            <Separator size="4" style={{ width: '100%' }} />

                            <Grid columns="2" gap="4" style={{ width: '100%' }}>
                                <Box style={{ textAlign: 'left' }}>
                                    <Text size="1" color="gray" weight="bold" as="div">REQUESTED AMOUNT</Text>
                                    <Text size="4" weight="bold" color="orange">৳{parseFloat(pendingLoan.original_amount).toLocaleString()}</Text>
                                </Box>
                                <Box style={{ textAlign: 'left' }}>
                                    <Text size="1" color="gray" weight="bold" as="div">REQUEST DATE</Text>
                                    <Text size="4" weight="bold">{new Date(pendingLoan.loan_date).toLocaleDateString()}</Text>
                                </Box>
                            </Grid>

                            {pendingLoan.notes && (
                                <Box style={{ width: '100%', textAlign: 'left', background: 'var(--gray-a2)', padding: '12px', borderRadius: 'var(--radius-2)' }}>
                                    <Text size="1" color="gray" weight="bold" as="div" mb="1">NOTES</Text>
                                    <Text size="2">{pendingLoan.notes}</Text>
                                </Box>
                            )}

                            <Badge color="orange" size="2" variant="soft">
                                STATUS: PENDING APPROVAL
                            </Badge>
                        </Flex>
                    </Card>
                ))}
                <Flex justify="center" mt="4">
                    <Button onClick={onCreateLoan} style={{ cursor: 'pointer' }}>
                        <PlusIcon style={{ marginRight: '8px' }} />
                        Create Another Fund
                    </Button>
                </Flex>
            </Box>
        );
    }

    // No active loan and no pending
    if (!activeLoan) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <DotsHorizontalIcon style={{ width: 64, height: 64, color: 'var(--gray-8)', marginBottom: '16px' }} />
                <Text size="5" weight="bold" mb="2">No Active Fund</Text>
                <Text size="2" color="gray" mb="4">
                    Create a petty cash fund to start tracking office expenses
                </Text>
                <Button onClick={onCreateLoan} style={{ cursor: 'pointer' }}>
                    <PlusIcon style={{ marginRight: '8px' }} />
                    Create Fund
                </Button>
            </Box>
        );
    }

    const stats = [
        {
            label: 'ORIGINAL AMOUNT',
            value: activeLoan.original_amount,
            icon: DotsHorizontalIcon,
            color: 'blue',
        },
        {
            label: 'CURRENT BALANCE',
            value: activeLoan.current_balance,
            icon: DotsHorizontalIcon,
            color: activeLoan.current_balance > 0 ? 'green' : 'red',
        },
        {
            label: 'TOTAL EXPENSES',
            value: activeLoan.total_expenses,
            icon: ArrowDownIcon,
            color: 'red',
        },
        {
            label: 'TOTAL REIMBURSEMENTS',
            value: activeLoan.total_reimbursements,
            icon: ArrowUpIcon,
            color: 'green',
        },
        {
            label: 'TOTAL REPAYMENTS',
            value: activeLoan.total_repayments,
            icon: CheckIcon,
            color: 'blue',
        },
    ];

    return (
        <Box>
            {/* Fund Name + Status Badge */}
            <Flex align="center" justify="between" mb="4" wrap="wrap" gap="2">
                <Flex align="center" gap="2">
                    <Text size="4" weight="bold" style={{ color: 'var(--accent-9)' }}>
                        {activeLoan.fund_name || 'General Fund'}
                    </Text>
                    <Badge color={activeLoan.status === 'active' ? 'green' : 'gray'} variant="soft">
                        {activeLoan.status.toUpperCase()}
                    </Badge>
                </Flex>

                <Flex align="center" gap="4">
                    <Text size="2" color="gray">
                        SINCE: {new Date(activeLoan.loan_date).toLocaleDateString()}
                    </Text>
                    <Button
                        size="1"
                        color="red"
                        variant="soft"
                        onClick={handleCloseLoan}
                        disabled={closing}
                        style={{ cursor: 'pointer' }}
                    >
                        {closing ? 'Closing...' : 'Close Fund'}
                    </Button>
                </Flex>
            </Flex>

            {/* Approval info */}
            {activeLoan.approver_name && (
                <Box mb="3" p="2" style={{ background: 'var(--green-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--green-a4)' }}>
                    <Text size="1" color="green">
                        ✓ Approved by <strong>{activeLoan.approver_name}</strong>
                        {activeLoan.approved_at && ` on ${new Date(activeLoan.approved_at).toLocaleDateString()}`}
                        {activeLoan.approval_comment && ` — "${activeLoan.approval_comment}"`}
                    </Text>
                </Box>
            )}

            <Separator size="4" mb="4" />

            {/* Stats Grid */}
            <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="4" mb="4">
                {stats.map((stat, index) => {
                    const Icon = stat.icon;
                    return (
                        <Card key={index} style={{ padding: '16px' }}>
                            <Flex direction="column" gap="2">
                                <Flex align="center" gap="2">
                                    <Icon style={{ width: 20, height: 20, color: `var(--${stat.color}-9)` }} />
                                    <Text size="1" weight="bold" color="gray">
                                        {stat.label}
                                    </Text>
                                </Flex>
                                <Text size="6" weight="bold" style={{ color: `var(--${stat.color}-9)` }}>
                                    ৳{parseFloat(stat.value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                </Text>
                            </Flex>
                        </Card>
                    );
                })}
            </Grid>

            {/* Transaction Count */}
            <Card style={{ padding: '16px' }}>
                <Flex align="center" justify="between">
                    <Flex align="center" gap="2">
                        <FileTextIcon style={{ width: 20, height: 20, color: 'var(--gray-9)' }} />
                        <Text size="2" weight="bold">TOTAL TRANSACTIONS</Text>
                    </Flex>
                    <Text size="4" weight="bold">{activeLoan.transaction_count}</Text>
                </Flex>
            </Card>
        </Box>
    );
};

export default OverviewPanel;
