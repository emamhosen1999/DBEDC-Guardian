import React, { useState, useEffect } from 'react';
import { 
    Dialog, Button, Flex, Grid, Text, TextField, 
    Select, Switch, Box, Spinner 
} from '@radix-ui/themes';
import { PersonIcon } from '@radix-ui/react-icons';
import * as useDesignationsQuery from '@/api/queries/useDesignationsQuery';
import { showToast } from '@/utils/toastUtils';

const DesignationForm = ({ open, onClose, onSuccess, designation = null, departments = [], designations = [] }) => {
    const [errors, setErrors] = useState({});

    // React Query mutations
    const createDesignation = useDesignationsQuery.useCreateDesignation();
    const updateDesignation = useDesignationsQuery.useUpdateDesignation();
    const isMutating = createDesignation.isPending || updateDesignation.isPending;

    const initialFormState = {
        title: '',
        department_id: 'none',
        hierarchy_level: 1,
        parent_id: 'none',
        is_active: true,
    };

    const [formData, setFormData] = useState(initialFormState);

    useEffect(() => {
        if (designation) {
            setFormData({
                title: designation.title || '',
                department_id: designation.department_id ? String(designation.department_id) : 'none',
                hierarchy_level: designation.hierarchy_level || 1,
                parent_id: designation.parent_id ? String(designation.parent_id) : 'none',
                is_active: designation.is_active ?? true,
            });
        } else {
            setFormData(initialFormState);
        }
        setErrors({});
    }, [designation, open]);

    const handleChange = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

    const availableParents = designations?.filter(d => {
        if (designation?.id && d.id === designation.id) return false;
        if (formData.department_id !== 'none' && String(d.department_id) !== formData.department_id) return false;
        return true;
    }) || [];

    const handleSubmit = async (e) => {
        e.preventDefault();
        setErrors({});

        const payload = { ...formData };
        if (payload.department_id === 'none') payload.department_id = null;
        if (payload.parent_id === 'none') payload.parent_id = null;

        try {
            let response;
            if (designation) {
                response = await updateDesignation.mutateAsync({ id: designation.id, data: payload });
                showToast.success('Designation updated successfully');
            } else {
                response = await createDesignation.mutateAsync(payload);
                showToast.success('Designation created successfully');
            }
            onSuccess(response.designation);
            onClose();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors);
                showToast.error('Please check the form for errors');
            } else {
                showToast.error('An error occurred');
            }
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !isMutating) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 500 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <PersonIcon style={{ color: 'var(--accent-9)' }} />
                        {designation ? 'Edit Designation' : 'Create Designation'}
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Grid columns="1" gap="4" mt="4">
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Title *</Text>
                            <TextField.Root 
                                value={formData.title} 
                                onChange={e => handleChange('title', e.target.value)} 
                                disabled={isMutating} 
                                placeholder="e.g. Senior Developer" 
                            />
                            {errors.title && <Text size="1" color="red">{errors.title[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Department</Text>
                            <Select.Root value={formData.department_id} onValueChange={v => handleChange('department_id', v)} disabled={isMutating}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="none">Select Department...</Select.Item>
                                    {departments.map(d => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {errors.department_id && <Text size="1" color="red">{errors.department_id[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Hierarchy Level (1 = Highest)</Text>
                            <TextField.Root 
                                type="number" 
                                min="1" max="10" 
                                value={formData.hierarchy_level} 
                                onChange={e => handleChange('hierarchy_level', parseInt(e.target.value) || 1)} 
                                disabled={isMutating} 
                            />
                        </Box>

                        {availableParents.length > 0 && (
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">Reports To (Parent Designation)</Text>
                                <Select.Root value={formData.parent_id} onValueChange={v => handleChange('parent_id', v)} disabled={isMutating}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="none">None (Top Level)</Select.Item>
                                        {availableParents.map(d => (
                                            <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Box>
                        )}

                        <Box style={{ gridColumn: '1 / -1' }} mt="2">
                            <Flex align="center" justify="between" p="3" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-2)', backgroundColor: 'var(--gray-a2)' }}>
                                <Box>
                                    <Text size="2" weight="bold" display="block">Active Status</Text>
                                    <Text size="1" color="gray">Active designations can be assigned to employees</Text>
                                </Box>
                                <Switch checked={formData.is_active} onCheckedChange={checked => handleChange('is_active', checked)} color="green" disabled={isMutating} />
                            </Flex>
                        </Box>
                    </Grid>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" type="button" onClick={onClose} disabled={isMutating}>Cancel</Button>
                        <Button type="submit" color="indigo" disabled={isMutating}>
                            {isMutating ? <Spinner size="1" /> : (designation ? 'Update Designation' : 'Create Designation')}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DesignationForm;