/**
 * ManagerPanel.jsx
 * Admin view for managing all employee petty cash loans and requests.
 * Pure Radix UI.
 */
import React, { useState, useEffect } from 'react';
import { Box, Card, Flex, Table, Badge, Button, Text, Select } from '@radix-ui/themes';
import { CheckIcon, Cross1Icon } from '@radix-ui/react-icons';
import axios from 'axios';

const ManagerPanel = ({ isMobile, onRefresh }) => {
    const [loans, setLoans] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filterStatus, setFilterStatus] = useState('all');

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

    const handleApprove = async (loanId) => {
        if (!window.confirm('Are you sure you want to approve this petty cash loan?')) return;
        try {
            const response = await axios.post('/petty-cash/loan/approve', { loan_id: loanId });
            if (response.data.success) {
                fetchLoans();
                if (onRefresh) onRefresh();
            } else {
                alert(response.data.error || 'Failed to approve loan');
            }
        } catch (error) {
            alert(error.response?.data?.error || 'Failed to approve loan');
        }
    };

    const handleReject = async (loanId) => {
        if (!window.confirm('Are you sure you want to reject this petty cash loan request?')) return;
        try {
            const response = await axios.post('/petty-cash/loan/reject', { loan_id: loanId });
            if (response.data.success) {
                fetchLoans();
                if (onRefresh) onRefresh();
            } else {
                alert(response.data.error || 'Failed to reject loan');
            }
        } catch (error) {
            alert(error.response?.data?.error || 'Failed to reject loan');
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
                <Text size="2" color="gray" weight="bold">MANAGE EMPLOYEE LOANS</Text>
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

            <Card>
                <Table.Root>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Requested Date</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Amount</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Notes</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {loans.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={6} style={{ textAlign: 'center', padding: '32px' }}>
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
                                        <Text size="2">{new Date(loan.loan_date).toLocaleDateString()}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold">${parseFloat(loan.original_amount).toFixed(2)}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={getStatusColor(loan.status)} variant="soft">
                                            {loan.status.toUpperCase().replace('_', ' ')}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" style={{ maxWidth: '150px', display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                            {loan.notes || '-'}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell style={{ textAlign: 'right' }}>
                                        {loan.status === 'pending_approval' ? (
                                            <Flex gap="2" justify="end">
                                                <Button 
                                                    size="1" 
                                                    color="green" 
                                                    onClick={() => handleApprove(loan.id)}
                                                    style={{ cursor: 'pointer' }}
                                                >
                                                    <CheckIcon style={{ marginRight: '4px' }} />
                                                    Approve
                                                </Button>
                                                <Button 
                                                    size="1" 
                                                    color="red" 
                                                    variant="soft"
                                                    onClick={() => handleReject(loan.id)}
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
            </Card>
        </Box>
    );
};

export default ManagerPanel;
