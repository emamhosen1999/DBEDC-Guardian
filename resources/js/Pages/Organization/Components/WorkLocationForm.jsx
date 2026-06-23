import React, { useState, useEffect } from 'react';
import { 
    Dialog, Button, Flex, Grid, Text, TextField, 
    Select, Box, Spinner 
} from '@radix-ui/themes';
import { SewingPinIcon } from '@radix-ui/react-icons';
import * as useWorkLocationsQuery from '@/api/queries/useWorkLocationsQuery';
import { showToast } from '@/utils/toastUtils';

const WorkLocationForm = ({ modalType, open, closeModal, onSuccess, currentRow, users = [], attendanceTypes = [] }) => {
    const [errors, setErrors] = useState({});

    // React Query mutations
    const createWorkLocation = useWorkLocationsQuery.useCreateWorkLocation();
    const updateWorkLocation = useWorkLocationsQuery.useUpdateWorkLocation();
    const isMutating = createWorkLocation.isPending || updateWorkLocation.isPending;

    const initialFormState = {
        location: '',
        attendance_type_id: 'none',
    };

    const [formData, setFormData] = useState(initialFormState);

    useEffect(() => {
        if (modalType === 'update' && currentRow) {
            setFormData({
                location: currentRow.name || '',
                attendance_type_id: currentRow.attendance_type_id ? String(currentRow.attendance_type_id) : 'none',
            });
        } else {
            setFormData(initialFormState);
        }
        setErrors({});
    }, [currentRow, modalType, open]);

    const handleChange = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        setErrors({});

        const payload = { ...formData };
        if (payload.attendance_type_id === 'none') payload.attendance_type_id = null;

        try {
            if (modalType === 'update') {
                await updateWorkLocation.mutateAsync({ id: currentRow.id, data: payload });
                showToast.success('Location updated successfully');
            } else {
                await createWorkLocation.mutateAsync(payload);
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
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !isMutating) closeModal(); }}>
            <Dialog.Content style={{ maxWidth: 450 }}>
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
                                disabled={isMutating} 
                                placeholder="e.g. Site Alpha" 
                            />
                            {errors.location && <Text size="1" color="red">{errors.location[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Default Attendance Rule (Optional)</Text>
                            <Select.Root value={formData.attendance_type_id} onValueChange={v => handleChange('attendance_type_id', v)} disabled={isMutating}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="none">Inherit / Unassigned</Select.Item>
                                    {attendanceTypes.map(type => (
                                        <Select.Item key={type.id} value={String(type.id)}>{type.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {errors.attendance_type_id && <Text size="1" color="red">{errors.attendance_type_id[0]}</Text>}
                        </Box>
                    </Grid>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" type="button" onClick={closeModal} disabled={isMutating}>
                            Cancel
                        </Button>
                        <Button variant="solid" color="indigo" type="submit" disabled={isMutating}>
                            {isMutating ? <Spinner size="1" /> : modalType === 'update' ? 'Update' : 'Create'}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default WorkLocationForm;
