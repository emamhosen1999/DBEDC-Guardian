/**
 * PettyCashExpenseForm.jsx
 * Dialog form for adding an expense.
 * Pure Radix UI.
 */
import React, { useState, useEffect } from 'react';
import { Dialog, Flex, Text, Button, TextField, Select, Box } from '@radix-ui/themes';
import { ArrowDownIcon, FileTextIcon, CalendarIcon } from '@radix-ui/react-icons';
import axios from 'axios';

const PettyCashExpenseForm = ({ open, onClose, onSuccess, loanId }) => {
    const [formData, setFormData] = useState({
        loan_id: loanId,
        amount: '',
        category: 'office_supplies',
        description: '',
        transaction_date: new Date().toISOString().split('T')[0],
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        setFormData(prev => ({
            ...prev,
            loan_id: loanId,
        }));
    }, [loanId]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError('');

        try {
            const response = await axios.post('/petty-cash/expense', {
                ...formData,
                amount: parseFloat(formData.amount),
            });

            if (response.data.success) {
                onSuccess(response.data.transaction);
            } else {
                setError(response.data.error || 'Failed to add expense');
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
                        <ArrowDownIcon style={{ width: 24, height: 24 }} />
                        Add Expense
                    </Flex>
                </Dialog.Title>

                <Dialog.Description size="2" color="gray" mb="4">
                    Record an expense with bill attachment.
                </Dialog.Description>

                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4">
                        {error && (
                            <Box p="3" style={{ background: 'var(--red-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--red-a6)' }}>
                                <Text size="2" color="red">{error}</Text>
                            </Box>
                        )}

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
                                    <Text color="gray">$</Text>
                                </TextField.Slot>
                            </TextField.Root>
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">CATEGORY *</Text>
                            <Select.Root name="category" value={formData.category} onValueChange={(value) => setFormData(prev => ({ ...prev, category: value }))}>
                                <Select.Trigger placeholder="Select category" />
                                <Select.Content>
                                    <Select.Item value="office_supplies">Office Supplies</Select.Item>
                                    <Select.Item value="meeting_supplies">Meeting Supplies</Select.Item>
                                    <Select.Item value="office_maintenance">Office Maintenance</Select.Item>
                                    <Select.Item value="services">Services</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">DESCRIPTION *</Text>
                            <TextField.Root
                                name="description"
                                value={formData.description}
                                onChange={handleChange}
                                required
                                placeholder="Expense description"
                                maxLength={1000}
                            />
                        </Flex>

                        <Flex direction="column" gap="1">
                            <Text size="2" weight="bold">DATE</Text>
                            <TextField.Root
                                name="transaction_date"
                                type="date"
                                value={formData.transaction_date}
                                onChange={handleChange}
                            >
                                <TextField.Slot>
                                    <CalendarIcon />
                                </TextField.Slot>
                            </TextField.Root>
                        </Flex>

                        <Flex gap="3" mt="2" justify="end">
                            <Dialog.Close>
                                <Button variant="soft" disabled={loading}>
                                    Cancel
                                </Button>
                            </Dialog.Close>
                            <Button type="submit" disabled={loading}>
                                {loading ? 'Adding...' : 'Add Expense'}
                            </Button>
                        </Flex>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default PettyCashExpenseForm;
