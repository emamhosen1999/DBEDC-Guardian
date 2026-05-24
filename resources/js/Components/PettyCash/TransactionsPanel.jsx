/**
 * TransactionsPanel.jsx
 * Displays transaction history table with filters and CRUD actions.
 * Pure Radix UI.
 */
import React, { useState, useEffect } from 'react';
import { Box, Card, Flex, Text, Button, Select, TextField, Table, Badge, Link } from '@radix-ui/themes';
import { DownloadIcon, FileTextIcon, TrashIcon, PlusIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import PettyCashExpenseForm from '@/Forms/PettyCashExpenseForm.jsx';
import PettyCashReimbursementForm from '@/Forms/PettyCashReimbursementForm.jsx';
import PettyCashRepaymentForm from '@/Forms/PettyCashRepaymentForm.jsx';

const TransactionsPanel = ({ loanId, isMobile }) => {
    const [transactions, setTransactions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [filterType, setFilterType] = useState('all');
    const [filterCategory, setFilterCategory] = useState('all');
    const [showExpenseForm, setShowExpenseForm] = useState(false);
    const [showReimbursementForm, setShowReimbursementForm] = useState(false);
    const [showRepaymentForm, setShowRepaymentForm] = useState(false);

    const fetchTransactions = async () => {
        if (!loanId) {
            setLoading(false);
            return;
        }
        try {
            setLoading(true);
            const response = await axios.get('/petty-cash/transactions', {
                params: { loan_id: loanId, page, per_page: 20 },
            });
            setTransactions(response.data.transactions);
        } catch (error) {
            console.error('Failed to fetch transactions:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchTransactions();
    }, [loanId, page]);

    const handleExport = async () => {
        try {
            const response = await axios.get('/petty-cash/export', {
                params: { loan_id: loanId },
            });
            
            const data = response.data.data;
            const csv = [
                Object.keys(data[0]).join(','),
                ...data.map(row => Object.values(row).join(','))
            ].join('\n');

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${response.data.filename}.csv`;
            a.click();
        } catch (error) {
            console.error('Failed to export:', error);
        }
    };

    const getTypeColor = (type) => {
        switch (type) {
            case 'expense': return 'red';
            case 'reimbursement': return 'green';
            case 'repayment': return 'blue';
            case 'loan_taken': return 'gray';
            default: return 'gray';
        }
    };

    const getCategoryColor = (category) => {
        switch (category) {
            case 'office_supplies': return 'purple';
            case 'meeting_supplies': return 'orange';
            case 'office_maintenance': return 'yellow';
            case 'services': return 'cyan';
            default: return 'gray';
        }
    };

    const filteredTransactions = transactions.data?.filter(t => {
        if (filterType !== 'all' && t.type !== filterType) return false;
        if (filterCategory !== 'all' && t.category !== filterCategory) return false;
        return true;
    }) || [];

    if (loading) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">Loading transactions...</Text>
            </Box>
        );
    }

    return (
        <Box>
            {/* Filters and Actions */}
            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4" mb="4">
                <Flex gap="2" wrap="wrap">
                    <Select.Root value={filterType} onValueChange={setFilterType}>
                        <Select.Trigger placeholder="Filter by Type" />
                        <Select.Content>
                            <Select.Item value="all">All Types</Select.Item>
                            <Select.Item value="loan_taken">Loan Taken</Select.Item>
                            <Select.Item value="expense">Expense</Select.Item>
                            <Select.Item value="reimbursement">Reimbursement</Select.Item>
                            <Select.Item value="repayment">Repayment</Select.Item>
                        </Select.Content>
                    </Select.Root>

                    <Select.Root value={filterCategory} onValueChange={setFilterCategory}>
                        <Select.Trigger placeholder="Filter by Category" />
                        <Select.Content>
                            <Select.Item value="all">All Categories</Select.Item>
                            <Select.Item value="office_supplies">Office Supplies</Select.Item>
                            <Select.Item value="meeting_supplies">Meeting Supplies</Select.Item>
                            <Select.Item value="office_maintenance">Office Maintenance</Select.Item>
                            <Select.Item value="services">Services</Select.Item>
                        </Select.Content>
                    </Select.Root>
                </Flex>

                <Flex gap="2">
                    <Button onClick={() => setShowExpenseForm(true)} variant="solid">
                        <PlusIcon style={{ marginRight: '8px' }} />
                        {!isMobile && 'Expense'}
                    </Button>
                    <Button onClick={() => setShowReimbursementForm(true)} variant="solid">
                        <PlusIcon style={{ marginRight: '8px' }} />
                        {!isMobile && 'Reimbursement'}
                    </Button>
                    <Button onClick={() => setShowRepaymentForm(true)} variant="solid">
                        <PlusIcon style={{ marginRight: '8px' }} />
                        {!isMobile && 'Repayment'}
                    </Button>
                    <Button onClick={handleExport} variant="soft">
                        <DownloadIcon style={{ marginRight: '8px' }} />
                        {!isMobile && 'Export'}
                    </Button>
                </Flex>
            </Flex>

            {/* Transactions Table */}
            <Card>
                <Table.Root>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Amount</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Description</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Bills</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {filteredTransactions.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={6} style={{ textAlign: 'center', padding: '32px' }}>
                                    <Text color="gray">No transactions found</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            filteredTransactions.map((transaction) => (
                                <Table.Row key={transaction.id}>
                                    <Table.Cell>
                                        <Text size="2">{new Date(transaction.transaction_date).toLocaleDateString()}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={getTypeColor(transaction.type)} variant="soft">
                                            {transaction.type.replace('_', ' ').toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        {transaction.category ? (
                                            <Badge color={getCategoryColor(transaction.category)} variant="soft">
                                                {transaction.category.replace('_', ' ').toUpperCase()}
                                            </Badge>
                                        ) : (
                                            <Text color="gray">N/A</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold" color={transaction.type === 'expense' || transaction.type === 'repayment' ? 'red' : 'green'}>
                                            ${parseFloat(transaction.amount).toFixed(2)}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2" style={{ maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                            {transaction.description || '-'}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        {transaction.has_bills ? (
                                            <Flex gap="2">
                                                {transaction.bills.map((bill) => (
                                                    <Link key={bill.id} href={bill.url} target="_blank" rel="noopener noreferrer">
                                                        <FileTextIcon style={{ width: 16, height: 16 }} />
                                                    </Link>
                                                ))}
                                            </Flex>
                                        ) : (
                                            <Text color="gray">No bills</Text>
                                        )}
                                    </Table.Cell>
                                </Table.Row>
                            ))
                        )}
                    </Table.Body>
                </Table.Root>

                {/* Pagination */}
                {transactions.last_page > 1 && (
                    <Flex justify="center" gap="2" mt="4">
                        <Button
                            variant="soft"
                            disabled={page === 1}
                            onClick={() => setPage(p => p - 1)}
                        >
                            Previous
                        </Button>
                        <Text size="2" color="gray" style={{ display: 'flex', alignItems: 'center' }}>
                            Page {page} of {transactions.last_page}
                        </Text>
                        <Button
                            variant="soft"
                            disabled={page === transactions.last_page}
                            onClick={() => setPage(p => p + 1)}
                        >
                            Next
                        </Button>
                    </Flex>
                )}
            </Card>

            {/* Transaction Forms */}
            <PettyCashExpenseForm
                open={showExpenseForm}
                onClose={() => setShowExpenseForm(false)}
                onSuccess={() => {
                    setShowExpenseForm(false);
                    fetchTransactions();
                }}
                loanId={loanId}
            />
            <PettyCashReimbursementForm
                open={showReimbursementForm}
                onClose={() => setShowReimbursementForm(false)}
                onSuccess={() => {
                    setShowReimbursementForm(false);
                    fetchTransactions();
                }}
                loanId={loanId}
            />
            <PettyCashRepaymentForm
                open={showRepaymentForm}
                onClose={() => setShowRepaymentForm(false)}
                onSuccess={() => {
                    setShowRepaymentForm(false);
                    fetchTransactions();
                }}
                loanId={loanId}
            />
        </Box>
    );
};

export default TransactionsPanel;
