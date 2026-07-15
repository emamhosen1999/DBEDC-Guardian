import { Panel } from '@/Components/ui/Panel';
/**
 * TransactionsPanel.jsx
 * Transaction history with server-side search, filters, date range, and CRUD.
 * Currency: BDT (৳). Categories from server.
 */
import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Box, Flex, Text, Button, Select, TextField, Table, Badge, Link, IconButton, Tooltip } from '@radix-ui/themes';
import { DownloadIcon, FileTextIcon, TrashIcon, PlusIcon, Pencil1Icon, UploadIcon, Cross1Icon, MagnifyingGlassIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { handleExportResponse } from '@/utils/exportUtils';
import PettyCashExpenseForm from '@/Forms/PettyCashExpenseForm.jsx';
import PettyCashReimbursementForm from '@/Forms/PettyCashReimbursementForm.jsx';
import PettyCashRepaymentForm from '@/Forms/PettyCashRepaymentForm.jsx';
import PettyCashEditTransactionForm from '@/Forms/PettyCashEditTransactionForm.jsx';

const CATEGORY_COLORS = {
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

const TransactionsPanel = ({ loanId, isMobile, onRefreshLoan, categories = {} }) => {
    const [transactions, setTransactions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);

    // Server-side filters
    const [filterType, setFilterType] = useState('all');
    const [filterCategory, setFilterCategory] = useState('all');
    const [searchText, setSearchText] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [sortBy, setSortBy] = useState('transaction_date');
    const [sortOrder, setSortOrder] = useState('desc');

    const [showExpenseForm, setShowExpenseForm] = useState(false);
    const [showReimbursementForm, setShowReimbursementForm] = useState(false);
    const [showRepaymentForm, setShowRepaymentForm] = useState(false);
    const [showEditForm, setShowEditForm] = useState(false);
    const [selectedTransaction, setSelectedTransaction] = useState(null);
    const [uploadingTransactionId, setUploadingTransactionId] = useState(null);
    const [showFilters, setShowFilters] = useState(false);

    const fileInputRef = useRef(null);
    const searchTimeout = useRef(null);

    const fetchTransactions = useCallback(async () => {
        if (!loanId) {
            setLoading(false);
            return;
        }
        try {
            setLoading(true);
            const params = {
                loan_id: loanId,
                page,
                per_page: 20,
                sort_by: sortBy,
                sort_order: sortOrder,
            };
            if (filterType !== 'all') params.type = filterType;
            if (filterCategory !== 'all') params.category = filterCategory;
            if (searchText.trim()) params.search = searchText.trim();
            if (dateFrom) params.date_from = dateFrom;
            if (dateTo) params.date_to = dateTo;

            const response = await axios.get('/petty-cash/transactions', { params });
            setTransactions(response.data.transactions);
        } catch (error) {
            console.error('Failed to fetch transactions:', error);
        } finally {
            setLoading(false);
        }
    }, [loanId, page, filterType, filterCategory, searchText, dateFrom, dateTo, sortBy, sortOrder]);

    useEffect(() => {
        fetchTransactions();
    }, [fetchTransactions]);

    // Debounced search
    const handleSearchChange = (e) => {
        const val = e.target.value;
        setSearchText(val);
        clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            setPage(1);
        }, 400);
    };

    const handleFilterChange = (setter) => (value) => {
        setter(value);
        setPage(1);
    };

    const handleSort = (column) => {
        if (sortBy === column) {
            setSortOrder(prev => prev === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(column);
            setSortOrder('desc');
        }
        setPage(1);
    };

    const clearFilters = () => {
        setFilterType('all');
        setFilterCategory('all');
        setSearchText('');
        setDateFrom('');
        setDateTo('');
        setSortBy('transaction_date');
        setSortOrder('desc');
        setPage(1);
    };

    const hasActiveFilters = filterType !== 'all' || filterCategory !== 'all' || searchText || dateFrom || dateTo;

    const handleExport = async () => {
        try {
            const response = await axios.get('/petty-cash/export', {
                params: { loan_id: loanId },
            });

            if (response.data && response.data.queued) {
                await handleExportResponse(response.data, `${response.data.filename || 'petty_cash_transactions'}.csv`, 'text/csv', 'csv');
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
        if (!window.confirm('Are you sure you want to delete this transaction? This will adjust your fund balance.')) return;
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

    const getCategoryColor = (category) => CATEGORY_COLORS[category] || 'gray';

    const formatCategory = (cat) => {
        if (!cat) return null;
        // Check server categories first
        if (categories[cat]) return categories[cat];
        return cat.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    };

    const transactionData = transactions.data || [];
    const sortIcon = (col) => sortBy === col ? (sortOrder === 'asc' ? ' ↑' : ' ↓') : '';

    const categoryList = Object.entries(categories).length > 0
        ? Object.entries(categories)
        : Object.entries(CATEGORY_COLORS);

    if (loading && transactionData.length === 0) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">Loading transactions...</Text>
            </Box>
        );
    }

    return (
        <Box>
            {/* Action Buttons */}
            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="3" mb="4">
                <Flex gap="2" wrap="wrap">
                    <Button onClick={() => setShowExpenseForm(true)} variant="solid" size="2">
                        <PlusIcon style={{ marginRight: '4px' }} />
                        {!isMobile && 'Log Expense'}
                    </Button>
                    <Button onClick={() => setShowReimbursementForm(true)} variant="solid" color="green" size="2">
                        <PlusIcon style={{ marginRight: '4px' }} />
                        {!isMobile && 'Receive Cash'}
                    </Button>
                    <Button onClick={() => setShowRepaymentForm(true)} variant="solid" color="blue" size="2">
                        <PlusIcon style={{ marginRight: '4px' }} />
                        {!isMobile && 'Return Cash'}
                    </Button>
                </Flex>
                <Flex gap="2">
                    <Button onClick={() => setShowFilters(p => !p)} variant="soft" size="2">
                        <MagnifyingGlassIcon style={{ marginRight: '4px' }} />
                        {showFilters ? 'Hide Filters' : 'Filters'}
                        {hasActiveFilters && <Badge color="red" size="1" ml="1">{[filterType !== 'all', filterCategory !== 'all', searchText, dateFrom, dateTo].filter(Boolean).length}</Badge>}
                    </Button>
                    <Button onClick={handleExport} variant="soft" size="2">
                        <DownloadIcon style={{ marginRight: '4px' }} />
                        {!isMobile && 'Export CSV'}
                    </Button>
                    <a href={`/petty-cash/export-pdf?loan_id=${loanId}`} target="_blank" rel="noopener noreferrer" style={{ textDecoration: 'none' }}>
                        <Button variant="soft" size="2" color="blue" style={{ cursor: 'pointer' }}>
                            <DownloadIcon style={{ marginRight: '4px' }} />
                            {!isMobile && 'Export PDF'}
                        </Button>
                    </a>
                </Flex>
            </Flex>

            {/* Filter Panel */}
            {showFilters && (
                <Panel tinted mb="4" style={{ padding: '16px' }}>
                    <Flex direction="column" gap="3">
                        {/* Search */}
                        <TextField.Root
                            placeholder="Search descriptions..."
                            value={searchText}
                            onChange={handleSearchChange}
                        >
                            <TextField.Slot>
                                <MagnifyingGlassIcon />
                            </TextField.Slot>
                        </TextField.Root>

                        <Flex gap="3" wrap="wrap">
                            {/* Type filter */}
                            <Box style={{ minWidth: '150px' }}>
                                <Text size="1" weight="bold" color="gray" mb="1" as="div">TYPE</Text>
                                <Select.Root value={filterType} onValueChange={handleFilterChange(setFilterType)}>
                                    <Select.Trigger placeholder="All Types" />
                                    <Select.Content>
                                        <Select.Item value="all">All Types</Select.Item>
                                        <Select.Item value="loan_taken">Loan Taken</Select.Item>
                                        <Select.Item value="expense">Expense</Select.Item>
                                        <Select.Item value="reimbursement">Reimbursement</Select.Item>
                                        <Select.Item value="repayment">Repayment</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            {/* Category filter */}
                            <Box style={{ minWidth: '150px' }}>
                                <Text size="1" weight="bold" color="gray" mb="1" as="div">CATEGORY</Text>
                                <Select.Root value={filterCategory} onValueChange={handleFilterChange(setFilterCategory)}>
                                    <Select.Trigger placeholder="All Categories" />
                                    <Select.Content>
                                        <Select.Item value="all">All Categories</Select.Item>
                                        {categoryList.map(([key, label]) => (
                                            <Select.Item key={key} value={key}>{typeof label === 'string' ? label : key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            {/* Date range */}
                            <Box style={{ minWidth: '140px' }}>
                                <Text size="1" weight="bold" color="gray" mb="1" as="div">FROM DATE</Text>
                                <input
                                    type="date"
                                    value={dateFrom}
                                    onChange={e => { setDateFrom(e.target.value); setPage(1); }}
                                    style={{
                                        padding: '6px 10px', border: '1px solid var(--gray-a6)',
                                        borderRadius: 'var(--radius-2)', fontSize: '14px', width: '100%',
                                        background: 'var(--color-background)'
                                    }}
                                />
                            </Box>
                            <Box style={{ minWidth: '140px' }}>
                                <Text size="1" weight="bold" color="gray" mb="1" as="div">TO DATE</Text>
                                <input
                                    type="date"
                                    value={dateTo}
                                    onChange={e => { setDateTo(e.target.value); setPage(1); }}
                                    style={{
                                        padding: '6px 10px', border: '1px solid var(--gray-a6)',
                                        borderRadius: 'var(--radius-2)', fontSize: '14px', width: '100%',
                                        background: 'var(--color-background)'
                                    }}
                                />
                            </Box>
                        </Flex>

                        {hasActiveFilters && (
                            <Flex justify="between" align="center">
                                <Text size="1" color="gray">
                                    Showing {transactionData.length} of {transactions.total || 0} transactions
                                </Text>
                                <Button variant="ghost" size="1" onClick={clearFilters} style={{ cursor: 'pointer' }}>
                                    Clear all filters
                                </Button>
                            </Flex>
                        )}
                    </Flex>
                </Panel>
            )}

            {/* Transactions Table */}
            <Panel>
                <Table.Root>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell style={{ cursor: 'pointer' }} onClick={() => handleSort('transaction_date')}>
                                Date{sortIcon('transaction_date')}
                            </Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ cursor: 'pointer' }} onClick={() => handleSort('amount')}>
                                Amount{sortIcon('amount')}
                            </Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Description</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Bills</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {transactionData.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={7} style={{ textAlign: 'center', padding: '32px' }}>
                                    <Text color="gray">{hasActiveFilters ? 'No transactions match your filters' : 'No transactions found'}</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            transactionData.map((transaction) => (
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
                                                {formatCategory(transaction.category)}
                                            </Badge>
                                        ) : (
                                            <Text color="gray">N/A</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold" color={transaction.type === 'expense' || transaction.type === 'repayment' ? 'red' : 'green'}>
                                            ৳{parseFloat(transaction.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
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
                    <Flex justify="center" align="center" gap="2" mt="4">
                        <Button
                            variant="soft"
                            size="1"
                            disabled={page === 1}
                            onClick={() => setPage(p => p - 1)}
                        >
                            Previous
                        </Button>
                        <Text size="2" color="gray" style={{ display: 'flex', alignItems: 'center' }}>
                            Page {page} of {transactions.last_page} ({transactions.total} total)
                        </Text>
                        <Button
                            variant="soft"
                            size="1"
                            disabled={page === transactions.last_page}
                            onClick={() => setPage(p => p + 1)}
                        >
                            Next
                        </Button>
                    </Flex>
                )}
            </Panel>

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
                categories={categories}
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
                categories={categories}
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
                categories={categories}
            />
        </Box>
    );
};

export default TransactionsPanel;
