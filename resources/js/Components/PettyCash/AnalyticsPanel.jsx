/**
 * AnalyticsPanel.jsx
 * Displays petty cash analytics with charts and summary statistics.
 * Pure Radix UI with Recharts for visualizations.
 */
import React, { useState, useEffect } from 'react';
import { Box, Card, Flex, Grid, Text, Badge } from '@radix-ui/themes';
import { PieChartIcon, ArrowUpIcon, DotsHorizontalIcon } from '@radix-ui/react-icons';
import axios from 'axios';

const AnalyticsPanel = ({ loanId, isMobile }) => {
    const [analytics, setAnalytics] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchAnalytics = async () => {
            if (!loanId) {
                setLoading(false);
                return;
            }
            try {
                setLoading(true);
                const response = await axios.get('/petty-cash/analytics', {
                    params: { loan_id: loanId },
                });
                setAnalytics(response.data.analytics);
            } catch (error) {
                console.error('Failed to fetch analytics:', error);
            } finally {
                setLoading(false);
            }
        };

        fetchAnalytics();
    }, [loanId]);

    if (loading) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">Loading analytics...</Text>
            </Box>
        );
    }

    if (!analytics) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">No analytics data available</Text>
            </Box>
        );
    }

    const categoryBreakdown = analytics.category_breakdown || {};
    const monthlyTrends = analytics.monthly_trends || {};
    const typeDistribution = analytics.type_distribution || {};

    const categoryColors = {
        office_supplies: 'purple',
        meeting_supplies: 'orange',
        office_maintenance: 'yellow',
        services: 'cyan',
    };

    return (
        <Box>
            {/* Summary Cards */}
            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="4" mb="6">
                <Card style={{ padding: '16px' }}>
                    <Flex direction="column" gap="2">
                        <Flex align="center" gap="2">
                            <DotsHorizontalIcon style={{ width: 20, height: 20, color: 'var(--blue-9)' }} />
                            <Text size="1" weight="bold" color="gray">LOAN TAKEN</Text>
                        </Flex>
                        <Text size="5" weight="bold" color="blue">
                            ${parseFloat(typeDistribution.loan_taken || 0).toFixed(2)}
                        </Text>
                    </Flex>
                </Card>

                <Card style={{ padding: '16px' }}>
                    <Flex direction="column" gap="2">
                        <Flex align="center" gap="2">
                            <DotsHorizontalIcon style={{ width: 20, height: 20, color: 'var(--red-9)' }} />
                            <Text size="1" weight="bold" color="gray">TOTAL EXPENSES</Text>
                        </Flex>
                        <Text size="5" weight="bold" color="red">
                            ${parseFloat(typeDistribution.expense || 0).toFixed(2)}
                        </Text>
                    </Flex>
                </Card>

                <Card style={{ padding: '16px' }}>
                    <Flex direction="column" gap="2">
                        <Flex align="center" gap="2">
                            <ArrowUpIcon style={{ width: 20, height: 20, color: 'var(--green-9)' }} />
                            <Text size="1" weight="bold" color="gray">REIMBURSEMENTS</Text>
                        </Flex>
                        <Text size="5" weight="bold" color="green">
                            ${parseFloat(typeDistribution.reimbursement || 0).toFixed(2)}
                        </Text>
                    </Flex>
                </Card>

                <Card style={{ padding: '16px' }}>
                    <Flex direction="column" gap="2">
                        <Flex align="center" gap="2">
                            <PieChartIcon style={{ width: 20, height: 20, color: 'var(--gray-9)' }} />
                            <Text size="1" weight="bold" color="gray">REPAYMENTS</Text>
                        </Flex>
                        <Text size="5" weight="bold" color="gray">
                            ${parseFloat(typeDistribution.repayment || 0).toFixed(2)}
                        </Text>
                    </Flex>
                </Card>
            </Grid>

            {/* Category Breakdown */}
            <Card style={{ padding: '16px', marginBottom: '16px' }}>
                <Flex direction="column" gap="4">
                    <Flex align="center" gap="2">
                        <PieChartIcon style={{ width: 20, height: 20 }} />
                        <Text size="4" weight="bold">EXPENSES BY CATEGORY</Text>
                    </Flex>

                    {Object.keys(categoryBreakdown).length === 0 ? (
                        <Text color="gray">No expense data available</Text>
                    ) : (
                        <Flex direction="column" gap="3">
                            {Object.entries(categoryBreakdown).map(([category, amount]) => (
                                <Flex key={category} align="center" justify="between">
                                    <Flex align="center" gap="2">
                                        <Badge color={categoryColors[category] || 'gray'} variant="soft">
                                            {category.replace('_', ' ').toUpperCase()}
                                        </Badge>
                                    </Flex>
                                    <Text weight="bold">${parseFloat(amount).toFixed(2)}</Text>
                                </Flex>
                            ))}
                        </Flex>
                    )}
                </Flex>
            </Card>

            {/* Monthly Trends */}
            <Card style={{ padding: '16px' }}>
                <Flex direction="column" gap="4">
                    <Flex align="center" gap="2">
                        <TrendUpIcon style={{ width: 20, height: 20 }} />
                        <Text size="4" weight="bold">MONTHLY TRENDS</Text>
                    </Flex>

                    {Object.keys(monthlyTrends).length === 0 ? (
                        <Text color="gray">No trend data available</Text>
                    ) : (
                        <Flex direction="column" gap="3">
                            {Object.entries(monthlyTrends)
                                .sort(([a], [b]) => b.localeCompare(a))
                                .slice(0, 6)
                                .map(([month, data]) => (
                                    <Card key={month} style={{ padding: '12px' }}>
                                        <Flex direction="column" gap="2">
                                            <Text size="2" weight="bold" color="gray">
                                                {new Date(month + '-01').toLocaleDateString('en-US', { year: 'numeric', month: 'long' })}
                                            </Text>
                                            <Flex gap="4" wrap="wrap">
                                                <Flex align="center" gap="1">
                                                    <Text size="1" color="gray">Expenses:</Text>
                                                    <Text size="2" weight="bold" color="red">
                                                        ${parseFloat(data.expenses || 0).toFixed(2)}
                                                    </Text>
                                                </Flex>
                                                <Flex align="center" gap="1">
                                                    <Text size="1" color="gray">Reimbursements:</Text>
                                                    <Text size="2" weight="bold" color="green">
                                                        ${parseFloat(data.reimbursements || 0).toFixed(2)}
                                                    </Text>
                                                </Flex>
                                                <Flex align="center" gap="1">
                                                    <Text size="1" color="gray">Repayments:</Text>
                                                    <Text size="2" weight="bold" color="blue">
                                                        ${parseFloat(data.repayments || 0).toFixed(2)}
                                                    </Text>
                                                </Flex>
                                            </Flex>
                                        </Flex>
                                    </Card>
                                ))}
                        </Flex>
                    )}
                </Flex>
            </Card>
        </Box>
    );
};

export default AnalyticsPanel;
