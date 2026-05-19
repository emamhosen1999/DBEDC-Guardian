import React, { useState, useEffect } from 'react';
import { Box, Button, Card, Flex, Grid, Text, TextField, Select } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, CircleIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import InfoRow from "@/Components/InfoRow.jsx";

const SalaryInformationForm = ({ user, setUser }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    
    // Form state initialized with current user data
    const [formData, setFormData] = useState({
        id: user.id,
        salary_basis: user.salary_basis || '',
        salary_amount: user.salary_amount || '',
        payment_type: user.payment_type || '',
        pf_contribution: user.pf_contribution ?? false,
        pf_no: user.pf_no || '',
        employee_pf_rate: user.employee_pf_rate || 0,
        additional_pf_rate: user.additional_pf_rate || 0,
        esi_contribution: user.esi_contribution ?? false,
        esi_no: user.esi_no || '',
        employee_esi_rate: user.employee_esi_rate || 0,
        additional_esi_rate: user.additional_esi_rate || 0
    });

    const handleChange = (key, value) => {
        setFormData(prev => ({ ...prev, [key]: value }));
    };

    const handleCancel = () => {
        // Reset to original props
        setFormData({
            id: user.id,
            salary_basis: user.salary_basis || '',
            salary_amount: user.salary_amount || '',
            payment_type: user.payment_type || '',
            pf_contribution: user.pf_contribution ?? false,
            pf_no: user.pf_no || '',
            employee_pf_rate: user.employee_pf_rate || 0,
            additional_pf_rate: user.additional_pf_rate || 0,
            esi_contribution: user.esi_contribution ?? false,
            esi_no: user.esi_no || '',
            employee_esi_rate: user.employee_esi_rate || 0,
            additional_esi_rate: user.additional_esi_rate || 0
        });
        setIsEditing(false);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const { data } = await axios.post(route('profile.update'), { ruleSet: 'salary', ...formData });
            setUser(data.user);
            showToast.success('Salary information updated');
            setIsEditing(false);
        } catch (err) {
            showToast.error('Failed to save salary information');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Card variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Salary & Statutory Info</Text>
                {!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}><Pencil1Icon /> Edit</Button>
                ) : (
                    <Button variant="ghost" size="1" color="red" onClick={handleCancel}><Cross2Icon /> Cancel</Button>
                )}
            </Flex>

            {!isEditing ? (
                <Box>
                    <Text size="2" weight="bold" color="indigo" mb="2">Salary Basis</Text>
                    <InfoRow label="Salary Amount" value={`$${user.salary_amount || '0'}`} icon={<CircleIcon />} />
                    <InfoRow label="Payment Type" value={user.payment_type} />
                    
                    <Text size="2" weight="bold" color="indigo" mb="2" mt="3">Statutory (PF/ESI)</Text>
                    <InfoRow label="PF No" value={user.pf_no || '—'} />
                    <InfoRow label="ESI No" value={user.esi_no || '—'} />
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    <Grid gap="3" mb="4">
                        <Box>
                            <Text size="1" weight="medium">Salary Amount ($)</Text>
                            <TextField.Root type="number" value={formData.salary_amount} onChange={e => handleChange('salary_amount', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="1" weight="medium">Payment Type</Text>
                            <Select.Root value={formData.payment_type} onValueChange={v => handleChange('payment_type', v)}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="Bank transfer">Bank transfer</Select.Item>
                                    <Select.Item value="Cash">Cash</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="1" weight="medium">PF Number</Text>
                            <TextField.Root value={formData.pf_no} onChange={e => handleChange('pf_no', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="1" weight="medium">ESI Number</Text>
                            <TextField.Root value={formData.esi_no} onChange={e => handleChange('esi_no', e.target.value)} />
                        </Box>
                    </Grid>
                    <Flex justify="end">
                        <Button type="submit" disabled={processing}>Save Changes</Button>
                    </Flex>
                </form>
            )}
        </Card>
    );
};

export default SalaryInformationForm;