/**
 * PettyCashLoanForm.jsx
 * Dialog form for creating a new petty cash fund.
 * Supports fund_name for multi-fund. Currency: BDT (৳).
 */
import React, { useState } from 'react';
import { Dialog, Flex, Text, Button, TextField, Box } from '@radix-ui/themes';
import { BackpackIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker';

const PettyCashLoanForm = ({ open, onClose, onSuccess }) => {
    const [formData, setFormData] = useState({
        amount: '',
        fund_name: '',
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
                setError(response.data.error || 'Failed to create fund');
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
                        <BackpackIcon style={{ width: 24, height: 24 }} />
                        Create Petty Cash Fund
                    </Flex>
                </Dialog.Title>

                <Dialog.Description size="2" color="gray" mb="4">
                    Create a named fund to track expenses. You can have multiple funds active simultaneously.
                </Dialog.Description>

                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4">
                        {error && (
                            <Box p="3" style={{ background: 'var(--red-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a6)' }}>
                                <Text size="2" color="red">{error}</Text>
                            </Box>
                        )}

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">FUND NAME</Text>
                            <TextField.Root
                                name="fund_name"
                                value={formData.fund_name}
                                onChange={handleChange}
                                placeholder="e.g. Fuel Fund, Office Fund, General Fund"
                                maxLength={255}
                            />
                            <Text size="1" color="gray">Leave empty for "General Fund"</Text>
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">AMOUNT *</Text>
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
                                    <Text color="gray">৳</Text>
                                </TextField.Slot>
                            </TextField.Root>
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold" mb="1">DATE</Text>
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
                                placeholder="Optional notes about this fund"
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
                                {loading ? 'Creating...' : 'Create Fund'}
                            </Button>
                        </Flex>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default PettyCashLoanForm;
