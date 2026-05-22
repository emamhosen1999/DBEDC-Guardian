import React, { useEffect } from 'react';
import { 
    Dialog, Button, Flex, Grid, Text, TextField, 
    Select, Box, Spinner 
} from '@radix-ui/themes';
import { SewingPinIcon } from '@radix-ui/react-icons';
import { useForm } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';

const WorkLocationForm = ({ modalType, open, closeModal, onSuccess, currentRow, users = [] }) => {
    const initialFormState = {
        location: '',
        start_chainage: '',
        end_chainage: '',
        incharge: 'none',
    };

    const { data, setData, processing, errors, put, post, reset } = useForm(initialFormState);

    useEffect(() => {
        if (modalType === 'update' && currentRow) {
            setData({
                location: currentRow.location || '',
                start_chainage: currentRow.start_chainage || '',
                end_chainage: currentRow.end_chainage || '',
                incharge: currentRow.incharge_id ? String(currentRow.incharge_id) : 'none',
            });
        } else {
            reset();
        }
    }, [currentRow, modalType, open, setData, reset]);

    const handleChange = (field, value) => setData(field, value);

    const handleSubmit = (e) => {
        e.preventDefault();

        const payload = { ...data };
        if (payload.incharge === 'none') payload.incharge = null;

        const options = {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                showToast.success(modalType === 'update' ? 'Location updated successfully' : 'Location created successfully');
                onSuccess();
                reset();
            },
            onError: (errors) => {
                showToast.error('Please check the form for errors');
            }
        };

        if (modalType === 'update') {
            put(route('work-locations.update', { id: currentRow.id }), payload, options);
        } else {
            post(route('work-locations.store'), payload, options);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={v => { if (!v && !processing) closeModal(); }}>
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
                                value={data.location} 
                                onChange={e => handleChange('location', e.target.value)} 
                                disabled={processing} 
                                placeholder="e.g. Site Alpha" 
                            />
                            {errors.location && <Text size="1" color="red">{errors.location}</Text>}
                        </Box>

                        <Grid columns="2" gap="4">
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">Start Chainage</Text>
                                <TextField.Root 
                                    value={data.start_chainage} 
                                    onChange={e => handleChange('start_chainage', e.target.value)} 
                                    disabled={processing} 
                                    placeholder="e.g. CH 10+000" 
                                />
                                {errors.start_chainage && <Text size="1" color="red">{errors.start_chainage}</Text>}
                            </Box>
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">End Chainage</Text>
                                <TextField.Root 
                                    value={data.end_chainage} 
                                    onChange={e => handleChange('end_chainage', e.target.value)} 
                                    disabled={processing} 
                                    placeholder="e.g. CH 15+500" 
                                />
                                {errors.end_chainage && <Text size="1" color="red">{errors.end_chainage}</Text>}
                            </Box>
                        </Grid>

                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">In-Charge (Optional)</Text>
                            <Select.Root value={data.incharge} onValueChange={v => handleChange('incharge', v)} disabled={processing}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="none">Unassigned</Select.Item>
                                    {users.map(u => (
                                        <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {errors.incharge && <Text size="1" color="red">{errors.incharge}</Text>}
                        </Box>
                    </Grid>

                    <Flex justify="end" gap="3" mt="5">
                        <Button variant="soft" color="gray" type="button" onClick={closeModal} disabled={processing}>Cancel</Button>
                        <Button type="submit" color="indigo" disabled={processing}>
                            {processing ? <Spinner size="1" /> : (modalType === 'update' ? 'Update Location' : 'Add Location')}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default WorkLocationForm;