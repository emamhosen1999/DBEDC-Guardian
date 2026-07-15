import { Panel } from '@/Components/ui/Panel';
/**
 * ManagerPanel.jsx
 * Admin view for managing all employee petty cash funds.
 * Supports approval comments (Phase 5). Currency: BDT (৳).
 */
import React, { useState, useEffect } from 'react';
import { Box, Flex, Table, Badge, Button, Text, Select, Dialog, TextField } from '@radix-ui/themes';
import { CheckIcon, Cross1Icon } from '@radix-ui/react-icons';
import axios from 'axios';

const ManagerPanel = ({ isMobile, onRefresh }) => {
    const [loans, setLoans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filterStatus, setFilterStatus] = useState('all');

    // Approval comment dialog
    const [commentDialog, setCommentDialog] = useState({ open: false, loanId: null, action: null });
    const [comment, setComment] = useState('');
    const [processing, setProcessing] = useState(false);

    const fetchLoans = async () => {
        try {
            setLoading(true);
            const response = await axios.get('/petty-cash/admin/overview', {
                params: { status: filterStatus !== 'all' ? filterStatus : null }
            });
            if (response.data.success) {
                setLoans(response.data.loans);
            }
        } catch (error) {
            console.error('Failed to fetch admin overview:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLoans();
    }, [filterStatus]);

    const openCommentDialog = (loanId, action) => {
        setCommentDialog({ open: true, loanId, action });
        setComment('');
    };

    const handleAction = async () => {
        const { loanId, action } = commentDialog;
        setProcessing(true);

        try {
            const endpoint = action === 'approve' ? '/petty-cash/loan/approve' : '/petty-cash/loan/reject';
            const response = await axios.post(endpoint, {
                loan_id: loanId,
                comment: comment || null,
            });
            if (response.data.success) {
                setCommentDialog({ open: false, loanId: null, action: null });
                fetchLoans();
                if (onRefresh) onRefresh();
            } else {
                alert(response.data.error || `Failed to ${action} loan`);
            }
        } catch (error) {
            alert(error.response?.data?.error || `Failed to ${action} loan`);
        } finally {
            setProcessing(false);
        }
    };

    if (loading) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">Loading overview...</Text>
            </Box>
        );
    }

    const getStatusColor = (status) => {
        switch (status) {
            case 'active': return 'green';
            case 'pending_approval': return 'orange';
            case 'rejected': return 'red';
            case 'settled': return 'blue';
            case 'closed': return 'gray';
            default: return 'gray';
        }
    };

    return (
        <Box>
            {/* Filter */}
            <Flex mb="4" justify="between" align="center">
                <Text size="2" color="gray" weight="bold">MANAGE EMPLOYEE FUNDS</Text>
                <Select.Root value={filterStatus} onValueChange={setFilterStatus}>
                    <Select.Trigger placeholder="Filter by Status" />
                    <Select.Content>
                        <Select.Item value="all">All Statuses</Select.Item>
                        <Select.Item value="pending_approval">Pending Approval</Select.Item>
                        <Select.Item value="active">Active</Select.Item>
                        <Select.Item value="settled">Settled</Select.Item>
                        <Select.Item value="closed">Closed</Select.Item>
                        <Select.Item value="rejected">Rejected</Select.Item>
                    </Select.Content>
                </Select.Root>
            </Flex>

            <Panel>
                <Table.Root>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Fund</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Amount</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Approval</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {loans.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={7} style={{ textAlign: 'center', padding: '32px' }}>
                                    <Text color="gray">No requests found</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            loans.map((loan) => (
                                <Table.Row key={loan.id}>
                                    <Table.Cell>
                                        <Flex direction="column">
                                            <Text size="2" weight="bold">{loan.user.name}</Text>
                                            <Text size="1" color="gray">{loan.user.email}</Text>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge variant="soft" size="1">{loan.fund_name || 'General Fund'}</Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{new Date(loan.loan_date).toLocaleDateString()}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold">৳{parseFloat(loan.original_amount).toLocaleString()}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={getStatusColor(loan.status)} variant="soft">
                                            {loan.status.toUpperCase().replace('_', ' ')}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        {loan.approver_name ? (
                                            <Flex direction="column">
                                                <Text size="1" color="gray">By: {loan.approver_name}</Text>
                                                {loan.approval_comment && (
                                                    <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>
                                                        "{loan.approval_comment}"
                                                    </Text>
                                                )}
                                            </Flex>
                                        ) : (
                                            <Text size="1" color="gray">—</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell style={{ textAlign: 'right' }}>
                                        {loan.status === 'pending_approval' ? (
                                            <Flex gap="2" justify="end">
                                                <Button
                                                    size="1"
                                                    color="green"
                                                    onClick={() => openCommentDialog(loan.id, 'approve')}
                                                    style={{ cursor: 'pointer' }}
                                                >
                                                    <CheckIcon style={{ marginRight: '4px' }} />
                                                    Approve
                                                </Button>
                                                <Button
                                                    size="1"
                                                    color="red"
                                                    variant="soft"
                                                    onClick={() => openCommentDialog(loan.id, 'reject')}
                                                    style={{ cursor: 'pointer' }}
                                                >
                                                    <Cross1Icon style={{ marginRight: '4px' }} />
                                                    Reject
                                                </Button>
                                            </Flex>
                                        ) : (
                                            <Text size="1" color="gray">Reviewed</Text>
                                        )}
                                    </Table.Cell>
                                </Table.Row>
                            ))
                        )}
                    </Table.Body>
                </Table.Root>
            </Panel>

            {/* Approval Comment Dialog */}
            <Dialog.Root open={commentDialog.open} onOpenChange={(open) => !open && setCommentDialog({ open: false, loanId: null, action: null })}>
                <Dialog.Content style={{ maxWidth: 400, padding: '24px' }}>
                    <Dialog.Title>
                        {commentDialog.action === 'approve' ? '✓ Approve Fund Request' : '✗ Reject Fund Request'}
                    </Dialog.Title>
                    <Dialog.Description size="2" color="gray" mb="4">
                        Add an optional comment explaining your decision.
                    </Dialog.Description>

                    <Flex direction="column" gap="3">
                        <TextField.Root
                            placeholder="Optional comment (e.g., 'Approved for office supplies')"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                            maxLength={1000}
                        />

                        <Flex gap="3" justify="end">
                            <Dialog.Close>
                                <Button variant="soft" disabled={processing}>Cancel</Button>
                            </Dialog.Close>
                            <Button
                                color={commentDialog.action === 'approve' ? 'green' : 'red'}
                                onClick={handleAction}
                                disabled={processing}
                            >
                                {processing ? 'Processing...' : (commentDialog.action === 'approve' ? 'Approve' : 'Reject')}
                            </Button>
                        </Flex>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
};

export default ManagerPanel;
