import React, { useState, useEffect } from 'react';
import {
    Box,
    Text,
    Grid,
    IconButton,
    TextField,
    Select,
    Button,
    Flex,
    Badge,
    Switch,
    Spinner,
    ScrollArea,
    TextArea,
} from '@radix-ui/themes';
import { XMarkIcon, PlusIcon } from '@heroicons/react/24/outline';
import { showToast } from '@/utils/toastUtils';
import GlassDialog from '@/Components/GlassDialog.jsx';
import axios from 'axios';
import dayjs from 'dayjs';
import DateTimePicker from '@/Components/DateTimePicker';

const toastStyle = {
    backdropFilter: 'blur(16px) saturate(200%)',
    background: 'rgba(255, 255, 255, 0.1)',
    border: '1px solid rgba(255, 255, 255, 0.2)',
};

const fieldError = (errors, key) => {
    const e = errors[key];
    if (!e) return null;
    return Array.isArray(e) ? e[0] : e;
};

const RemovableBadges = ({ items, onRemove, color = 'blue' }) => (
    <Flex wrap="wrap" gap="2">
        {items.map((item, index) => (
            <Badge key={index} variant="soft" color={color}>
                {typeof item === 'object' ? `${item.key}: ${item.value}` : item}
                <IconButton
                    size="1"
                    variant="ghost"
                    color="gray"
                    type="button"
                    onClick={() => onRemove(item)}
                    style={{ marginLeft: 4 }}
                >
                    <XMarkIcon className="w-3 h-3" />
                </IconButton>
            </Badge>
        ))}
    </Flex>
);

