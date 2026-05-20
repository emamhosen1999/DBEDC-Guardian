import React, { useState, useEffect } from 'react';
import { 
    Dialog, Button, Flex, Grid, Text, TextField, 
    Select, Switch, Box, Spinner 
} from '@radix-ui/themes';
import { Component1Icon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const DesignationForm = ({ open, onClose, onSuccess, designation = null, departments = [], designations = [] }) => {
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    const initialFormState = {
        title: '',
        department_id: '',
        hierarchy_level: 1,
        parent_id: '',
        is_active: true,
    };

    const [formData, setFormData] = useState(initialFormState);

    useEffect(() => {
        if (designation) {
            setFormData({
                title: designation.title || '',
                department_id: String(designation.department_id || ''),
                hierarchy_level: designation.hierarchy_level || 1,
                parent_id: designation.parent_id ? String(designation.parent_id) : '',
                is_active: designation.is_active ?? true,
            });
        } else {
            setFormData(initialFormState);
        }
        setErrors({});
    }, [designation, open]);

    const availableParents = designations?.filter(d => {
        if (designation?.id && d.id === designation.id) return false;
        if (formData.department_id && String(d.department_id) !== String(formData.department_id)) return false;
        if (formData.hierarchy_level && d.hierarchy_level >= formData.hierarchy_level) return false;
        return true;
    }) || [];

    const handleChange = (name, value) => {
        setFormData(prev => ({ ...prev, [name]: value }));
        if (errors[name]) setErrors(prev => ({ ...prev, [name]: null }));

        if (name === 'department_id') setFormData(prev => ({ ...prev, parent_id: '' }));
        if (name === 'hierarchy_level' && value === 1) setFormData(prev => ({ ...prev, parent_id: '' }));
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setLoading(true);
        setErrors({});

        try {
            const apiData = { 
                ...formData,
                parent_id: formData.hierarchy_level === 1 ? null : formData.parent_id || null
            };
            
            let response;
            if (designation?.id) {
                response = await axios.put(`/designations/${designation.id}`, apiData);
            } else {
                response = await axios.post('/designations', apiData);
            }
            
            showToast.success(response.data.message || `Designation ${designation ? 'updated' : 'created'} successfully`);
            onSuccess(response.data.designation);
            onClose();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors || {});
                showToast.error('Validation failed. Please check the fields.');
            } else {
                showToast.error(error.response?.data?.message || 'An unexpected error occurred');
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !loading) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 600 }}>
                <Dialog.Title mb="4">
                    <Flex align="center" gap="2">
                        <Component1Icon style={{ color: 'var(--indigo-9)' }} />
                        {designation ? 'Edit Designation' : 'Create New Designation'}
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="4" mb="5">
                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Designation Title <Text color="red">*</Text></Text>
                            <TextField.Root 
                                placeholder="e.g. Senior Software Engineer" 
                                value={formData.title} 
                                onChange={e => handleChange('title', e.target.value)} 
                                color={errors.title ? 'red' : undefined}
                            />
                            {errors.title && <Text size="1" color="red" mt="1">{errors.title[0]}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Department <Text color="red">*</Text></Text>
                            <Select.Root value={formData.department_id || undefined} onValueChange={v => handleChange('department_id', v)}>
                                <Select.Trigger style={{ width: '100%' }} placeholder="Select Department" color={errors.department_id ? 'red' : undefined} />
                                <Select.Content>
                                    {departments?.map(dept => (
                                        <Select.Item key={dept.id} value={String(dept.id)}>{dept.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {errors.department_id && <Text size="1" color="red" mt="1">{errors.department_id[0]}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Hierarchy Level <Text color="red">*</Text></Text>
                            <TextField.Root 
                                type="number" 
                                min={1} max={10} 
                                value={formData.hierarchy_level} 
                                onChange={e => handleChange('hierarchy_level', parseInt(e.target.value) || 1)} 
                                color={errors.hierarchy_level ? 'red' : undefined}
                            />
                            <Text size="1" color="gray" mt="1">1 = Highest (CEO), 2 = VP/Manager</Text>
                            {errors.hierarchy_level && <Text size="1" color="red" mt="1">{errors.hierarchy_level[0]}</Text>}
                        </Box>

                        {formData.hierarchy_level > 1 && (
                            <Box style={{ gridColumn: '1 / -1' }}>
                                <Text as="label" size="2" weight="medium" mb="1" display="block">Parent Designation (Reports To)</Text>
                                <Select.Root 
                                    value={formData.parent_id || undefined} 
                                    onValueChange={v => handleChange('parent_id', v)}
                                    disabled={!formData.department_id || availableParents.length === 0}
                                >
                                    <Select.Trigger style={{ width: '100%' }} placeholder={!formData.department_id ? "Select department first" : availableParents.length === 0 ? "No higher-level options" : "Select parent designation"} />
                                    <Select.Content>
                                        {availableParents.map(parent => (
                                            <Select.Item key={parent.id} value={String(parent.id)}>
                                                {parent.title} (Level {parent.hierarchy_level})
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                {errors.parent_id && <Text size="1" color="red" mt="1">{errors.parent_id[0]}</Text>}
                            </Box>
                        )}

                        <Box style={{ gridColumn: '1 / -1' }} mt="2">
                            <Flex align="center" justify="between" p="3" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-2)', backgroundColor: 'var(--gray-a2)' }}>
                                <Box>
                                    <Text size="2" weight="bold" display="block">Active Status</Text>
                                    <Text size="1" color="gray">Active designations can be assigned to employees</Text>
                                </Box>
                                <Switch checked={formData.is_active} onCheckedChange={checked => handleChange('is_active', checked)} color="green" />
                            </Flex>
                        </Box>
                    </Grid>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" type="button" onClick={onClose} disabled={loading}>Cancel</Button>
                        <Button type="submit" color="indigo" disabled={loading}>
                            {loading ? <Spinner size="1" /> : (designation ? 'Update Designation' : 'Create Designation')}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DesignationForm;