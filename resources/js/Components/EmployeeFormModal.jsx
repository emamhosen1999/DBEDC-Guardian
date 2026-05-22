import React, { useState, useEffect } from 'react';
import {
    Text,
    Box,
    Grid,
    TextField,
    Select,
    Switch,
    TextArea,
    Button,
    Flex,
    Badge,
    Spinner,
    Callout,
    ScrollArea,
} from '@radix-ui/themes';
import {
    UserIcon,
    EnvelopeIcon,
    PhoneIcon,
    CalendarIcon,
    CurrencyDollarIcon,
    CheckCircleIcon,
} from '@heroicons/react/24/outline';

import GlassDialog from './GlassDialog';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar';

const fieldError = (errors, key) => errors[key] || null;

const EmployeeFormModal = ({
    open,
    onClose,
    employee = null,
    onSubmit,
    departments = [],
    designations = [],
    attendanceTypes = [],
    loading = false,
    mode = 'create',
}) => {
    const isEdit = mode === 'edit';
    const isView = mode === 'view';
    const isCreate = mode === 'create';

    const [formData, setFormData] = useState({
        name: '',
        email: '',
        phone: '',
        department_id: '',
        designation_id: '',
        attendance_type_id: '',
        hire_date: '',
        salary: '',
        active: true,
        notes: ''
    });

    const [errors, setErrors] = useState({});
    const [touched, setTouched] = useState({});

    useEffect(() => {
        if (employee && (isEdit || isView)) {
            setFormData({
                name: employee.name || '',
                email: employee.email || '',
                phone: employee.phone || '',
                department_id: employee.department_id || '',
                designation_id: employee.designation_id || '',
                attendance_type_id: employee.attendance_type_id || '',
                hire_date: employee.hire_date || '',
                salary: employee.salary || '',
                active: employee.active ?? true,
                notes: employee.notes || ''
            });
        } else if (isCreate) {
            setFormData({
                name: '',
                email: '',
                phone: '',
                department_id: '',
                designation_id: '',
                attendance_type_id: '',
                hire_date: '',
                salary: '',
                active: true,
                notes: ''
            });
        }
        setErrors({});
        setTouched({});
    }, [employee, mode]);

    const validateField = (name, value) => {
        switch (name) {
            case 'name':
                if (!value || value.trim().length < 2) {
                    return 'Name must be at least 2 characters long';
                }
                if (value.length > 255) {
                    return 'Name cannot exceed 255 characters';
                }
                break;

            case 'email': {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!value) {
                    return 'Email is required';
                }
                if (!emailRegex.test(value)) {
                    return 'Please enter a valid email address';
                }
                break;
            }

            case 'phone':
                if (value && !/^[\d\s\-\+\(\)]+$/.test(value)) {
                    return 'Please enter a valid phone number';
                }
                if (value && value.length > 20) {
                    return 'Phone number cannot exceed 20 characters';
                }
                break;

            case 'salary':
                if (value && (isNaN(value) || parseFloat(value) < 0)) {
                    return 'Salary must be a positive number';
                }
                if (value && parseFloat(value) > 999999999) {
                    return 'Salary amount is too large';
                }
                break;

            case 'hire_date':
                if (value && new Date(value) > new Date()) {
                    return 'Hire date cannot be in the future';
                }
                break;

            default:
                break;
        }
        return null;
    };

    const handleFieldChange = (name, value) => {
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));

        setTouched(prev => ({
            ...prev,
            [name]: true
        }));

        const error = validateField(name, value);
        setErrors(prev => ({
            ...prev,
            [name]: error
        }));
    };

    const validateForm = () => {
        const newErrors = {};
        const requiredFields = ['name', 'email'];

        requiredFields.forEach(field => {
            if (!formData[field] || formData[field].trim() === '') {
                newErrors[field] = `${field.charAt(0).toUpperCase() + field.slice(1)} is required`;
            }
        });

        Object.keys(formData).forEach(field => {
            const error = validateField(field, formData[field]);
            if (error) {
                newErrors[field] = error;
            }
        });

        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = () => {
        const allTouched = {};
        Object.keys(formData).forEach(key => {
            allTouched[key] = true;
        });
        setTouched(allTouched);

        if (validateForm()) {
            onSubmit(formData);
        }
    };

    const getDepartmentName = (id) => {
        const dept = departments.find(d => d.id === id);
        return dept?.name || 'Not assigned';
    };

    const getDesignationName = (id) => {
        const desig = designations.find(d => d.id === id);
        return desig?.name || 'Not assigned';
    };

    const getAttendanceTypeName = (id) => {
        const type = attendanceTypes.find(t => t.id === id);
        return type?.name || 'Not assigned';
    };

    const title = isCreate ? 'Add New Employee' : isEdit ? 'Edit Employee' : 'Employee Details';

    return (
        <GlassDialog isOpen={open} onClose={onClose} title={title}>
            <Flex align="center" gap="2" mb="3" px="4">
                <UserIcon className="w-6 h-6" />
                {employee && (
                    <Badge color={employee.active ? 'green' : 'red'} variant="soft" size="1">
                        {employee.active ? 'Active' : 'Inactive'}
                    </Badge>
                )}
            </Flex>

            <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 200px)' }}>
                <Box px="4" pb="4">
                    {employee && (isEdit || isView) && (
                        <Flex justify="center" mb="4">
                            <ProfileAvatar
                                src={employee.profile_image_url || employee.profile_image}
                                name={employee.name}
                                size="lg"
                                className="w-20 h-20"
                            />
                        </Flex>
                    )}

                    <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text size="3" weight="bold" mb="2">Basic Information</Text>
                        </Box>

                        <Field label="Full Name" required error={touched.name && fieldError(errors, 'name')}>
                            <TextField.Root
                                placeholder="Enter employee's full name"
                                value={formData.name}
                                onChange={(e) => handleFieldChange('name', e.target.value)}
                                disabled={isView}
                            >
                                <TextField.Slot><UserIcon className="w-4 h-4" /></TextField.Slot>
                            </TextField.Root>
                        </Field>

                        <Field label="Email Address" required error={touched.email && fieldError(errors, 'email')}>
                            <TextField.Root
                                type="email"
                                placeholder="Enter email address"
                                value={formData.email}
                                onChange={(e) => handleFieldChange('email', e.target.value)}
                                disabled={isView}
                            >
                                <TextField.Slot><EnvelopeIcon className="w-4 h-4" /></TextField.Slot>
                            </TextField.Root>
                        </Field>

                        <Field label="Phone Number" error={touched.phone && fieldError(errors, 'phone')}>
                            <TextField.Root
                                placeholder="Enter phone number"
                                value={formData.phone}
                                onChange={(e) => handleFieldChange('phone', e.target.value)}
                                disabled={isView}
                            >
                                <TextField.Slot><PhoneIcon className="w-4 h-4" /></TextField.Slot>
                            </TextField.Root>
                        </Field>

                        <Field label="Hire Date" error={touched.hire_date && fieldError(errors, 'hire_date')}>
                            <TextField.Root
                                type="date"
                                value={formData.hire_date}
                                onChange={(e) => handleFieldChange('hire_date', e.target.value)}
                                disabled={isView}
                            >
                                <TextField.Slot><CalendarIcon className="w-4 h-4" /></TextField.Slot>
                            </TextField.Root>
                        </Field>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text size="3" weight="bold" mb="2" mt="2">Job Information</Text>
                        </Box>

                        <Field label="Department">
                            {isView ? (
                                <TextField.Root value={getDepartmentName(formData.department_id)} disabled />
                            ) : (
                                <Select.Root
                                    value={formData.department_id ? String(formData.department_id) : undefined}
                                    onValueChange={(v) => handleFieldChange('department_id', v ? parseInt(v, 10) : '')}
                                >
                                    <Select.Trigger placeholder="Select department" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {departments.map((dept) => (
                                            <Select.Item key={dept.id} value={String(dept.id)}>
                                                {dept.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            )}
                        </Field>

                        <Field label="Designation">
                            {isView ? (
                                <TextField.Root value={getDesignationName(formData.designation_id)} disabled />
                            ) : (
                                <Select.Root
                                    value={formData.designation_id ? String(formData.designation_id) : undefined}
                                    onValueChange={(v) => handleFieldChange('designation_id', v ? parseInt(v, 10) : '')}
                                >
                                    <Select.Trigger placeholder="Select designation" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {designations.map((desig) => (
                                            <Select.Item key={desig.id} value={String(desig.id)}>
                                                {desig.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            )}
                        </Field>

                        <Field label="Attendance Type">
                            {isView ? (
                                <TextField.Root value={getAttendanceTypeName(formData.attendance_type_id)} disabled />
                            ) : (
                                <>
                                    <Select.Root
                                        value={formData.attendance_type_id ? String(formData.attendance_type_id) : undefined}
                                        onValueChange={(v) => handleFieldChange('attendance_type_id', v ? parseInt(v, 10) : '')}
                                    >
                                        <Select.Trigger placeholder="Select attendance type" style={{ width: '100%' }} />
                                        <Select.Content>
                                            {attendanceTypes.map((type) => (
                                                <Select.Item key={type.id} value={String(type.id)}>
                                                    {type.name}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                    {(() => {
                                        const selectedType = attendanceTypes.find(
                                            t => String(t.id) === String(formData.attendance_type_id)
                                        );
                                        return selectedType?.slug === 'biometric' ? (
                                            <Text size="1" color="blue" mt="2">
                                                Biometric type selected. After saving, go to <strong>Admin → Biometric Devices</strong> to enroll this employee on a device.
                                            </Text>
                                        ) : null;
                                    })()}
                                </>
                            )}
                        </Field>

                        <Field label="Salary" error={touched.salary && fieldError(errors, 'salary')}>
                            <TextField.Root
                                type="number"
                                placeholder="Enter salary amount"
                                value={formData.salary}
                                onChange={(e) => handleFieldChange('salary', e.target.value)}
                                disabled={isView}
                            >
                                <TextField.Slot><CurrencyDollarIcon className="w-4 h-4" /></TextField.Slot>
                            </TextField.Root>
                        </Field>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Text size="3" weight="bold" mb="2" mt="2">Additional Information</Text>
                        </Box>

                        <Box>
                            <Flex align="center" gap="3">
                                <Flex align="center" gap="2">
                                    <Switch
                                        checked={formData.active}
                                        onCheckedChange={(value) => handleFieldChange('active', value)}
                                        disabled={isView}
                                    />
                                    <Text size="2">Active Employee</Text>
                                </Flex>
                                <Badge color={formData.active ? 'green' : 'red'} variant="soft" size="1">
                                    {formData.active ? 'Active' : 'Inactive'}
                                </Badge>
                            </Flex>
                        </Box>

                        <Box style={{ gridColumn: '1 / -1' }}>
                            <Field label="Notes">
                                <TextArea
                                    placeholder="Additional notes or comments"
                                    value={formData.notes}
                                    onChange={(e) => handleFieldChange('notes', e.target.value)}
                                    disabled={isView}
                                    rows={3}
                                />
                            </Field>
                        </Box>
                    </Grid>

                    {Object.keys(errors).length > 0 && Object.values(errors).some(Boolean) && (
                        <Callout.Root color="red" mt="4">
                            <Callout.Text>
                                <Text weight="medium" size="2" mb="1">Please fix the following errors:</Text>
                                <ul style={{ margin: '8px 0', paddingLeft: '20px' }}>
                                    {Object.values(errors).filter(Boolean).map((error, index) => (
                                        <li key={index}>{error}</li>
                                    ))}
                                </ul>
                            </Callout.Text>
                        </Callout.Root>
                    )}
                </Box>
            </ScrollArea>

            <Flex
                px="4"
                py="3"
                justify="between"
                align="center"
                style={{ borderTop: '1px solid var(--gray-6)' }}
            >
                <Button variant="soft" color="gray" onClick={onClose} disabled={loading}>
                    {isView ? 'Close' : 'Cancel'}
                </Button>

                {!isView && (
                    <Button onClick={handleSubmit} disabled={loading}>
                        {loading ? (
                            <>
                                <Spinner size="1" />
                                Saving...
                            </>
                        ) : (
                            <>
                                <CheckCircleIcon className="w-4 h-4" />
                                {isCreate ? 'Create Employee' : 'Update Employee'}
                            </>
                        )}
                    </Button>
                )}
            </Flex>
        </GlassDialog>
    );
};

const Field = ({ label, required, error, children }) => (
    <Box>
        <Text as="label" size="2" weight="medium" mb="1" display="block">
            {label}{required && <Text color="red"> *</Text>}
        </Text>
        {children}
        {error && <Text size="1" color="red" mt="1">{error}</Text>}
    </Box>
);

export default EmployeeFormModal;
