import React, { useState, useEffect } from 'react';
import {
    Dialog,
    Box,
    Grid,
    Text,
    IconButton,
    Button,
    TextField,
    Select,
    Flex,
    Spinner,
    TextArea,
} from '@radix-ui/themes';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker';

const fieldError = (errors, field) => {
    const e = errors[field];
    if (!e) return null;
    return Array.isArray(e) ? e[0] : e;
};

const AddEditTrainingForm = ({ open, onClose, training = null, fetchData, currentPage, perPage, filterData }) => {
    const isEditing = !!training;
    const [formData, setFormData] = useState({
        title: '',
        description: '',
        category_id: '',
        instructor: '',
        duration: '',
        duration_unit: 'days',
        status: 'planned',
        location: '',
        max_participants: '',
        start_date: '',
        end_date: '',
        cost: '',
    });
    const [categories, setCategories] = useState([]);
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        const fetchCategories = async () => {
            try {
                const response = await axios.get(route('hr.training.categories.list'));
                setCategories(response.data);
            } catch (error) {
                console.error('Failed to fetch categories:', error);
                showToast.error('Failed to load training categories.');
            }
        };

        fetchCategories();

        if (isEditing && training) {
            setFormData({
                title: training.title || '',
                description: training.description || '',
                category_id: training.category_id || '',
                instructor: training.instructor || '',
                duration: training.duration || '',
                duration_unit: training.duration_unit || 'days',
                status: training.status || 'planned',
                location: training.location || '',
                max_participants: training.max_participants || '',
                start_date: training.start_date ? training.start_date.substring(0, 10) : '',
                end_date: training.end_date ? training.end_date.substring(0, 10) : '',
                cost: training.cost || '',
            });
        }
    }, [isEditing, training]);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));

        if (errors[name]) {
            setErrors(prev => ({
                ...prev,
                [name]: null
            }));
        }
    };

    const handleSelectChange = (name, value) => {
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
        if (errors[name]) {
            setErrors(prev => ({
                ...prev,
                [name]: null
            }));
        }
    };

    const validateForm = () => {
        const newErrors = {};

        if (!formData.title) newErrors.title = 'Title is required';
        if (!formData.description) newErrors.description = 'Description is required';
        if (!formData.category_id) newErrors.category_id = 'Category is required';
        if (!formData.duration) newErrors.duration = 'Duration is required';
        if (!formData.status) newErrors.status = 'Status is required';

        if (formData.max_participants && isNaN(formData.max_participants)) {
            newErrors.max_participants = 'Max participants must be a number';
        }

        if (formData.cost && isNaN(formData.cost)) {
            newErrors.cost = 'Cost must be a number';
        }

        if (formData.start_date && formData.end_date) {
            const startDate = new Date(formData.start_date);
            const endDate = new Date(formData.end_date);

            if (endDate < startDate) {
                newErrors.end_date = 'End date cannot be before start date';
            }
        }

        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (!validateForm()) return;

        setLoading(true);

        try {
            let response;

            if (isEditing) {
                response = await axios.put(route('hr.training.update', training.id), formData);
            } else {
                response = await axios.post(route('hr.training.store'), formData);
            }

            showToast.success(response.data.message || `Training ${isEditing ? 'updated' : 'created'} successfully!`);
            onClose();
            fetchData(currentPage, perPage, filterData);
        } catch (error) {
            console.error('Error submitting form:', error);

            if (error.response?.data?.errors) {
                setErrors(error.response.data.errors);
            } else {
                showToast.error(`Failed to ${isEditing ? 'update' : 'create'} training session.`);
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 720 }}>
                <Flex justify="between" align="center" mb="3">
                    <Dialog.Title>
                        {isEditing ? 'Edit Training Session' : 'Create New Training Session'}
                    </Dialog.Title>
                    <Dialog.Close>
                        <IconButton variant="ghost" color="gray" aria-label="close">
                            <XMarkIcon className="w-5 h-5" />
                        </IconButton>
                    </Dialog.Close>
                </Flex>

                <form onSubmit={handleSubmit}>
                    <Grid columns={{ initial: '1', md: '2' }} gap="3">
                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Training Title *</Text>
                            <TextField.Root
                                name="title"
                                placeholder="Enter training title"
                                value={formData.title}
                                onChange={handleChange}
                            />
                            {fieldError(errors, 'title') && <Text size="1" color="red" mt="1">{fieldError(errors, 'title')}</Text>}
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Description *</Text>
                            <TextArea
                                name="description"
                                placeholder="Enter training description"
                                value={formData.description}
                                onChange={handleChange}
                                rows={3}
                            />
                            {fieldError(errors, 'description') && <Text size="1" color="red" mt="1">{fieldError(errors, 'description')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Category *</Text>
                            <Select.Root
                                value={formData.category_id ? String(formData.category_id) : undefined}
                                onValueChange={(v) => handleSelectChange('category_id', v)}
                            >
                                <Select.Trigger placeholder="Select a category" style={{ width: '100%' }} />
                                <Select.Content>
                                    {categories.map(category => (
                                        <Select.Item key={category.id} value={String(category.id)}>
                                            {category.name}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {fieldError(errors, 'category_id') && <Text size="1" color="red" mt="1">{fieldError(errors, 'category_id')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Instructor</Text>
                            <TextField.Root
                                name="instructor"
                                placeholder="Enter instructor name"
                                value={formData.instructor}
                                onChange={handleChange}
                            />
                            {fieldError(errors, 'instructor') && <Text size="1" color="red" mt="1">{fieldError(errors, 'instructor')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Duration *</Text>
                            <TextField.Root
                                name="duration"
                                type="number"
                                placeholder="Enter duration"
                                value={formData.duration}
                                onChange={handleChange}
                            />
                            {fieldError(errors, 'duration') && <Text size="1" color="red" mt="1">{fieldError(errors, 'duration')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Unit</Text>
                            <Select.Root
                                value={formData.duration_unit}
                                onValueChange={(v) => handleSelectChange('duration_unit', v)}
                            >
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="hours">Hours</Select.Item>
                                    <Select.Item value="days">Days</Select.Item>
                                    <Select.Item value="weeks">Weeks</Select.Item>
                                    <Select.Item value="months">Months</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Status *</Text>
                            <Select.Root
                                value={formData.status}
                                onValueChange={(v) => handleSelectChange('status', v)}
                            >
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="planned">Planned</Select.Item>
                                    <Select.Item value="active">Active</Select.Item>
                                    <Select.Item value="completed">Completed</Select.Item>
                                    <Select.Item value="cancelled">Cancelled</Select.Item>
                                </Select.Content>
                            </Select.Root>
                            {fieldError(errors, 'status') && <Text size="1" color="red" mt="1">{fieldError(errors, 'status')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Location</Text>
                            <TextField.Root
                                name="location"
                                placeholder="Enter training location"
                                value={formData.location}
                                onChange={handleChange}
                            />
                            {fieldError(errors, 'location') && <Text size="1" color="red" mt="1">{fieldError(errors, 'location')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Maximum Participants</Text>
                            <TextField.Root
                                name="max_participants"
                                type="number"
                                placeholder="Enter max participants"
                                value={formData.max_participants}
                                onChange={handleChange}
                            />
                            {fieldError(errors, 'max_participants') && <Text size="1" color="red" mt="1">{fieldError(errors, 'max_participants')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Cost</Text>
                            <TextField.Root
                                name="cost"
                                type="number"
                                step="0.01"
                                placeholder="Enter training cost"
                                value={formData.cost}
                                onChange={handleChange}
                            />
                            {fieldError(errors, 'cost') && <Text size="1" color="red" mt="1">{fieldError(errors, 'cost')}</Text>}
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Start Date</Text>
                            <DateTimePicker
                                mode="date"
                                value={formData.start_date}
                                onChange={(val) => handleChange({ target: { name: 'start_date', value: val } })}
                                error={fieldError(errors, 'start_date')}
                            />
                        </Box>

                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">End Date</Text>
                            <DateTimePicker
                                mode="date"
                                value={formData.end_date}
                                onChange={(val) => handleChange({ target: { name: 'end_date', value: val } })}
                                error={fieldError(errors, 'end_date')}
                            />
                        </Box>
                    </Grid>

                    <Flex justify="between" mt="5">
                        <Button type="button" variant="soft" color="gray" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={loading}>
                            {loading && <Spinner size="1" />}
                            {isEditing ? 'Update Training' : 'Create Training'}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default AddEditTrainingForm;
