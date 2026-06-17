import React, { useState, useEffect, useMemo } from 'react';
import {
    Badge,
    Box,
    Button,
    Dialog,
    Flex,
    Grid,
    Select,
    Switch,
    Text,
    TextArea,
    TextField,
} from '@radix-ui/themes';
import { CalendarIcon } from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import { differenceInDays } from 'date-fns';
import axios from 'axios';

const holidayTypes = [
    { key: 'public', label: 'Public Holiday', icon: '🏛️' },
    { key: 'religious', label: 'Religious Holiday', icon: '🕌' },
    { key: 'national', label: 'National Holiday', icon: '🇧🇩' },
    { key: 'company', label: 'Company Holiday', icon: '🏢' },
    { key: 'optional', label: 'Optional Holiday', icon: '📅' },
];

const HolidayForm = ({ open, closeModal, setHolidaysData, currentHoliday }) => {
    const [formData, setFormData] = useState({
        title: '',
        description: '',
        from_date: '',
        to_date: '',
        type: 'company',
        is_recurring: false,
        is_active: true,
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (currentHoliday) {
            setFormData({
                title: currentHoliday.title || '',
                description: currentHoliday.description || '',
                from_date: currentHoliday.from_date || '',
                to_date: currentHoliday.to_date || '',
                type: currentHoliday.type || 'company',
                is_recurring: !!currentHoliday.is_recurring,
                is_active: currentHoliday.is_active !== undefined ? currentHoliday.is_active : true,
            });
        } else {
            setFormData({
                title: '',
                description: '',
                from_date: '',
                to_date: '',
                type: 'company',
                is_recurring: false,
                is_active: true,
            });
        }
        setErrors({});
    }, [currentHoliday, open]);

    const duration = useMemo(() => {
        if (formData.from_date && formData.to_date) {
            return differenceInDays(new Date(formData.to_date), new Date(formData.from_date)) + 1;
        }
        return 1;
    }, [formData.from_date, formData.to_date]);

    const err = (key) => errors[key]?.[0] ?? errors[key];

    const handleFieldChange = (field, value) => {
        setFormData((prev) => {
            const next = { ...prev, [field]: value };
            if (field === 'from_date' && !prev.to_date) next.to_date = value;
            return next;
        });
        if (errors[field]) setErrors((prev) => ({ ...prev, [field]: undefined }));
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});
        try {
            const submitData = {
                title: formData.title,
                description: formData.description,
                fromDate: formData.from_date,
                toDate: formData.to_date,
                type: formData.type,
                is_recurring: formData.is_recurring,
                is_active: formData.is_active,
            };
            if (currentHoliday) submitData.id = currentHoliday.id;
            const response = await axios.post(route('holiday-add'), submitData);
            if (response.status === 200) {
                setHolidaysData(response.data.holidays);
                showToast.success(response.data.message || 'Holiday saved successfully!');
                closeModal();
            }
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors || {});
                showToast.error('Please check the form for errors');
            } else {
                showToast.error(error.response?.data?.message || 'Failed to save holiday');
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v && !processing) closeModal(); }}>
            <Dialog.Content style={{ maxWidth: 640 }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <CalendarIcon style={{ width: 20, height: 20 }} />
                        {currentHoliday ? 'Edit holiday' : 'Add holiday'}
                    </Flex>
                </Dialog.Title>

                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4" mt="4">
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Title</Text>
                            <TextField.Root
                                value={formData.title}
                                onChange={(e) => handleFieldChange('title', e.target.value)}
                                placeholder="Holiday name"
                            />
                            {err('title') && <Text size="1" color="red">{err('title')}</Text>}
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Description</Text>
                            <TextArea
                                value={formData.description}
                                onChange={(e) => handleFieldChange('description', e.target.value)}
                                rows={3}
                            />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" as="div">Type</Text>
                            <Select.Root value={formData.type} onValueChange={(v) => handleFieldChange('type', v)}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    {holidayTypes.map((t) => (
                                        <Select.Item key={t.key} value={t.key}>
                                            {t.icon} {t.label}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Grid columns={{ initial: '1', sm: '2' }} gap="3">
                            <Box>
                                <Text size="2" weight="medium" mb="1" as="div">From</Text>
                                <TextField.Root
                                    type="date"
                                    value={formData.from_date}
                                    onChange={(e) => handleFieldChange('from_date', e.target.value)}
                                />
                                {err('fromDate') && <Text size="1" color="red">{err('fromDate')}</Text>}
                            </Box>
                            <Box>
                                <Flex justify="between" align="center" mb="1">
                                    <Text size="2" weight="medium" as="div">To</Text>
                                    {duration > 1 && <Badge variant="soft">{duration} days</Badge>}
                                </Flex>
                                <TextField.Root
                                    type="date"
                                    value={formData.to_date}
                                    onChange={(e) => handleFieldChange('to_date', e.target.value)}
                                />
                                {err('toDate') && <Text size="1" color="red">{err('toDate')}</Text>}
                            </Box>
                        </Grid>
                        <Flex gap="4" wrap="wrap">
                            <Flex align="center" gap="2">
                                <Switch
                                    checked={formData.is_recurring}
                                    onCheckedChange={(c) => handleFieldChange('is_recurring', c)}
                                />
                                <Text size="2">Recurring yearly</Text>
                            </Flex>
                            <Flex align="center" gap="2">
                                <Switch
                                    checked={formData.is_active}
                                    onCheckedChange={(c) => handleFieldChange('is_active', c)}
                                />
                                <Text size="2">Active</Text>
                            </Flex>
                        </Flex>
                    </Flex>
                    <Flex justify="end" gap="3" mt="5">
                        <Button type="button" variant="soft" color="gray" onClick={closeModal} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            {currentHoliday ? 'Update' : 'Create'}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default HolidayForm;