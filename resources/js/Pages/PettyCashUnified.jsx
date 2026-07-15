/**
 * PettyCashUnified.jsx
 * Petty Cash Management shell — tabbed, multi-fund, pure Radix UI.
 * Supports multiple active funds per user with fund selector.
 */
import React, { useState, useCallback, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge, Box, Card, Flex, Heading,
    Separator, Tabs, Text, ScrollArea, Select
} from '@radix-ui/themes';
import {
    DashboardIcon, ListBulletIcon, BarChartIcon,
    ReaderIcon, LayersIcon, PlusIcon, BackpackIcon,
    ActivityLogIcon
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import OverviewPanel from '@/Components/PettyCash/OverviewPanel.jsx';
import TransactionsPanel from '@/Components/PettyCash/TransactionsPanel.jsx';
import AnalyticsPanel from '@/Components/PettyCash/AnalyticsPanel.jsx';
import HistoryPanel from '@/Components/PettyCash/HistoryPanel.jsx';
import ManagerPanel from '@/Components/PettyCash/ManagerPanel.jsx';
import AuditLogPanel from '@/Components/PettyCash/AuditLogPanel.jsx';
import PettyCashLoanForm from '@/Forms/PettyCashLoanForm.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';

const PettyCashUnified = ({ title, activeLoans = [], pendingLoans = [], canApprove, categories = {} }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');

    const [activeTab, setActiveTab] = useState('overview');
    const [showLoanForm, setShowLoanForm] = useState(false);
    const [selectedFundId, setSelectedFundId] = useState(
        activeLoans.length > 0 ? String(activeLoans[0].id) : ''
    );

    // Backward compat: support old single-loan prop format
    const activeLoansList = useMemo(() => {
        if (activeLoans && activeLoans.length > 0) return activeLoans;
        return [];
    }, [activeLoans]);

    const pendingLoansList = useMemo(() => {
        if (pendingLoans && pendingLoans.length > 0) return pendingLoans;
        return [];
    }, [pendingLoans]);

    const selectedLoan = useMemo(() => {
        if (!selectedFundId) return activeLoansList[0] || null;
        return activeLoansList.find(l => String(l.id) === selectedFundId) || activeLoansList[0] || null;
    }, [selectedFundId, activeLoansList]);

    const handleLoanCreated = useCallback(() => {
        setShowLoanForm(false);
        router.reload();
    }, []);

    const handleFundChange = (value) => {
        setSelectedFundId(value);
    };

    const hasActiveLoans = activeLoansList.length > 0;
    const hasPendingLoans = pendingLoansList.length > 0;
    const showCreateButton = true; // Always allow creating new funds

    return (
        <>
            <Head title={title || 'Petty Cash Management'} />

            <Flex justify="center" p={{ initial: '3', md: '5' }}>
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card size="3" style={{
                        boxShadow: 'var(--shadow-3)',
                        borderRadius: 'var(--radius-4)',
                        border: '1px solid var(--gray-a3)'
                    }}>

                        {/* ── Page Header ── */}
                        <Box mb="5">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'linear-gradient(135deg, var(--accent-a3) 0%, var(--accent-a2) 100%)',
                                        borderRadius: 'var(--radius-3)',
                                        border: '1px solid var(--accent-a5)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                        boxShadow: '0 4px 12px var(--accent-a2)'
                                    }}>
                                        <BackpackIcon style={{ width: 24, height: 24, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ letterSpacing: '-0.02em', color: 'var(--gray-12)' }}>Petty Cash Management</Heading>
                                        <Text size="2" color="gray" style={{ display: 'block', mt: 0.5 }}>
                                            Track office expenses, reimbursements, and fund balances
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Fund selector + Create button */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {/* Fund Selector — only show when multiple active funds */}
                                    {activeLoansList.length > 1 && (
                                        <Select.Root value={selectedFundId} onValueChange={handleFundChange}>
                                            <Select.Trigger placeholder="Select Fund" />
                                            <Select.Content>
                                                {activeLoansList.map(loan => (
                                                    <Select.Item key={loan.id} value={String(loan.id)}>
                                                        {loan.fund_name || 'General Fund'} — ৳{parseFloat(loan.current_balance).toLocaleString()}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    )}

                                    {showCreateButton && (
                                        <button
                                            onClick={() => setShowLoanForm(true)}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: '8px',
                                                padding: '8px 16px',
                                                backgroundColor: 'var(--accent-9)',
                                                color: 'var(--accent-contrast)',
                                                border: 'none',
                                                borderRadius: 'var(--radius-2)',
                                                cursor: 'pointer',
                                                fontSize: '14px',
                                                fontWeight: '500',
                                            }}
                                        >
                                            <PlusIcon style={{ width: 16, height: 16 }} />
                                            {!isMobile && (hasActiveLoans ? 'New Fund' : 'Request Loan')}
                                        </button>
                                    )}
                                </Flex>
                            </Flex>

                            {/* Active fund badges */}
                            {activeLoansList.length > 0 && (
                                <Flex gap="2" mt="3" wrap="wrap">
                                    {activeLoansList.map(loan => (
                                        <Badge
                                            key={loan.id}
                                            color={selectedLoan?.id === loan.id ? 'blue' : 'gray'}
                                            variant={selectedLoan?.id === loan.id ? 'solid' : 'soft'}
                                            size="2"
                                            style={{ cursor: 'pointer' }}
                                            onClick={() => setSelectedFundId(String(loan.id))}
                                        >
                                            {loan.fund_name || 'General Fund'}: ৳{parseFloat(loan.current_balance).toLocaleString()}
                                        </Badge>
                                    ))}
                                </Flex>
                            )}
                        </Box>

                        <Separator size="4" mb="5" style={{ background: 'var(--gray-a3)' }} />

                        {/* ── Tabs ── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>

                            <ScrollArea type="auto" scrollbars="horizontal">
                                <Tabs.List mb="4" style={{ whiteSpace: 'nowrap', width: 'max-content', minWidth: '100%' }}>

                                    <Tabs.Trigger value="overview">
                                        <Flex align="center" gap="2">
                                            <DashboardIcon />
                                            {!isMobile && 'Overview'}
                                        </Flex>
                                    </Tabs.Trigger>

                                    {selectedLoan && (
                                        <Tabs.Trigger value="transactions">
                                            <Flex align="center" gap="2">
                                                <ListBulletIcon />
                                                {!isMobile && 'Transactions'}
                                            </Flex>
                                        </Tabs.Trigger>
                                    )}

                                    {selectedLoan && (
                                        <Tabs.Trigger value="analytics">
                                            <Flex align="center" gap="2">
                                                <BarChartIcon />
                                                {!isMobile && 'Analytics'}
                                            </Flex>
                                        </Tabs.Trigger>
                                    )}

                                    <Tabs.Trigger value="history">
                                        <Flex align="center" gap="2">
                                            <ReaderIcon />
                                            {!isMobile && 'Loan History'}
                                        </Flex>
                                    </Tabs.Trigger>

                                    {canApprove && (
                                        <Tabs.Trigger value="manager">
                                            <Flex align="center" gap="2">
                                                <LayersIcon />
                                                {!isMobile && 'Manager Dashboard'}
                                            </Flex>
                                        </Tabs.Trigger>
                                    )}

                                    {selectedLoan && (
                                        <Tabs.Trigger value="audit">
                                            <Flex align="center" gap="2">
                                                <ActivityLogIcon />
                                                {!isMobile && 'Audit Log'}
                                            </Flex>
                                        </Tabs.Trigger>
                                    )}

                                </Tabs.List>
                            </ScrollArea>

                            {/* ── Tab Contents ── */}
                            <Tabs.Content value="overview">
                                <ErrorBoundary>
                                    <OverviewPanel
                                        activeLoan={selectedLoan}
                                        pendingLoans={pendingLoansList}
                                        allActiveLoans={activeLoansList}
                                        isMobile={isMobile}
                                        onCreateLoan={() => setShowLoanForm(true)}
                                        onRefresh={() => router.reload()}
                                        onSelectFund={(id) => setSelectedFundId(String(id))}
                                    />
                                </ErrorBoundary>
                            </Tabs.Content>

                            {selectedLoan && (
                                <Tabs.Content value="transactions">
                                    <ErrorBoundary>
                                        <TransactionsPanel
                                            loanId={selectedLoan.id}
                                            isMobile={isMobile}
                                            onRefreshLoan={() => router.reload()}
                                            categories={categories}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {selectedLoan && (
                                <Tabs.Content value="analytics">
                                    <ErrorBoundary>
                                        <AnalyticsPanel
                                            loanId={selectedLoan.id}
                                            isMobile={isMobile}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            <Tabs.Content value="history">
                                <ErrorBoundary>
                                    <HistoryPanel isMobile={isMobile} />
                                </ErrorBoundary>
                            </Tabs.Content>

                            {canApprove && (
                                <Tabs.Content value="manager">
                                    <ErrorBoundary>
                                        <ManagerPanel isMobile={isMobile} onRefresh={() => router.reload()} />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {selectedLoan && (
                                <Tabs.Content value="audit">
                                    <ErrorBoundary>
                                        <AuditLogPanel loanId={selectedLoan.id} isMobile={isMobile} />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                        </Tabs.Root>
                    </Card>
                </Box>
            </Flex>

            {/* Loan Form Dialog */}
            {showLoanForm && (
                <PettyCashLoanForm
                    open={showLoanForm}
                    onClose={() => setShowLoanForm(false)}
                    onSuccess={handleLoanCreated}
                />
            )}
        </>
    );
};

PettyCashUnified.layout = page => <App>{page}</App>;
export default PettyCashUnified;