const AddEditJobForm = ({
    open,
    onClose,
    job = null,
    onSuccess,
    departments: propDepartments = [],
    managers: propManagers = [],
    addJobOptimized,
    updateJobOptimized,
    fetchJobStats
}) => {
    const isEditing = !!job;
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const [formData, setFormData] = useState({
        title: job?.title || '',
        department_id: job?.department_id || '',
        type: job?.type || 'full_time',
        location: job?.location || '',
        is_remote_allowed: job?.is_remote_allowed || false,
        description: job?.description || '',
        responsibilities: job?.responsibilities || [],
        requirements: job?.requirements || [],
        qualifications: job?.qualifications || [],
        salary_min: job?.salary_min || '',
        salary_max: job?.salary_max || '',
        salary_currency: job?.salary_currency || 'USD',
        benefits: job?.benefits || [],
        posting_date: job?.posting_date ? dayjs(job.posting_date).format('YYYY-MM-DD') : dayjs().format('YYYY-MM-DD'),
        closing_date: job?.closing_date ? dayjs(job.closing_date).format('YYYY-MM-DD') : '',
        status: job?.status || 'draft',
        hiring_manager_id: job?.hiring_manager_id || '',
        positions: job?.positions || 1,
        salary_visible: job?.salary_visible || false,
        is_featured: job?.is_featured || false,
        skills_required: job?.skills_required || [],
        custom_fields: job?.custom_fields || []
    });

    const [departments] = useState(propDepartments);
    const [managers, setManagers] = useState(propManagers);
    const [loadingManagers, setLoadingManagers] = useState(false);

    const [newResponsibility, setNewResponsibility] = useState('');
    const [newRequirement, setNewRequirement] = useState('');
    const [newQualification, setNewQualification] = useState('');
    const [newBenefit, setNewBenefit] = useState('');
    const [newSkill, setNewSkill] = useState('');
    const [newCustomField, setNewCustomField] = useState({ key: '', value: '' });

    useEffect(() => {
        if (open && managers && managers.length === 0) {
            setLoadingManagers(true);
            axios.get(route('hr.managers.list'))
                .then(response => {
                    if (response.data && Array.isArray(response.data)) {
                        setManagers(response.data);
                    }
                })
                .catch(error => {
                    console.error('Error fetching managers:', error);
                    showToast.error('Failed to load managers list');
                })
                .finally(() => {
                    setLoadingManagers(false);
                });
        } else if (open && managers && managers.length > 0) {
            setManagers(managers);
            setLoadingManagers(false);
        }
    }, [open, managers]);

    const formatDate = (dateString) => {
        if (!dateString) return '';

        if (typeof dateString === 'string' && dateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
            return dateString;
        }

        if (typeof dateString === 'string' && dateString.includes('T')) {
            return dateString.split('T')[0];
        }

        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) {
                return '';
            }

            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        } catch (error) {
            console.error('Date formatting error:', error);
            return '';
        }
    };

    useEffect(() => {
        if (open && isEditing && job) {
            setFormData({
                title: job.title || '',
                department_id: job.department_id || '',
                type: job.type || 'full_time',
                location: job.location || '',
                is_remote_allowed: job.is_remote_allowed || false,
                description: job.description || '',
                responsibilities: Array.isArray(job.responsibilities) ? job.responsibilities : [],
                requirements: Array.isArray(job.requirements) ? job.requirements : [],
                qualifications: Array.isArray(job.qualifications) ? job.qualifications : [],
                salary_min: job.salary_min || '',
                salary_max: job.salary_max || '',
                salary_currency: job.salary_currency || 'USD',
                benefits: Array.isArray(job.benefits) ? job.benefits : [],
                posting_date: job.posting_date ? formatDate(job.posting_date) : dayjs().format('YYYY-MM-DD'),
                closing_date: job.closing_date ? formatDate(job.closing_date) : '',
                status: job.status || 'draft',
                hiring_manager_id: job.hiring_manager_id || '',
                positions: job.positions || 1,
                salary_visible: job.salary_visible || false,
                is_featured: job.is_featured || false,
                skills_required: Array.isArray(job.skills_required) ? job.skills_required : [],
                custom_fields: Array.isArray(job.custom_fields) ? job.custom_fields : []
            });
        }
        if (open) {
            setErrors({});
        }
    }, [open, isEditing, job]);

    const handleChange = (e) => {
        const { name, value, type, checked } = e.target;
        const fieldValue = type === 'checkbox' ? checked : value;

        setFormData(prev => ({
            ...prev,
            [name]: fieldValue
        }));
    };

    const handleSelectChange = (name, value) => {
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
    };

    const handleAddResponsibility = () => {
        if (!newResponsibility.trim()) return;
        setFormData(prev => ({
            ...prev,
            responsibilities: [...prev.responsibilities, newResponsibility.trim()]
        }));
        setNewResponsibility('');
    };

    const handleAddRequirement = () => {
        if (!newRequirement.trim()) return;
        setFormData(prev => ({
            ...prev,
            requirements: [...prev.requirements, newRequirement.trim()]
        }));
        setNewRequirement('');
    };

    const handleAddQualification = () => {
        if (!newQualification.trim()) return;
        setFormData(prev => ({
            ...prev,
            qualifications: [...prev.qualifications, newQualification.trim()]
        }));
        setNewQualification('');
    };

    const handleAddBenefit = () => {
        if (!newBenefit.trim()) return;
        setFormData(prev => ({
            ...prev,
            benefits: [...prev.benefits, newBenefit.trim()]
        }));
        setNewBenefit('');
    };

    const handleAddSkill = () => {
        if (!newSkill.trim()) return;
        setFormData(prev => ({
            ...prev,
            skills_required: [...prev.skills_required, newSkill.trim()]
        }));
        setNewSkill('');
    };

    const handleAddCustomField = () => {
        if (!newCustomField.key.trim() || !newCustomField.value.trim()) return;
        setFormData(prev => ({
            ...prev,
            custom_fields: [
                ...prev.custom_fields,
                {
                    key: newCustomField.key.trim(),
                    value: newCustomField.value.trim(),
                    id: Date.now()
                }
            ]
        }));
        setNewCustomField({ key: '', value: '' });
    };

    const handleDeleteItem = (item, type) => {
        setFormData(prev => ({
            ...prev,
            [type]: type === 'custom_fields'
                ? prev[type].filter(field => field.id !== item.id)
                : prev[type].filter(i => i !== item)
        }));
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);

        const promise = new Promise(async (resolve, reject) => {
            try {
                const apiRoute = isEditing
                    ? route('hr.recruitment.update.ajax', job.id)
                    : route('hr.recruitment.store.ajax');

                const requestData = {
                    ...formData,
                    _method: isEditing ? 'PUT' : 'POST'
                };

                const response = await axios.post(apiRoute, requestData);

                if (response.status === 200) {
                    if (isEditing && updateJobOptimized && response.data.job) {
                        updateJobOptimized(response.data.job);
                        fetchJobStats && fetchJobStats();
                    } else if (addJobOptimized && response.data.job) {
                        addJobOptimized(response.data.job);
                        fetchJobStats && fetchJobStats();
                    }

                    onClose();
                    resolve([response.data.message || (isEditing ? 'Job updated successfully!' : 'Job created successfully!')]);
                }
            } catch (error) {
                console.error(error);
                setProcessing(false);

                if (error.response) {
                    if (error.response.status === 422) {
                        setErrors(error.response.data.errors || {});
                        reject(error.response.data.message || 'Please correct the validation errors.');
                    } else {
                        reject('An unexpected error occurred. Please try again later.');
                    }
                } else if (error.request) {
                    reject('No response received from the server. Please check your internet connection.');
                } else {
                    reject('An error occurred while setting up the request.');
                }
            } finally {
                setProcessing(false);
            }
        });

        showToast.promise(promise, {
            pending: {
                render() {
                    return (
                        <Flex align="center" gap="2">
                            <Spinner />
                            <span>{isEditing ? 'Updating job...' : 'Creating job...'}</span>
                        </Flex>
                    );
                },
                icon: false,
                style: toastStyle,
            },
            success: {
                render({ data }) {
                    return (
                        <>
                            {data.map((message, index) => (
                                <div key={index}>{message}</div>
                            ))}
                        </>
                    );
                },
                icon: '🟢',
                style: toastStyle,
            },
            error: {
                render({ data }) {
                    return <>{data}</>;
                },
                icon: '🔴',
                style: toastStyle,
            },
        });
    };

    const jobTypeOptions = [
        { value: 'full_time', label: 'Full Time' },
        { value: 'part_time', label: 'Part Time' },
        { value: 'contract', label: 'Contract' },
        { value: 'temporary', label: 'Temporary' },
        { value: 'internship', label: 'Internship' },
        { value: 'remote', label: 'Remote' }
    ];

    const currencyOptions = [
        { value: 'USD', label: 'USD ($)' },
        { value: 'EUR', label: 'EUR (€)' },
        { value: 'GBP', label: 'GBP (£)' },
        { value: 'INR', label: 'INR (₹)' },
        { value: 'JPY', label: 'JPY (¥)' },
        { value: 'CAD', label: 'CAD ($)' },
        { value: 'AUD', label: 'AUD ($)' }
    ];

    const statusOptions = [
        { value: 'draft', label: 'Draft' },
        { value: 'open', label: 'Open' },
        { value: 'closed', label: 'Closed' },
        { value: 'on_hold', label: 'On Hold' },
        { value: 'cancelled', label: 'Cancelled' }
    ];

    const currencySymbol = currencyOptions.find(c => c.value === formData.salary_currency)?.label.split(' ')[1] || '$';

    const addRow = (value, setValue, onAdd, placeholder, color) => (
        <Flex gap="2" mb="2">
            <TextField.Root
                style={{ flex: 1 }}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={placeholder}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onAdd();
                    }
                }}
            />
            <Button type="button" onClick={onAdd} disabled={!value.trim()} color={color}>
                <PlusIcon className="w-4 h-4" />
                Add
            </Button>
        </Flex>
    );

    return (
        <GlassDialog
            isOpen={open}
            onClose={onClose}
            title={isEditing ? 'Edit Job Posting' : 'Create New Job Posting'}
        >
            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column' }}>
                <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 140px)' }}>
                    <Box p="4" pt="2">
                        <Grid columns={{ initial: '1', md: '2' }} gap="4">
                            <SectionTitle>Basic Information</SectionTitle>

                            <Field label="Job Title" error={fieldError(errors, 'title')} span>
                                <TextField.Root
                                    name="title"
                                    value={formData.title}
                                    onChange={handleChange}
                                    placeholder="e.g., Senior Software Engineer"
                                />
                            </Field>

                            <Field label="Department" error={fieldError(errors, 'department_id')}>
                                <Select.Root
                                    value={formData.department_id ? String(formData.department_id) : undefined}
                                    onValueChange={(v) => handleSelectChange('department_id', v)}
                                >
                                    <Select.Trigger placeholder="Select department" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {departments.map((department) => (
                                            <Select.Item key={department.id} value={String(department.id)}>
                                                {department.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Job Type" error={fieldError(errors, 'type')}>
                                <Select.Root
                                    value={formData.type}
                                    onValueChange={(v) => handleSelectChange('type', v)}
                                >
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        {jobTypeOptions.map((option) => (
                                            <Select.Item key={option.value} value={option.value}>
                                                {option.label}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Box>
                                <Flex align="center" gap="2" height="100%">
                                    <Switch
                                        checked={formData.is_remote_allowed}
                                        onCheckedChange={(checked) =>
                                            setFormData(prev => ({ ...prev, is_remote_allowed: checked }))
                                        }
                                    />
                                    <Text size="2">Remote Job</Text>
                                </Flex>
                            </Box>

                            <Field
                                label="Location"
                                error={fieldError(errors, 'location')}
                                hint={formData.is_remote_allowed ? 'Not applicable for remote jobs' : undefined}
                            >
                                <TextField.Root
                                    name="location"
                                    value={formData.location}
                                    onChange={handleChange}
                                    disabled={formData.is_remote_allowed}
                                    placeholder={formData.is_remote_allowed ? 'Not applicable for remote jobs' : 'e.g., New York, NY'}
                                />
                            </Field>

                            <Field label="Hiring Manager" error={fieldError(errors, 'hiring_manager_id')}>
                                <Select.Root
                                    value={formData.hiring_manager_id ? String(formData.hiring_manager_id) : undefined}
                                    onValueChange={(v) => handleSelectChange('hiring_manager_id', v)}
                                    disabled={loadingManagers}
                                >
                                    <Select.Trigger placeholder="Select a Manager" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {managers.map((manager) => (
                                            <Select.Item key={manager.id} value={String(manager.id)}>
                                                {manager.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                {loadingManagers && <Spinner size="1" mt="1" />}
                            </Field>

                            <Field label="Number of Positions" error={fieldError(errors, 'positions')}>
                                <TextField.Root
                                    name="positions"
                                    type="number"
                                    min={1}
                                    value={String(formData.positions)}
                                    onChange={handleChange}
                                />
                            </Field>

                            <Field label="Status" error={fieldError(errors, 'status')}>
                                <Select.Root
                                    value={formData.status}
                                    onValueChange={(v) => handleSelectChange('status', v)}
                                >
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        {statusOptions.map((option) => (
                                            <Select.Item key={option.value} value={option.value}>
                                                {option.label}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Posted Date" error={fieldError(errors, 'posting_date')}>
                                <DateTimePicker
                                    mode="date"
                                    value={formData.posting_date}
                                    onChange={(val) => handleChange({ target: { name: 'posting_date', value: val } })}
                                    error={fieldError(errors, 'posting_date')}
                                />
                            </Field>

                            <Field label="Closing Date" error={fieldError(errors, 'closing_date')}>
                                <DateTimePicker
                                    mode="date"
                                    value={formData.closing_date}
                                    onChange={(val) => handleChange({ target: { name: 'closing_date', value: val } })}
                                    error={fieldError(errors, 'closing_date')}
                                />
                            </Field>

                            <SectionTitle>Job Description</SectionTitle>

                            <Field label="Job Description" error={fieldError(errors, 'description')} span>
                                <TextArea
                                    name="description"
                                    rows={4}
                                    value={formData.description}
                                    onChange={handleChange}
                                    placeholder="Describe the job role, company culture, and what makes this position attractive..."
                                />
                            </Field>

                            <SectionTitle>Key Responsibilities</SectionTitle>
                            <Box style={{ gridColumn: '1 / -1' }}>
                                {addRow(newResponsibility, setNewResponsibility, handleAddResponsibility, 'e.g., Develop and maintain web applications', 'blue')}
                                <RemovableBadges items={formData.responsibilities} onRemove={(item) => handleDeleteItem(item, 'responsibilities')} color="blue" />
                                {fieldError(errors, 'responsibilities') && <Text size="1" color="red" mt="1">{fieldError(errors, 'responsibilities')}</Text>}
                            </Box>

                            <SectionTitle>Requirements</SectionTitle>
                            <Box style={{ gridColumn: '1 / -1' }}>
                                {addRow(newRequirement, setNewRequirement, handleAddRequirement, 'e.g., 3+ years of experience with React', 'purple')}
                                <RemovableBadges items={formData.requirements} onRemove={(item) => handleDeleteItem(item, 'requirements')} color="purple" />
                                {fieldError(errors, 'requirements') && <Text size="1" color="red" mt="1">{fieldError(errors, 'requirements')}</Text>}
                            </Box>

                            <SectionTitle>Preferred Qualifications</SectionTitle>
                            <Box style={{ gridColumn: '1 / -1' }}>
                                {addRow(newQualification, setNewQualification, handleAddQualification, "e.g., Bachelor's degree in Computer Science", 'green')}
                                <RemovableBadges items={formData.qualifications} onRemove={(item) => handleDeleteItem(item, 'qualifications')} color="green" />
                            </Box>

                            <SectionTitle>Required Skills</SectionTitle>
                            <Box style={{ gridColumn: '1 / -1' }}>
                                {addRow(newSkill, setNewSkill, handleAddSkill, 'e.g., JavaScript, React, Node.js', 'cyan')}
                                <RemovableBadges items={formData.skills_required} onRemove={(item) => handleDeleteItem(item, 'skills_required')} color="cyan" />
                            </Box>

                            <SectionTitle>Additional Information</SectionTitle>
                            <Box style={{ gridColumn: '1 / -1' }}>
                                <Flex gap="2" mb="2">
                                    <TextField.Root
                                        style={{ flex: 1 }}
                                        placeholder="Field name"
                                        value={newCustomField.key}
                                        onChange={(e) => setNewCustomField(prev => ({ ...prev, key: e.target.value }))}
                                    />
                                    <TextField.Root
                                        style={{ flex: 1 }}
                                        placeholder="Field value"
                                        value={newCustomField.value}
                                        onChange={(e) => setNewCustomField(prev => ({ ...prev, value: e.target.value }))}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                handleAddCustomField();
                                            }
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        color="orange"
                                        onClick={handleAddCustomField}
                                        disabled={!newCustomField.key.trim() || !newCustomField.value.trim()}
                                    >
                                        <PlusIcon className="w-4 h-4" />
                                        Add
                                    </Button>
                                </Flex>
                                <RemovableBadges items={formData.custom_fields} onRemove={(item) => handleDeleteItem(item, 'custom_fields')} color="orange" />
                            </Box>

                            <SectionTitle>Compensation</SectionTitle>

                            <Field label="Minimum Salary">
                                <TextField.Root
                                    name="salary_min"
                                    type="number"
                                    value={formData.salary_min}
                                    onChange={handleChange}
                                    placeholder="50000"
                                >
                                    <TextField.Slot>{currencySymbol}</TextField.Slot>
                                </TextField.Root>
                            </Field>

                            <Field label="Maximum Salary" error={fieldError(errors, 'salary_max')}>
                                <TextField.Root
                                    name="salary_max"
                                    type="number"
                                    value={formData.salary_max}
                                    onChange={handleChange}
                                    placeholder="80000"
                                >
                                    <TextField.Slot>{currencySymbol}</TextField.Slot>
                                </TextField.Root>
                            </Field>

                            <Field label="Currency">
                                <Select.Root
                                    value={formData.salary_currency}
                                    onValueChange={(v) => handleSelectChange('salary_currency', v)}
                                >
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        {currencyOptions.map((option) => (
                                            <Select.Item key={option.value} value={option.value}>
                                                {option.label}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <SectionTitle>Benefits & Perks</SectionTitle>
                            <Box style={{ gridColumn: '1 / -1' }}>
                                {addRow(newBenefit, setNewBenefit, handleAddBenefit, 'e.g., Health insurance, Flexible working hours', 'amber')}
                                <RemovableBadges items={formData.benefits} onRemove={(item) => handleDeleteItem(item, 'benefits')} color="amber" />
                            </Box>

                            <SectionTitle>Additional Settings</SectionTitle>

                            <Box>
                                <Flex align="center" gap="2">
                                    <Switch
                                        checked={formData.salary_visible}
                                        onCheckedChange={(checked) =>
                                            setFormData(prev => ({ ...prev, salary_visible: checked }))
                                        }
                                    />
                                    <Text size="2">Display Salary Information</Text>
                                </Flex>
                            </Box>

                            <Box>
                                <Flex align="center" gap="2">
                                    <Switch
                                        checked={formData.is_featured}
                                        onCheckedChange={(checked) =>
                                            setFormData(prev => ({ ...prev, is_featured: checked }))
                                        }
                                    />
                                    <Text size="2">Featured Job Listing</Text>
                                </Flex>
                            </Box>
                        </Grid>
                    </Box>
                </ScrollArea>

                <Flex
                    gap="3"
                    justify="center"
                    p="4"
                    style={{
                        borderTop: '1px solid var(--gray-6)',
                        backdropFilter: 'blur(16px) saturate(200%)',
                    }}
                >
                    <Button type="button" variant="soft" color="gray" onClick={onClose} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner size="1" />}
                        {isEditing ? 'Update Job Posting' : 'Create Job Posting'}
                    </Button>
                </Flex>
            </form>
        </GlassDialog>
    );
};

const SectionTitle = ({ children }) => (
    <Box style={{ gridColumn: '1 / -1' }}>
        <Text size="4" weight="bold" color="blue" mb="1">{children}</Text>
    </Box>
);

const Field = ({ label, error, hint, span, children }) => (
    <Box style={span ? { gridColumn: '1 / -1' } : undefined}>
        <Text as="label" size="2" weight="medium" mb="1" display="block">{label}</Text>
        {children}
        {error && <Text size="1" color="red" mt="1">{error}</Text>}
        {hint && !error && <Text size="1" color="gray" mt="1">{hint}</Text>}
    </Box>
);

export default AddEditJobForm;
