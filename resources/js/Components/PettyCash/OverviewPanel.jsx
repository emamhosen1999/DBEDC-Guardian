/**
 * OverviewPanel.jsx
 * Displays petty cash loan summary with stats cards.
 * Pure Radix UI.
 */
import React from 'react';
import { Box, Card, Flex, Grid, Text, Badge, Button, Separator } from '@radix-ui/themes';
import {
    DotsHorizontalIcon, ArrowDownIcon, ArrowUpIcon, CheckIcon,
    PlusIcon, FileTextIcon
} from '@radix-ui/react-icons';

const OverviewPanel = ({ activeLoan, isMobile, onCreateLoan }) => {
    if (!activeLoan) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <DotsHorizontalIcon style={{ width: 64, height: 64, color: 'var(--gray-8)', marginBottom: '16px' }} />
                <Text size="5" weight="bold" mb="2">No Active Loan</Text>
                <Text size="2" color="gray" mb="4">
                    Create a petty cash loan to start tracking office expenses
                </Text>
                <Button onClick={onCreateLoan}>
                    <PlusIcon style={{ marginRight: '8px' }} />
                    Create Loan
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
            {/* Status Badge */}
            <Flex align="center" justify="between" mb="4">
                <Flex align="center" gap="2">
                    <Text size="2" color="gray">STATUS:</Text>
                    <Badge color={activeLoan.status === 'active' ? 'green' : 'gray'} variant="soft">
                        {activeLoan.status.toUpperCase()}
                    </Badge>
                </Flex>
                <Text size="2" color="gray">
                    LOAN DATE: {new Date(activeLoan.loan_date).toLocaleDateString()}
                </Text>
            </Flex>

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
                                    ${parseFloat(stat.value).toFixed(2)}
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
