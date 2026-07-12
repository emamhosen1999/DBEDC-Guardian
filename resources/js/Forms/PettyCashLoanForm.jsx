/**
 * PettyCashLoanForm.jsx
 * Dialog form for creating a new petty cash loan.
 * Pure Radix UI.
 */
import React, { useState } from 'react';
import { Dialog, Flex, Text, Button, TextField, Box } from '@radix-ui/themes';
import { DotsHorizontalIcon, CalendarIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker';

const PettyCashLoanForm = ({ open, onClose, onSuccess }) => {
    const [formData, setFormData] = useState({
        amount: '',
        loan_date: new Date().toISOString().split('T')[0],
        notes: '',
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError('');

        try {
            const response = await axios.post('/petty-cash/loan', {
                ...formData,
                amount: parseFloat(formData.amount),
            });

            if (response.data.success) {
                onSuccess(response.data.loan);
            } else {
                setError(response.data.error || 'Failed to create loan');
            }
        } catch (err) {
            setError(err.response?.data?.error || 'An error occurred');
        } finally {
            setLoading(false);
        }
    };

    const handleChange = (e) => {
        setFormData(prev => ({
            ...prev,
            [e.target.name]: e.target.value,
        }));
    };

    return (
        <Dialog.Root open={open} onOpenChange={onClose}>
            <Dialog.Content style={{ maxWidth: 450, padding: '24px' }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <DotsHorizontalIcon style={{ width: 24, height: 24 }} />
                        Create Petty Cash Loan
                    </Flex>
                </Dialog.Title>

                <Dialog.Description size="2" color="gray" mb="4">
                    Enter the loan amount and details to start tracking your petty cash expenses.
                </Dialog.Description>

                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4">
                        {error && (
                            <Box p="3" style={{ background: 'var(--red-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a6)' }}>
                                <Text size="2" color="red">{error}</Text>
                            </Box>
                        )}

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">LOAN AMOUNT *</Text>
                            <TextField.Root
                                name="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={formData.amount}
                                onChange={handleChange}
                                required
                                placeholder="0.00"
                            >
                                <TextField.Slot>
                                    <Text color="gray">$</Text>
                                </TextField.Slot>
                            </TextField.Root>
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold" mb="1">LOAN DATE</Text>
                            <DateTimePicker
                                mode="date"
                                value={formData.loan_date}
                                onChange={(val) => handleChange({ target: { name: 'loan_date', value: val } })}
                            />
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">NOTES</Text>
                            <TextField.Root
                                name="notes"
                                value={formData.notes}
                                onChange={handleChange}
                                placeholder="Optional notes about this loan"
                                maxLength={1000}
                            />
                        </Flex>

                        <Flex gap="3" mt="2" justify="end">
                            <Dialog.Close>
                                <Button variant="soft" disabled={loading}>
                                    Cancel
                                </Button>
                            </Dialog.Close>
                            <Button type="submit" disabled={loading}>
                                {loading ? 'Creating...' : 'Create Loan'}
                            </Button>
                        </Flex>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default PettyCashLoanForm;
