import React, { useState, useEffect } from 'react';
import { 
    Dialog, Button, Flex, Grid, Text, TextField, 
    Select, Box, Spinner 
} from '@radix-ui/themes';
import { SewingPinIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const WorkLocationForm = ({ modalType, open, closeModal, onSuccess, currentRow, users = [] }) => {
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    const initialFormState = {
        location: '',
        start_chainage: '',
        end_chainage: '',
        incharge: 'none',
    };

    const [formData, setFormData] = useState(initialFormState);

    useEffect(() => {
        if (modalType === 'update' && currentRow) {
            setFormData({
                location: currentRow.location || '',
                start_chainage: currentRow.start_chainage || '',
                end_chainage: currentRow.end_chainage || '',
                incharge: currentRow.incharge_id ? String(currentRow.incharge_id) : 'none',
            });
        } else {
            setFormData(initialFormState);
        }
        setErrors({});
    }, [currentRow, modalType, open]);

    const handleChange = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});

        const payload = { ...formData };
        if (payload.incharge === 'none') payload.incharge = null;

        try {
            if (modalType === 'update') {
                await axios.put(`/work-locations/${currentRow.id}`, payload);
                showToast.success('Location updated successfully');
            } else {
                await axios.post('/work-locations', payload);
                showToast.success('Location created successfully');
            }
            onSuccess();
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
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !loading) closeModal(); }}>
            <Dialog.Content style={{ maxWidth: 500 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <SewingPinIcon style={{ color: 'var(--accent-9)' }} />
                        {modalType === 'update' ? 'Edit Work Location' : 'Add Work Location'}
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Grid columns="1" gap="4" mt="4">
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Location Name *</Text>
                            <TextField.Root 
                                value={formData.location} 
                                onChange={e => handleChange('location', e.target.value)} 
                                disabled={loading} 
                                placeholder="e.g. Site Alpha" 
                            />
                            {errors.location && <Text size="1" color="red">{errors.location[0]}</Text>}
                        </Box>

                        <Grid columns="2" gap="4">
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">Start Chainage</Text>
                                <TextField.Root 
                                    value={formData.start_chainage} 
                                    onChange={e => handleChange('start_chainage', e.target.value)} 
                                    disabled={loading} 
                                    placeholder="e.g. CH 10+000" 
                                />
                                {errors.start_chainage && <Text size="1" color="red">{errors.start_chainage[0]}</Text>}
                            </Box>
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">End Chainage</Text>
                                <TextField.Root 
                                    value={formData.end_chainage} 
                                    onChange={e => handleChange('end_chainage', e.target.value)} 
                                    disabled={loading} 
                                    placeholder="e.g. CH 15+500" 
                                />
                                {errors.end_chainage && <Text size="1" color="red">{errors.end_chainage[0]}</Text>}
                            </Box>
                        </Grid>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">In-Charge (Optional)</Text>
                            <Select.Root value={formData.incharge} onValueChange={v => handleChange('incharge', v)} disabled={loading}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="none">Unassigned</Select.Item>
                                    {users.map(u => (
                                        <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {errors.incharge && <Text size="1" color="red">{errors.incharge[0]}</Text>}
                        </Box>
                    </Grid>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" type="button" onClick={closeModal} disabled={loading}>Cancel</Button>
                        <Button type="submit" color="indigo" disabled={loading}>
                            {loading ? <Spinner size="1" /> : (modalType === 'update' ? 'Update Location' : 'Add Location')}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default WorkLocationForm;