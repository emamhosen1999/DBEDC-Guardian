import React, { useEffect, useState } from "react";
import {
    Dialog,
    Button,
    TextField,
    Select,
    Flex,
    Box,
    Text,
    Avatar,
    Switch,
    Grid,
    Badge,
    ScrollArea,
    IconButton,
    Spinner
} from '@radix-ui/themes';
import {
    CameraIcon,
    EyeOpenIcon,
    EyeClosedIcon,
    LockClosedIcon,
    PersonIcon
} from '@radix-ui/react-icons';
import { useForm } from 'laravel-precognition-react';
import { showToast } from "@/utils/toastUtils";

const AddEditUserFormRadix = ({ user, allUsers, departments, designations, roles, setUsers, open, closeModal, editMode = false }) => {
    const [showPassword, setShowPassword] = useState(false);
    const [selectedImage, setSelectedImage] = useState(user?.profile_image_url || user?.profile_image || null);
    const [selectedImageFile, setSelectedImageFile] = useState(null);
    const [filteredDesignations, setFilteredDesignations] = useState(designations || []);
    const [filteredReportTo, setFilteredReportTo] = useState(allUsers || []);

    // Initialize Precognition form with proper method and URL
    const form = useForm(
        editMode ? 'put' : 'post',
        editMode && user?.id ? route('users.update', { user: user.id }) : route('users.store'),
        {
            id: user?.id || '',
            name: user?.name || '',
            user_name: user?.user_name || '',
            gender: user?.gender || '',
            birthday: user?.birthday || '',
            date_of_joining: user?.date_of_joining || '',
            address: user?.address || '',
            employee_id: user?.employee_id || '',
            phone: user?.phone || '',
            email: user?.email || '',
            department_id: user?.department_id || '',
            designation_id: user?.designation_id || '',
            report_to: user?.report_to || '',
            password: '',
            password_confirmation: '',
            roles: user?.roles?.map(r => typeof r === 'object' ? r.name : r) || [],
            single_device_login_enabled: user?.single_device_login_enabled || user?.single_device_login || false,
            profile_image: null,
        }
    );

    // Filter designations when department changes
    useEffect(() => {
        if (form.data.department_id) {
            const filtered = designations?.filter(
                designation => String(designation.department_id) === String(form.data.department_id)
            ) || [];
            setFilteredDesignations(filtered);
            
            if (form.data.designation_id) {
                const isValid = filtered.some(
                    d => String(d.id) === String(form.data.designation_id)
                );
                if (!isValid) {
                    handleChange('designation_id', '');
                }
            }
        } else {
            setFilteredDesignations(designations || []);
        }
    }, [form.data.department_id, designations]);

    // Filter report to users when department and designation changes
    useEffect(() => {
        if (form.data.department_id && form.data.designation_id) {
            const selectedDesignation = designations?.find(
                d => String(d.id) === String(form.data.designation_id)
            );
            
            if (!selectedDesignation) {
                setFilteredReportTo([]);
                handleChange('report_to', '');
                return;
            }

            if (selectedDesignation.hierarchy_level === 1) {
                setFilteredReportTo([]);
                handleChange('report_to', '');
                return;
            }

            const filtered = allUsers?.filter(u => {
                const userDeptId = u.department_id || u.department?.id;
                const deptMatch = String(userDeptId) === String(form.data.department_id);
                const userDesignation = designations?.find(
                    d => String(d.id) === String(u.designation_id)
                );
                const isHigherLevel = userDesignation && 
                    userDesignation.hierarchy_level < selectedDesignation.hierarchy_level;
                const notSelf = !editMode || !form.data.id || u.id !== form.data.id;
                return deptMatch && isHigherLevel && notSelf;
            }) || [];
            
            setFilteredReportTo(filtered);
            
            if (form.data.report_to) {
                const isValid = filtered.some(
                    u => String(u.id) === String(form.data.report_to)
                );
                if (!isValid) {
                    handleChange('report_to', '');
                }
            }
        } else if (form.data.department_id) {
            const filtered = allUsers?.filter(u => {
                const userDeptId = u.department_id || u.department?.id;
                const deptMatch = String(userDeptId) === String(form.data.department_id);
                const notSelf = !editMode || !form.data.id || u.id !== form.data.id;
                return deptMatch && notSelf;
            }) || [];
            setFilteredReportTo(filtered);
        } else {
            const allExceptSelf = allUsers?.filter(u => !editMode || !form.data.id || u.id !== form.data.id) || [];
            setFilteredReportTo(allExceptSelf);
        }
    }, [form.data.department_id, form.data.designation_id, allUsers, form.data.id, editMode, designations]);

    useEffect(() => {
        if (user?.profile_image_url || user?.profile_image) {
            setSelectedImage(user.profile_image_url || user.profile_image);
        }
    }, [user]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        if (selectedImageFile) {
            const fileType = selectedImageFile.type;
            if (!['image/jpeg', 'image/jpg', 'image/png'].includes(fileType)) {
                showToast.error('Invalid file type. Only JPEG and PNG are allowed.');
                return;
            }
            form.setData('profile_image', selectedImageFile);
        }

        try {
            await form.submit({
                preserveScroll: true,
                transform: (data) => {
                    return {
                        ...data,
                        single_device_login_enabled: data.single_device_login_enabled ? 1 : 0,
                    };
                },
                onSuccess: (response) => {
                    if (setUsers) {
                        if (editMode) {
                            setUsers(prevUsers => 
                                prevUsers.map(u => 
                                    u.id === response.data.user.id ? response.data.user : u
                                )
                            );
                        } else {
                            setUsers(prevUsers => [...prevUsers, response.data.user]);
                        }
                    }
                    closeModal();
                    showToast.success(`User ${editMode ? 'updated' : 'created'} successfully`);
                },
                onError: (errors) => {
                    const errorMessages = Object.values(errors).flat();
                    showToast.error(errorMessages.join(', '));
                },
            });
        } catch (error) {
            console.error('Form submission error:', error);
            showToast.error('Failed to submit form');
        }
    };

    const handleImageChange = (event) => {
        const file = event.target.files[0];
        if (file) {
            const objectURL = URL.createObjectURL(file);
            setSelectedImage(objectURL);
            setSelectedImageFile(file);
        }
    };

    const handleChange = (key, value) => {
        form.setData(key, value);
        if (form.touched(key)) {
            form.validate(key);
        }
    };

    const handleTogglePasswordVisibility = () => {
        setShowPassword(!showPassword);
    };

    const isFormValid = () => {
        if (editMode) {
            return form.isDirty && !form.processing;
        } else {
            const hasRequiredFields = 
                form.data.name?.trim() && 
                form.data.user_name?.trim() && 
                form.data.email?.trim() && 
                form.data.password?.trim() && 
                form.data.password_confirmation?.trim();
            
            const passwordsMatch = form.data.password === form.data.password_confirmation;
            
            return hasRequiredFields && passwordsMatch && !form.processing;
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={closeModal}>
            <Dialog.Content style={{ maxWidth: '800px', maxHeight: '90vh' }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <PersonIcon width="24" height="24" />
                        <Text>{editMode ? 'Edit User' : 'Add New User'}</Text>
                    </Flex>
                </Dialog.Title>
                <Dialog.Description>
                    {editMode ? 'Update user information' : 'Create a new user account'}
                </Dialog.Description>

                <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 200px)' }}>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        {/* Profile Image Upload */}
                        <Flex justify="center" mb="4">
                            <Box position="relative">
                                <Avatar
                                    size="8"
                                    src={selectedImage}
                                    fallback={form.data.name?.charAt(0) || 'U'}
                                    style={{ cursor: 'pointer' }}
                                />
                                <label
                                    htmlFor="icon-button-file"
                                    style={{
                                        position: 'absolute',
                                        top: '50%',
                                        left: '50%',
                                        transform: 'translate(-50%, -50%)',
                                        cursor: 'pointer'
                                    }}
                                >
                                    <input
                                        accept="image/*"
                                        id="icon-button-file"
                                        type="file"
                                        style={{ display: 'none' }}
                                        onChange={handleImageChange}
                                    />
                                    <CameraIcon width="24" height="24" color="white" />
                                </label>
                            </Box>
                        </Flex>

                        {/* Basic Information */}
                        <Grid columns={{ initial: '1', md: '2' }} gap="4">
                            <Box>
                                <Text as="label" size="1" weight="medium">Full Name *</Text>
                                <TextField.Root
                                    placeholder="Enter full name"
                                    value={form.data.name}
                                    onChange={(e) => handleChange('name', e.target.value)}
                                    onBlur={() => form.validate('name')}
                                    color={form.invalid('name') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.name && (
                                            <Text color="red" size="1">{form.errors.name}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Username *</Text>
                                <TextField.Root
                                    placeholder="Enter username"
                                    value={form.data.user_name}
                                    onChange={(e) => handleChange('user_name', e.target.value)}
                                    onBlur={() => form.validate('user_name')}
                                    color={form.invalid('user_name') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.user_name && (
                                            <Text color="red" size="1">{form.errors.user_name}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Email *</Text>
                                <TextField.Root
                                    type="email"
                                    placeholder="Enter email address"
                                    value={form.data.email}
                                    onChange={(e) => handleChange('email', e.target.value)}
                                    onBlur={() => form.validate('email')}
                                    color={form.invalid('email') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.email && (
                                            <Text color="red" size="1">{form.errors.email}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Phone</Text>
                                <TextField.Root
                                    placeholder="Enter phone number"
                                    value={form.data.phone}
                                    onChange={(e) => handleChange('phone', e.target.value)}
                                    onBlur={() => form.validate('phone')}
                                    color={form.invalid('phone') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.phone && (
                                            <Text color="red" size="1">{form.errors.phone}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Department</Text>
                                <Select.Root
                                    value={form.data.department_id || ''}
                                    onValueChange={(value) => handleChange('department_id', value)}
                                    onOpenChange={() => form.validate('department_id')}
                                >
                                    <Select.Trigger placeholder="Select department" mt="1" />
                                    <Select.Content>
                                        {departments?.map((department) => (
                                            <Select.Item key={department.id} value={String(department.id)}>
                                                {department.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                {form.errors.department_id && (
                                    <Text color="red" size="1" mt="1">{form.errors.department_id}</Text>
                                )}
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Designation</Text>
                                <Select.Root
                                    value={form.data.designation_id || ''}
                                    onValueChange={(value) => handleChange('designation_id', value)}
                                    disabled={!form.data.department_id || filteredDesignations.length === 0}
                                >
                                    <Select.Trigger placeholder="Select designation" mt="1" />
                                    <Select.Content>
                                        {filteredDesignations?.map((designation) => (
                                            <Select.Item key={designation.id} value={String(designation.id)}>
                                                {designation.title || designation.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                {form.errors.designation_id && (
                                    <Text color="red" size="1" mt="1">{form.errors.designation_id}</Text>
                                )}
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Gender</Text>
                                <Select.Root
                                    value={form.data.gender || ''}
                                    onValueChange={(value) => handleChange('gender', value)}
                                >
                                    <Select.Trigger placeholder="Select gender" mt="1" />
                                    <Select.Content>
                                        <Select.Item value="male">Male</Select.Item>
                                        <Select.Item value="female">Female</Select.Item>
                                        <Select.Item value="other">Other</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Employee ID</Text>
                                <TextField.Root
                                    placeholder="Enter employee ID"
                                    value={form.data.employee_id}
                                    onChange={(e) => handleChange('employee_id', e.target.value)}
                                    onBlur={() => form.validate('employee_id')}
                                    color={form.invalid('employee_id') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.employee_id && (
                                            <Text color="red" size="1">{form.errors.employee_id}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Date of Birth</Text>
                                <TextField.Root
                                    type="date"
                                    value={form.data.birthday}
                                    onChange={(e) => handleChange('birthday', e.target.value)}
                                    onBlur={() => form.validate('birthday')}
                                    color={form.invalid('birthday') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.birthday && (
                                            <Text color="red" size="1">{form.errors.birthday}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box>
                                <Text as="label" size="1" weight="medium">Date of Joining</Text>
                                <TextField.Root
                                    type="date"
                                    value={form.data.date_of_joining}
                                    onChange={(e) => handleChange('date_of_joining', e.target.value)}
                                    onBlur={() => form.validate('date_of_joining')}
                                    color={form.invalid('date_of_joining') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.date_of_joining && (
                                            <Text color="red" size="1">{form.errors.date_of_joining}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>

                            <Box gridColumn="1 / -1">
                                <Text as="label" size="1" weight="medium">Reports To</Text>
                                <Select.Root
                                    value={form.data.report_to ? String(form.data.report_to) : ''}
                                    onValueChange={(value) => handleChange('report_to', value)}
                                    disabled={!form.data.department_id || !form.data.designation_id || filteredReportTo.length === 0}
                                >
                                    <Select.Trigger 
                                        placeholder={
                                            !form.data.department_id 
                                                ? "Select department first" 
                                                : !form.data.designation_id
                                                ? "Select designation first"
                                                : filteredReportTo.length === 0
                                                ? "No supervisor needed"
                                                : "Search for a supervisor..."
                                        } 
                                        mt="1" 
                                    />
                                    <Select.Content>
                                        {filteredReportTo?.map((user) => (
                                            <Select.Item key={user.id} value={String(user.id)}>
                                                {user.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                                {form.data.designation_id && filteredReportTo.length === 0 && (
                                    <Text color="gray" size="1" mt="1">
                                        This designation is at the top of the hierarchy and doesn't require a supervisor.
                                    </Text>
                                )}
                            </Box>

                            <Box gridColumn="1 / -1">
                                <Text as="label" size="1" weight="medium">Address</Text>
                                <TextField.Root
                                    placeholder="Enter address"
                                    value={form.data.address}
                                    onChange={(e) => handleChange('address', e.target.value)}
                                    onBlur={() => form.validate('address')}
                                    color={form.invalid('address') ? 'red' : undefined}
                                    mt="1"
                                >
                                    <TextField.Slot side="right">
                                        {form.errors.address && (
                                            <Text color="red" size="1">{form.errors.address}</Text>
                                        )}
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>
                        </Grid>

                        {/* Roles Selection */}
                        <Box>
                            <Text as="label" size="1" weight="medium">Roles</Text>
                            <Select.Root
                                value={form.data.roles || []}
                                onValueChange={(values) => handleChange('roles', values)}
                                multiple
                            >
                                <Select.Trigger placeholder="Select user roles" mt="1" />
                                <Select.Content>
                                    {roles?.map((role) => {
                                        const roleName = typeof role === 'object' ? role.name : role;
                                        return (
                                            <Select.Item key={roleName} value={roleName}>
                                                {roleName}
                                            </Select.Item>
                                        );
                                    })}
                                </Select.Content>
                            </Select.Root>
                            {form.data.roles && form.data.roles.length > 0 && (
                                <Flex gap="1" wrap mt="2">
                                    {form.data.roles.map((role) => (
                                        <Badge key={role} size="1" variant="soft" color="indigo">
                                            {role}
                                        </Badge>
                                    ))}
                                </Flex>
                            )}
                        </Box>

                        {/* Single Device Login Toggle */}
                        <Box p="4" style={{ border: '1px solid var(--gray-6)', borderRadius: 'var(--radius-2)' }}>
                            <Flex align="center" justify="between">
                                <Flex gap="2" align="center">
                                    <LockClosedIcon color="indigo" />
                                    <Box>
                                        <Text size="2" weight="medium">Single Device Login</Text>
                                        <Text size="1" color="gray">Restrict user to login from only one device</Text>
                                    </Box>
                                </Flex>
                                <Switch
                                    checked={form.data.single_device_login_enabled}
                                    onCheckedChange={(checked) => handleChange('single_device_login_enabled', checked)}
                                />
                            </Flex>
                        </Box>

                        {/* Password fields (only for new users) */}
                        {!editMode && (
                            <Grid columns={{ initial: '1', md: '2' }} gap="4">
                                <Box>
                                    <Text as="label" size="1" weight="medium">Password *</Text>
                                    <TextField.Root
                                        type={showPassword ? 'text' : 'password'}
                                        placeholder="Enter password"
                                        value={form.data.password}
                                        onChange={(e) => handleChange('password', e.target.value)}
                                        onBlur={() => form.validate('password')}
                                        color={form.invalid('password') ? 'red' : undefined}
                                        mt="1"
                                    >
                                        <TextField.Slot side="right">
                                            <IconButton 
                                                size="1" 
                                                variant="ghost" 
                                                onClick={handleTogglePasswordVisibility}
                                            >
                                                {showPassword ? <EyeClosedIcon /> : <EyeOpenIcon />}
                                            </IconButton>
                                        </TextField.Slot>
                                    </TextField.Root>
                                    {form.errors.password && (
                                        <Text color="red" size="1" mt="1">{form.errors.password}</Text>
                                    )}
                                </Box>

                                <Box>
                                    <Text as="label" size="1" weight="medium">Confirm Password *</Text>
                                    <TextField.Root
                                        type={showPassword ? 'text' : 'password'}
                                        placeholder="Confirm password"
                                        value={form.data.password_confirmation}
                                        onChange={(e) => handleChange('password_confirmation', e.target.value)}
                                        onBlur={() => form.validate('password_confirmation')}
                                        color={form.invalid('password_confirmation') || (form.data.password !== form.data.password_confirmation && form.data.password_confirmation) ? 'red' : undefined}
                                        mt="1"
                                    >
                                        <TextField.Slot side="right">
                                            <IconButton 
                                                size="1" 
                                                variant="ghost" 
                                                onClick={handleTogglePasswordVisibility}
                                            >
                                                {showPassword ? <EyeClosedIcon /> : <EyeOpenIcon />}
                                            </IconButton>
                                        </TextField.Slot>
                                    </TextField.Root>
                                    {form.errors.password_confirmation && (
                                        <Text color="red" size="1" mt="1">{form.errors.password_confirmation}</Text>
                                    )}
                                    {(form.data.password !== form.data.password_confirmation && form.data.password_confirmation) && (
                                        <Text color="red" size="1" mt="1">Passwords do not match</Text>
                                    )}
                                </Box>
                            </Grid>
                        )}
                    </form>
                </ScrollArea>

                <Flex gap="3" justify="end" mt="4">
                    <Button variant="soft" color="gray" onClick={closeModal} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={handleSubmit} disabled={!isFormValid() || form.processing}>
                        {form.processing && <Spinner size="1" />}
                        {editMode ? 'Update User' : 'Add User'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default AddEditUserFormRadix;
