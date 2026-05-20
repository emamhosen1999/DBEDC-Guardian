import React, { useState, useEffect } from 'react';
import { 
    Dialog, Button, Flex, Grid, Text, TextField, 
    Select, Switch, Box, Spinner 
} from '@radix-ui/themes';
import { HomeIcon, PersonIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const DepartmentForm = ({ open, onClose, onSuccess, department = null, managers = [], parentDepartments = [], readOnly = false }) => {
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    const initialFormState = {
        name: '',
        code: '',
        description: '',
        parent_id: 'none',
        manager_id: 'none',
        location: '',
        is_active: true,
        established_date: '',
    };

    const [formData, setFormData] = useState(initialFormState);

    useEffect(() => {
        if (department) {
            setFormData({
                name: department.name || '',
                code: department.code || '',
                description: department.description || '',
                parent_id: department.parent_id ? String(department.parent_id) : 'none',
                manager_id: department.manager_id ? String(department.manager_id) : 'none',
                location: department.location || '',
                is_active: department.is_active ?? true,
                established_date: department.established_date ? department.established_date.split('T')[0] : '',
            });
        } else {
            setFormData(initialFormState);
        }
        setErrors({});
    }, [department, open]);

    const handleChange = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (readOnly) return onClose();
        
        setLoading(true);
        setErrors({});

        const payload = { ...formData };
        if (payload.parent_id === 'none') payload.parent_id = null;
        if (payload.manager_id === 'none') payload.manager_id = null;

        try {
            if (department) {
                await axios.put(`/departments/${department.id}`, payload);
                showToast.success('Department updated successfully');
            } else {
                await axios.post('/departments', payload);
                showToast.success('Department created successfully');
            }
            onSuccess();
            onClose();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors);
                showToast.error('Please check the form for errors');
            } else {
                showToast.error(error.response?.data?.message || 'An error occurred');
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !loading) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 600 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <HomeIcon style={{ color: 'var(--accent-9)' }} />
                        {readOnly ? 'View Department' : (department ? 'Edit Department' : 'Create Department')}
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="4" mt="4">
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Department Name *</Text>
                            <TextField.Root 
                                value={formData.name} 
                                onChange={e => handleChange('name', e.target.value)} 
                                disabled={readOnly || loading} 
                                placeholder="e.g. Human Resources" 
                            />
                            {errors.name && <Text size="1" color="red">{errors.name[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Department Code</Text>
                            <TextField.Root 
                                value={formData.code} 
                                onChange={e => handleChange('code', e.target.value)} 
                                disabled={readOnly || loading} 
                                placeholder="e.g. HR-01" 
                            />
                            {errors.code && <Text size="1" color="red">{errors.code[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Parent Department</Text>
                            <Select.Root value={formData.parent_id} onValueChange={v => handleChange('parent_id', v)} disabled={readOnly || loading}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="none">None (Top Level)</Select.Item>
                                    {parentDepartments.filter(d => d.id !== department?.id).map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {errors.parent_id && <Text size="1" color="red">{errors.parent_id[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Department Manager</Text>
                            <Select.Root value={formData.manager_id} onValueChange={v => handleChange('manager_id', v)} disabled={readOnly || loading}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="none">Unassigned</Select.Item>
                                    {managers.map(m => (
                                        <Select.Item key={m.id} value={String(m.id)}>{m.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Location</Text>
                            <TextField.Root 
                                value={formData.location} 
                                onChange={e => handleChange('location', e.target.value)} 
                                disabled={readOnly || loading} 
                                placeholder="e.g. Building A, Floor 3" 
                            />
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Established Date</Text>
                            <TextField.Root 
                                type="date"
                                value={formData.established_date} 
                                onChange={e => handleChange('established_date', e.target.value)} 
                                disabled={readOnly || loading} 
                            />
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }} mt="2">
                            <Flex align="center" justify="between" p="3" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-2)', backgroundColor: 'var(--gray-a2)' }}>
                                <Box>
                                    <Text size="2" weight="bold" display="block">Active Status</Text>
                                    <Text size="1" color="gray">Active departments appear in selections and reports</Text>
                                </Box>
                                <Switch checked={formData.is_active} onCheckedChange={checked => handleChange('is_active', checked)} color="green" disabled={readOnly || loading} />
                            </Flex>
                        </Box>
                    </Grid>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" type="button" onClick={onClose} disabled={loading}>
                            {readOnly ? 'Close' : 'Cancel'}
                        </Button>
                        {!readOnly && (
                            <Button type="submit" color="indigo" disabled={loading}>
                                {loading ? <Spinner size="1" /> : (department ? 'Update Department' : 'Create Department')}
                            </Button>
                        )}
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DepartmentForm;