/**
 * TransactionsPanel.jsx
 * Displays transaction history table with filters and CRUD actions.
 * Pure Radix UI.
 */
import React, { useState, useEffect, useRef } from 'react';
import { Box, Card, Flex, Text, Button, Select, TextField, Table, Badge, Link, IconButton, Tooltip } from '@radix-ui/themes';
import { DownloadIcon, FileTextIcon, TrashIcon, PlusIcon, Pencil1Icon, UploadIcon, Cross1Icon } from '@radix-ui/react-icons';
import axios from 'axios';
import { handleExportResponse } from '@/utils/exportUtils';
import PettyCashExpenseForm from '@/Forms/PettyCashExpenseForm.jsx';
import PettyCashReimbursementForm from '@/Forms/PettyCashReimbursementForm.jsx';
import PettyCashRepaymentForm from '@/Forms/PettyCashRepaymentForm.jsx';
import PettyCashEditTransactionForm from '@/Forms/PettyCashEditTransactionForm.jsx';

const TransactionsPanel = ({ loanId, isMobile, onRefreshLoan }) => {
    const [transactions, setTransactions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [filterType, setFilterType] = useState('all');
    const [filterCategory, setFilterCategory] = useState('all');
    const [showExpenseForm, setShowExpenseForm] = useState(false);
    const [showReimbursementForm, setShowReimbursementForm] = useState(false);
    const [showRepaymentForm, setShowRepaymentForm] = useState(false);
    const [showEditForm, setShowEditForm] = useState(false);
    const [selectedTransaction, setSelectedTransaction] = useState(null);
    const [uploadingTransactionId, setUploadingTransactionId] = useState(null);
    
    const fileInputRef = useRef(null);

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
            
            if (response.data && response.data.queued) {
                await handleExportResponse(response.data, `${response.data.filename || 'petty_cash_transactions'}.csv`, 'text/csv', 'csv');
            } else {
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
            }
        } catch (error) {
            console.error('Failed to export:', error);
        }
    };
    const handleUploadBill = async (transactionId, file) => {
        if (!file) return;
        try {
            const formData = new FormData();
            formData.append('transaction_id', transactionId);
            formData.append('bill', file);
            await axios.post('/petty-cash/upload-bill', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            fetchTransactions();
        } catch (error) {
            console.error('Failed to upload bill:', error);
        }
    };

    const handleDeleteBill = async (transactionId, mediaId) => {
        if (!window.confirm('Are you sure you want to delete this bill?')) return;
        try {
            await axios.post('/petty-cash/delete-bill', {
                transaction_id: transactionId,
                media_id: mediaId,
            });
            fetchTransactions();
        } catch (error) {
            console.error('Failed to delete bill:', error);
        }
    };

    const handleDeleteTransaction = async (transactionId) => {
        if (!window.confirm('Are you sure you want to delete this transaction? This will adjust your loan balance.')) return;
        try {
            const response = await axios.delete('/petty-cash/transaction', {
                data: { transaction_id: transactionId }
            });
            if (response.data.success) {
                fetchTransactions();
                if (onRefreshLoan) onRefreshLoan();
            } else {
                alert(response.data.error || 'Failed to delete transaction');
            }
        } catch (error) {
            alert(error.response?.data?.error || 'Failed to delete transaction');
        }
    };

    const triggerUploadBillClick = (transactionId) => {
        setUploadingTransactionId(transactionId);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
            fileInputRef.current.click();
        }
    };

    const handleFileChange = (e) => {
        if (e.target.files && e.target.files[0]) {
            handleUploadBill(uploadingTransactionId, e.target.files[0]);
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
                        {!isMobile && 'Log Expense'}
                    </Button>
                    <Button onClick={() => setShowReimbursementForm(true)} variant="solid">
                        <PlusIcon style={{ marginRight: '8px' }} />
                        {!isMobile && 'Receive Cash (Reimbursement)'}
                    </Button>
                    <Button onClick={() => setShowRepaymentForm(true)} variant="solid">
                        <PlusIcon style={{ marginRight: '8px' }} />
                        {!isMobile && 'Return Cash (Repayment)'}
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
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {filteredTransactions.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={7} style={{ textAlign: 'center', padding: '32px' }}>
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
                                        <Flex gap="2" align="center" wrap="wrap">
                                            {transaction.bills && transaction.bills.length > 0 ? (
                                                transaction.bills.map((bill) => (
                                                    <Flex key={bill.id} align="center" gap="1" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-1)', padding: '2px 6px' }}>
                                                        <Link href={bill.url} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center' }}>
                                                            <FileTextIcon style={{ width: 14, height: 14 }} />
                                                        </Link>
                                                        <IconButton 
                                                            size="1" 
                                                            variant="ghost" 
                                                            color="red"
                                                            onClick={() => handleDeleteBill(transaction.id, bill.id)}
                                                            style={{ cursor: 'pointer' }}
                                                        >
                                                            <Cross1Icon style={{ width: 10, height: 10 }} />
                                                        </IconButton>
                                                    </Flex>
                                                ))
                                            ) : (
                                                <Text size="1" color="gray">No bills</Text>
                                            )}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell style={{ textAlign: 'right' }}>
                                        {transaction.type !== 'loan_taken' ? (
                                            <Flex gap="2" justify="end">
                                                {(transaction.type === 'expense' || transaction.type === 'reimbursement') && (
                                                    <Tooltip content="Upload Receipt">
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            onClick={() => triggerUploadBillClick(transaction.id)}
                                                            style={{ cursor: 'pointer' }}
                                                        >
                                                            <UploadIcon style={{ width: 14, height: 14 }} />
                                                        </IconButton>
                                                    </Tooltip>
                                                )}
                                                <Tooltip content="Edit">
                                                    <IconButton
                                                        size="1"
                                                        variant="soft"
                                                        color="gray"
                                                        onClick={() => {
                                                            setSelectedTransaction(transaction);
                                                            setShowEditForm(true);
                                                        }}
                                                        style={{ cursor: 'pointer' }}
                                                    >
                                                        <Pencil1Icon style={{ width: 14, height: 14 }} />
                                                    </IconButton>
                                                </Tooltip>
                                                <Tooltip content="Delete">
                                                    <IconButton
                                                        size="1"
                                                        variant="soft"
                                                        color="red"
                                                        onClick={() => handleDeleteTransaction(transaction.id)}
                                                        style={{ cursor: 'pointer' }}
                                                    >
                                                        <TrashIcon style={{ width: 14, height: 14 }} />
                                                    </IconButton>
                                                </Tooltip>
                                            </Flex>
                                        ) : (
                                            <Text size="1" color="gray">System Log</Text>
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

            <input 
                type="file"
                ref={fileInputRef}
                style={{ display: 'none' }}
                accept="image/jpeg,image/png,image/jpg,application/pdf"
                onChange={handleFileChange}
            />

            {/* Transaction Forms */}
            <PettyCashExpenseForm
                open={showExpenseForm}
                onClose={() => setShowExpenseForm(false)}
                onSuccess={() => {
                    setShowExpenseForm(false);
                    fetchTransactions();
                    if (onRefreshLoan) onRefreshLoan();
                }}
                loanId={loanId}
            />
            <PettyCashReimbursementForm
                open={showReimbursementForm}
                onClose={() => setShowReimbursementForm(false)}
                onSuccess={() => {
                    setShowReimbursementForm(false);
                    fetchTransactions();
                    if (onRefreshLoan) onRefreshLoan();
                }}
                loanId={loanId}
            />
            <PettyCashRepaymentForm
                open={showRepaymentForm}
                onClose={() => setShowRepaymentForm(false)}
                onSuccess={() => {
                    setShowRepaymentForm(false);
                    fetchTransactions();
                    if (onRefreshLoan) onRefreshLoan();
                }}
                loanId={loanId}
            />
            <PettyCashEditTransactionForm
                open={showEditForm}
                onClose={() => {
                    setShowEditForm(false);
                    setSelectedTransaction(null);
                }}
                onSuccess={() => {
                    setShowEditForm(false);
                    setSelectedTransaction(null);
                    fetchTransactions();
                    if (onRefreshLoan) onRefreshLoan();
                }}
                transaction={selectedTransaction}
            />
        </Box>
    );
};

export default TransactionsPanel;
