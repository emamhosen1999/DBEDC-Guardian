import React, { useState, useEffect } from 'react';
import {
    Dialog, Button, Flex, Grid, Text, TextField,
    Select, Box, Spinner, Switch, TextArea, Checkbox
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
        code: '',
        description: '',
        address: '',
        latitude: '',
        longitude: '',
        geofence_radius: '',
        timezone: '',
        is_active: true,
        attendance_type_ids: [],
    };

    const [formData, setFormData] = useState(initialFormState);

    useEffect(() => {
        if (modalType === 'update' && currentRow) {
            // Prefer the multi-method set; fall back to the legacy single id.
            const ids = (currentRow.attendance_types?.length
                ? currentRow.attendance_types.map(t => t.id)
                : (currentRow.attendance_type_id ? [currentRow.attendance_type_id] : []))
                .map(Number);
            setFormData({
                location: currentRow.name || '',
                code: currentRow.code || '',
                description: currentRow.description || '',
                address: currentRow.address || '',
                latitude: currentRow.latitude ?? '',
                longitude: currentRow.longitude ?? '',
                geofence_radius: currentRow.geofence_radius ?? '',
                timezone: currentRow.timezone || '',
                is_active: currentRow.is_active ?? true,
                attendance_type_ids: ids,
            });
        } else {
            setFormData(initialFormState);
        }
        setErrors({});
    }, [currentRow, modalType, open]);

    const handleChange = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

    const toggleAttendanceType = (id) => setFormData(prev => ({
        ...prev,
        attendance_type_ids: prev.attendance_type_ids.includes(id)
            ? prev.attendance_type_ids.filter(x => x !== id)
            : [...prev.attendance_type_ids, id],
    }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        setErrors({});

        const payload = { ...formData };
        // Normalise empty numeric/optional fields to null so the API stores NULL, not "".
        ['latitude', 'longitude', 'geofence_radius', 'code', 'description', 'address', 'timezone'].forEach((f) => {
            if (payload[f] === '' || payload[f] === undefined) payload[f] = null;
        });

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
            <Dialog.Content style={{ maxWidth: 560 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <SewingPinIcon style={{ color: 'var(--accent-9)' }} />
                        {modalType === 'update' ? 'Edit Work Location' : 'Add Work Location'}
                    </Flex>
                </Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="2">
                    Where employees clock in and the default attendance rule that applies there.
                </Dialog.Description>

                <form onSubmit={handleSubmit}>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="4" mt="2">
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Location Name *</Text>
                            <TextField.Root
                                value={formData.location}
                                onChange={e => handleChange('location', e.target.value)}
                                disabled={isMutating}
                                placeholder="e.g. Site Alpha Camp"
                            />
                            {errors.location && <Text size="1" color="red">{errors.location[0]}</Text>}
                            {errors.name && <Text size="1" color="red">{errors.name[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Code (Optional)</Text>
                            <TextField.Root
                                value={formData.code}
                                onChange={e => handleChange('code', e.target.value)}
                                disabled={isMutating}
                                placeholder="e.g. SITE-A"
                            />
                            {errors.code && <Text size="1" color="red">{errors.code[0]}</Text>}
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text size="2" weight="medium" mb="1" as="div">Address (Optional)</Text>
                            <TextField.Root
                                value={formData.address}
                                onChange={e => handleChange('address', e.target.value)}
                                disabled={isMutating}
                                placeholder="Street, city, region"
                            />
                            {errors.address && <Text size="1" color="red">{errors.address[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Latitude (Optional)</Text>
                            <TextField.Root
                                type="number" step="any"
                                value={formData.latitude}
                                onChange={e => handleChange('latitude', e.target.value)}
                                disabled={isMutating}
                                placeholder="e.g. 23.8103"
                            />
                            {errors.latitude && <Text size="1" color="red">{errors.latitude[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Longitude (Optional)</Text>
                            <TextField.Root
                                type="number" step="any"
                                value={formData.longitude}
                                onChange={e => handleChange('longitude', e.target.value)}
                                disabled={isMutating}
                                placeholder="e.g. 90.4125"
                            />
                            {errors.longitude && <Text size="1" color="red">{errors.longitude[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Geofence Radius (meters)</Text>
                            <TextField.Root
                                type="number" min="0"
                                value={formData.geofence_radius}
                                onChange={e => handleChange('geofence_radius', e.target.value)}
                                disabled={isMutating}
                                placeholder="e.g. 150"
                            />
                            {errors.geofence_radius && <Text size="1" color="red">{errors.geofence_radius[0]}</Text>}
                        </Box>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Timezone (Optional)</Text>
                            <TextField.Root
                                value={formData.timezone}
                                onChange={e => handleChange('timezone', e.target.value)}
                                disabled={isMutating}
                                placeholder="e.g. Asia/Dhaka"
                            />
                            {errors.timezone && <Text size="1" color="red">{errors.timezone[0]}</Text>}
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text size="2" weight="medium" mb="1" as="div">Allowed Attendance Methods</Text>
                            <Text size="1" color="gray" mb="2" as="div">
                                Select one or more. Employees here can punch via <strong>any</strong> selected method
                                (e.g. biometric OR geofence OR WiFi). Personal overrides replace this set.
                            </Text>
                            <Flex direction="column" gap="2" style={{ border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-2)', padding: '10px' }}>
                                {attendanceTypes.length === 0 && (
                                    <Text size="1" color="gray">No attendance types configured.</Text>
                                )}
                                {attendanceTypes.map(type => (
                                    <Text as="label" size="2" key={type.id} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                                        <Checkbox
                                            checked={formData.attendance_type_ids.includes(type.id)}
                                            onCheckedChange={() => toggleAttendanceType(type.id)}
                                            disabled={isMutating}
                                        />
                                        {type.name}
                                    </Text>
                                ))}
                            </Flex>
                            {errors.attendance_type_ids && <Text size="1" color="red">{errors.attendance_type_ids[0]}</Text>}
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text size="2" weight="medium" mb="1" as="div">Description (Optional)</Text>
                            <TextArea
                                value={formData.description}
                                onChange={e => handleChange('description', e.target.value)}
                                disabled={isMutating}
                                placeholder="Notes about this location"
                            />
                            {errors.description && <Text size="1" color="red">{errors.description[0]}</Text>}
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Flex align="center" justify="between">
                                <Box>
                                    <Text size="2" weight="medium" as="div">Active</Text>
                                    <Text size="1" color="gray" as="div">Inactive locations can't be assigned to employees.</Text>
                                </Box>
                                <Switch
                                    checked={formData.is_active}
                                    onCheckedChange={v => handleChange('is_active', v)}
                                    disabled={isMutating}
                                />
                            </Flex>
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
