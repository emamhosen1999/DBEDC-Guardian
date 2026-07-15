/**
 * AnalyticsPanel.jsx
 * Displays petty cash analytics with interactive charts and summary statistics.
 * Pure Radix UI with Recharts for visualizations.
 */
import React, { useState, useEffect } from 'react';
import { Box, Card, Flex, Grid, Text, Badge } from '@radix-ui/themes';
import { 
    Banknote, ShoppingBag, ArrowUpRight, ArrowDownLeft,
    PieChart as PieIcon, BarChart3
} from 'lucide-react';
import axios from 'axios';
import { 
    PieChart, Pie, Cell, ResponsiveContainer, 
    Tooltip as RechartsTooltip, BarChart, Bar, 
    XAxis, YAxis, Legend, CartesianGrid 
} from 'recharts';

const COLORS = ['#8884d8', '#82ca9d', '#ffc658', '#00c49f', '#ff8042', '#a4de6c'];

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
        fuel: 'red',
        office_supplies: 'purple',
        meeting_supplies: 'orange',
        office_maintenance: 'yellow',
        services: 'cyan',
        transport: 'blue',
        utilities: 'green',
        food_beverage: 'pink',
        miscellaneous: 'gray',
    };

    // Format data for Recharts Pie
    const categoryPieData = Object.entries(categoryBreakdown)
        .map(([key, value]) => ({
            name: key.replace('_', ' ').toUpperCase(),
            value: parseFloat(value || 0)
        }))
        .filter(item => item.value > 0);

    // Format data for Recharts Bar
    const trendsBarData = Object.entries(monthlyTrends)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([month, data]) => ({
            monthName: new Date(month + '-01').toLocaleDateString('en-US', { month: 'short', year: '2-digit' }),
            expenses: parseFloat(data.expenses || 0),
            reimbursements: parseFloat(data.reimbursements || 0),
            repayments: parseFloat(data.repayments || 0),
        }));

    return (
        <Box>
            {/* Summary Cards */}
            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="4" mb="6">
                <Card style={{ 
                    padding: '20px', 
                    borderRadius: 'var(--radius-3)',
                    borderLeft: '4px solid var(--blue-9)'
                }}>
                    <Flex justify="between" align="center">
                        <Flex direction="column" gap="1">
                            <Text size="1" weight="bold" color="gray">INITIAL FUNDS</Text>
                            <Text size="5" weight="bold" color="blue">
                                ৳{parseFloat(typeDistribution.loan_taken || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </Text>
                        </Flex>
                        <Box p="2" style={{ background: 'var(--blue-a2)', borderRadius: '50%' }}>
                            <Banknote style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                        </Box>
                    </Flex>
                </Card>

                <Card style={{ 
                    padding: '20px', 
                    borderRadius: 'var(--radius-3)',
                    borderLeft: '4px solid var(--red-9)'
                }}>
                    <Flex justify="between" align="center">
                        <Flex direction="column" gap="1">
                            <Text size="1" weight="bold" color="gray">TOTAL EXPENSES</Text>
                            <Text size="5" weight="bold" color="red">
                                ৳{parseFloat(typeDistribution.expense || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </Text>
                        </Flex>
                        <Box p="2" style={{ background: 'var(--red-a2)', borderRadius: '50%' }}>
                            <ShoppingBag style={{ width: 22, height: 22, color: 'var(--red-9)' }} />
                        </Box>
                    </Flex>
                </Card>

                <Card style={{ 
                    padding: '20px', 
                    borderRadius: 'var(--radius-3)',
                    borderLeft: '4px solid var(--green-9)'
                }}>
                    <Flex justify="between" align="center">
                        <Flex direction="column" gap="1">
                            <Text size="1" weight="bold" color="gray">TOTAL REIMBURSEMENTS</Text>
                            <Text size="5" weight="bold" color="green">
                                ৳{parseFloat(typeDistribution.reimbursement || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </Text>
                        </Flex>
                        <Box p="2" style={{ background: 'var(--green-a2)', borderRadius: '50%' }}>
                            <ArrowUpRight style={{ width: 22, height: 22, color: 'var(--green-9)' }} />
                        </Box>
                    </Flex>
                </Card>

                <Card style={{ 
                    padding: '20px', 
                    borderRadius: 'var(--radius-3)',
                    borderLeft: '4px solid var(--gray-9)'
                }}>
                    <Flex justify="between" align="center">
                        <Flex direction="column" gap="1">
                            <Text size="1" weight="bold" color="gray">TOTAL REPAYMENTS</Text>
                            <Text size="5" weight="bold" color="gray">
                                ৳{parseFloat(typeDistribution.repayment || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </Text>
                        </Flex>
                        <Box p="2" style={{ background: 'var(--gray-a2)', borderRadius: '50%' }}>
                            <ArrowDownLeft style={{ width: 22, height: 22, color: 'var(--gray-9)' }} />
                        </Box>
                    </Flex>
                </Card>
            </Grid>

            {/* Charts Section */}
            <Grid columns={{ initial: '1', lg: '2' }} gap="4" mb="4">
                {/* Expenses by Category (Pie Chart) */}
                <Card style={{ padding: '24px', borderRadius: 'var(--radius-3)' }}>
                    <Flex direction="column" gap="4">
                        <Flex align="center" gap="2">
                            <PieIcon style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                            <Text size="3" weight="bold">EXPENSES BY CATEGORY</Text>
                        </Flex>

                        {categoryPieData.length === 0 ? (
                            <Box py="8" style={{ textAlign: 'center' }}>
                                <Text color="gray">No expense data available</Text>
                            </Box>
                        ) : (
                            <Flex direction={{ initial: 'column', sm: 'row' }} align="center" gap="4" justify="center">
                                <Box style={{ width: '100%', maxWidth: '280px', height: '240px' }}>
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={categoryPieData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={60}
                                                outerRadius={90}
                                                paddingAngle={4}
                                                dataKey="value"
                                            >
                                                {categoryPieData.map((entry, index) => (
                                                    <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                                ))}
                                            </Pie>
                                            <RechartsTooltip formatter={(value) => `৳${value.toFixed(2)}`} />
                                        </PieChart>
                                    </ResponsiveContainer>
                                </Box>
                                <Flex direction="column" gap="2" style={{ minWidth: '150px' }}>
                                    {categoryPieData.map((entry, index) => (
                                        <Flex key={entry.name} align="center" gap="2">
                                            <Box style={{ 
                                                width: '12px', 
                                                height: '12px', 
                                                borderRadius: '3px',
                                                backgroundColor: COLORS[index % COLORS.length] 
                                            }} />
                                            <Text size="1" weight="medium" color="gray">
                                                {entry.name}: <span style={{ fontWeight: 'bold', color: 'var(--gray-12)' }}>৳{entry.value.toFixed(2)}</span>
                                            </Text>
                                        </Flex>
                                    ))}
                                </Flex>
                            </Flex>
                        )}
                    </Flex>
                </Card>

                {/* Monthly Trends (Grouped Bar Chart) */}
                <Card style={{ padding: '24px', borderRadius: 'var(--radius-3)' }}>
                    <Flex direction="column" gap="4">
                        <Flex align="center" gap="2">
                            <BarChart3 style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                            <Text size="3" weight="bold">MONTHLY FLOWS</Text>
                        </Flex>

                        {trendsBarData.length === 0 ? (
                            <Box py="8" style={{ textAlign: 'center' }}>
                                <Text color="gray">No chronological data available</Text>
                            </Box>
                        ) : (
                            <Box style={{ width: '100%', height: '240px' }}>
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={trendsBarData}
                                        margin={{ top: 10, right: 10, left: -20, bottom: 0 }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="monthName" fontSize={11} tickLine={false} />
                                        <YAxis fontSize={11} tickLine={false} axisLine={false} />
                                        <RechartsTooltip formatter={(value) => `৳${value.toFixed(2)}`} />
                                        <Legend verticalAlign="top" height={36} fontSize={11} iconType="circle" />
                                        <Bar dataKey="reimbursements" name="Reimbursements" fill="#82ca9d" radius={[4, 4, 0, 0]} />
                                        <Bar dataKey="expenses" name="Expenses" fill="#8884d8" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </Box>
                        )}
                    </Flex>
                </Card>
            </Grid>
        </Box>
    );
};

export default AnalyticsPanel;
