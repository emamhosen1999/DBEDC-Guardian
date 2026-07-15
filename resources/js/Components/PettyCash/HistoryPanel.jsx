/**
 * HistoryPanel.jsx
 * Displays historical closed, settled, or rejected loans.
 * Pure Radix UI.
 */
import React, { useState, useEffect } from 'react';
import { Box, Card, Flex, Table, Badge, Button, Text, Dialog } from '@radix-ui/themes';
import { EyeOpenIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import TransactionsPanel from './TransactionsPanel';

const HistoryPanel = ({ isMobile }) => {
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedLoan, setSelectedLoan] = useState(null);

    const fetchHistory = async () => {
        try {
            setLoading(true);
            const response = await axios.get('/petty-cash/history');
            if (response.data.success) {
                setHistory(response.data.history);
            }
        } catch (error) {
            console.error('Failed to fetch history:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchHistory();
    }, []);

    if (loading) {
        return (
            <Box p="6" style={{ textAlign: 'center' }}>
                <Text color="gray">Loading history...</Text>
            </Box>
        );
    }

    return (
        <Box>
            <Card>
                <Table.Root>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Requested Date</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Closed Date</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Amount</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {history.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={5} style={{ textAlign: 'center', padding: '32px' }}>
                                    <Text color="gray">No past loans found</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            history.map((loan) => (
                                <Table.Row key={loan.id}>
                                    <Table.Cell>
                                        <Text size="2">{new Date(loan.loan_date).toLocaleDateString()}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{loan.closed_date ? new Date(loan.closed_date).toLocaleDateString() : 'N/A'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold">${parseFloat(loan.original_amount).toFixed(2)}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={loan.status === 'settled' ? 'blue' : loan.status === 'rejected' ? 'red' : 'gray'} variant="soft">
                                            {loan.status.toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell style={{ textAlign: 'right' }}>
                                        {loan.status !== 'rejected' ? (
                                            <Button 
                                                size="1" 
                                                variant="soft" 
                                                onClick={() => setSelectedLoan(loan)}
                                                style={{ cursor: 'pointer' }}
                                            >
                                                <EyeOpenIcon style={{ marginRight: '4px' }} />
                                                View Logs
                                            </Button>
                                        ) : (
                                            <Text size="1" color="gray">None</Text>
                                        )}
                                    </Table.Cell>
                                </Table.Row>
                            ))
                        )}
                    </Table.Body>
                </Table.Root>
            </Card>

            {/* Past Loan Transactions Modal */}
            {selectedLoan && (
                <Dialog.Root open={!!selectedLoan} onOpenChange={() => setSelectedLoan(null)}>
                    <Dialog.Content style={{ maxWidth: 850, padding: '24px' }}>
                        <Dialog.Title>
                            Past Loan Ledger - ${parseFloat(selectedLoan.original_amount).toFixed(2)} ({new Date(selectedLoan.loan_date).toLocaleDateString()})
                        </Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="4">
                            Detailed transaction history for this closed petty cash loan.
                        </Dialog.Description>

                        <Box style={{ maxHeight: '500px', overflowY: 'auto' }}>
                            <TransactionsPanel loanId={selectedLoan.id} isMobile={isMobile} />
                        </Box>

                        <Flex justify="end" mt="4">
                            <Dialog.Close>
                                <Button variant="soft">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            )}
        </Box>
    );
};

export default HistoryPanel;
