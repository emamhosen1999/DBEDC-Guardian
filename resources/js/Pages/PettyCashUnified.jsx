/**
 * PettyCashUnified.jsx
 * Petty Cash Management shell — tabbed, pure Radix UI.
 * Aligned with LeavesUnified.jsx pattern.
 */
import React, { useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Badge, Box, Card, Flex, Heading,
    Separator, Tabs, Text, ScrollArea
} from '@radix-ui/themes';
import {
    DotsHorizontalIcon, FileTextIcon, PlusIcon
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

import OverviewPanel from '@/Components/PettyCash/OverviewPanel.jsx';
import TransactionsPanel from '@/Components/PettyCash/TransactionsPanel.jsx';
import AnalyticsPanel from '@/Components/PettyCash/AnalyticsPanel.jsx';
import PettyCashLoanForm from '@/Forms/PettyCashLoanForm.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';

const PettyCashUnified = ({ title, activeLoan }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');

    const [activeTab, setActiveTab] = useState('overview');
    const [showLoanForm, setShowLoanForm] = useState(false);

    const handleLoanCreated = useCallback((newLoan) => {
        setShowLoanForm(false);
        // Refresh data by reloading page or updating state
        window.location.reload();
    }, []);

    const headerActions = (
        !activeLoan ? (
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
                {!isMobile && 'Create Loan'}
            </button>
        ) : null
    );

    return (
        <>
            <Head title={title || 'Petty Cash Management'} />

            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Card>

                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex
                                direction={{ initial: 'column', sm: 'row' }}
                                align={{ initial: 'start', sm: 'center' }}
                                justify="between"
                                gap="4"
                            >
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{
                                        background: 'var(--accent-a3)',
                                        borderRadius: 'var(--radius-2)',
                                        border: '1px solid var(--accent-a6)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        <DotsHorizontalIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Petty Cash Management</Heading>
                                        <Text size="2" color="gray">
                                            Track office expenses, reimbursements, and loan balance
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Dynamic actions */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {headerActions}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ── Tabs ── */}
                        <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                            
                            <ScrollArea type="auto" scrollbars="horizontal">
                                <Tabs.List mb="4" style={{ whiteSpace: 'nowrap', width: 'max-content', minWidth: '100%' }}>

                                    <Tabs.Trigger value="overview">
                                        <Flex align="center" gap="2">
                                            <DotsHorizontalIcon />
                                            {!isMobile && 'Overview'}
                                        </Flex>
                                    </Tabs.Trigger>

                                    {activeLoan && (
                                        <Tabs.Trigger value="transactions">
                                            <Flex align="center" gap="2">
                                                <FileTextIcon />
                                                {!isMobile && 'Transactions'}
                                            </Flex>
                                        </Tabs.Trigger>
                                    )}

                                    {activeLoan && (
                                        <Tabs.Trigger value="analytics">
                                            <Flex align="center" gap="2">
                                                <DotsHorizontalIcon />
                                                {!isMobile && 'Analytics'}
                                            </Flex>
                                        </Tabs.Trigger>
                                    )}

                                </Tabs.List>
                            </ScrollArea>

                            {/* ── Tab Contents ── */}
                            <Tabs.Content value="overview">
                                <ErrorBoundary>
                                    <OverviewPanel
                                        activeLoan={activeLoan}
                                        isMobile={isMobile}
                                        onCreateLoan={() => setShowLoanForm(true)}
                                    />
                                </ErrorBoundary>
                            </Tabs.Content>

                            {activeLoan && (
                                <Tabs.Content value="transactions">
                                    <ErrorBoundary>
                                        <TransactionsPanel
                                            loanId={activeLoan.id}
                                            isMobile={isMobile}
                                        />
                                    </ErrorBoundary>
                                </Tabs.Content>
                            )}

                            {activeLoan && (
                                <Tabs.Content value="analytics">
                                    <ErrorBoundary>
                                        <AnalyticsPanel
                                            loanId={activeLoan.id}
                                            isMobile={isMobile}
                                        />
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
