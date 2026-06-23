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
    Checkbox,
    Spinner,
    Heading,
    Separator,
    Card,
    Tooltip
} from '@radix-ui/themes';
import {
    CameraIcon,
    EyeOpenIcon,
    EyeClosedIcon,
    LockClosedIcon,
    PersonIcon,
    EnvelopeClosedIcon,
    MobileIcon,
    IdCardIcon,
    CalendarIcon,
    HomeIcon,
    DesktopIcon,
    MagnifyingGlassIcon
} from '@radix-ui/react-icons';
import { useForm } from 'laravel-precognition-react';
import { showToast } from "@/utils/toastUtils";

const AddEditUserFormRadix = ({ user, allUsers, departments, designations, roles, workLocations = [], attendanceTypes = [], setUsers, open, closeModal, editMode = false, onSuccess }) => {
    const [showPassword, setShowPassword] = useState(false);
    const [selectedImage, setSelectedImage] = useState(user?.profile_image_url || user?.profile_image || null);
    const [selectedImageFile, setSelectedImageFile] = useState(null);
    const [filteredDesignations, setFilteredDesignations] = useState(designations || []);
    const [filteredReportTo, setFilteredReportTo] = useState(allUsers || []);
    const [hasOverride, setHasOverride] = useState(user?.has_attendance_override || false);

    // Initialize Precognition form with proper method and URL
    const form = useForm(
        editMode ? 'put' : 'post',
        editMode && user?.id ? route('users.update', { id: user.id }) : route('users.store'),
        {
            id: user?.id || '',
            name: user?.name || '',
            user_name: user?.user_name || '',
            gender: user?.gender?.toLowerCase() || '',
            birthday: user?.birthday || '',
            date_of_joining: user?.date_of_joining || '',
            address: user?.address || '',
            employee_id: user?.employee_id ? String(user.employee_id) : '',
            phone: user?.phone || '',
            email: user?.email || '',
            department_id: user?.department?.id || user?.department_id || '',
            designation_id: user?.designation?.id || user?.designation_id || '',
            report_to: user?.report_to || '',
            password: '',
            password_confirmation: '',
            roles: user?.roles?.map(r => typeof r === 'object' ? r.name : r) || [],
            single_device_login_enabled: user?.single_device_login_enabled || user?.single_device_login || false,
            work_location_id: user?.work_location_id || '',
            attendance_type_id: user?.has_attendance_override && user?.attendance_type_id ? String(user.attendance_type_id) : '',
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
        setHasOverride(user?.has_attendance_override || false);
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
                    showToast.success(`User ${editMode ? 'updated' : 'created'} successfully`);
                    if (onSuccess) { onSuccess(response); } else { closeModal(); }
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

    const toggleRole = (roleName, checked) => {
        const currentRoles = Array.isArray(form.data.roles) ? form.data.roles : [];
        const nextRoles = checked
            ? [...new Set([...currentRoles, roleName])]
            : currentRoles.filter(role => role !== roleName);

        handleChange('roles', nextRoles);
    };

    const isFormValid = () => {
        if (editMode) {
            return !form.processing;
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
            <Dialog.Content style={{ maxWidth: '850px', width: '95vw', padding: 0, overflow: 'hidden' }}>
                
                {/* Fixed Header */}
                <Box p="4" style={{ backgroundColor: 'var(--color-panel-solid)', borderBottom: '1px solid var(--gray-6)' }}>
                    <Dialog.Title mb="1">
                        <Flex align="center" gap="2">
                            <PersonIcon width="24" height="24" />
                            <Text size="5" weight="bold">{editMode ? 'Edit User Profile' : 'Add New User'}</Text>
                        </Flex>
                    </Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        {editMode ? 'Update user information and access controls.' : 'Fill in the details to create a new user account.'}
                    </Dialog.Description>
                </Box>

                {/* Scrollable Body */}
                <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 140px)' }}>
                    <Box p="4" pt="5">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            
                            {/* SECTION 1: Profile Image */}
                            <Flex direction="column" align="center" justify="center" mb="2">
                                <Box position="relative">
                                    <Avatar
                                        size="8"
                                        src={selectedImage}
                                        fallback={form.data.name?.charAt(0)?.toUpperCase() || <PersonIcon width="40" height="40" />}
                                        radius="full"
                                        style={{ boxShadow: 'var(--shadow-3)', width: '100px', height: '100px' }}
                                    />
                                    <label htmlFor="icon-button-file" style={{ cursor: 'pointer' }}>
                                        <input
                                            accept="image/*"
                                            id="icon-button-file"
                                            type="file"
                                            style={{ display: 'none' }}
                                            onChange={handleImageChange}
                                        />
                                        <IconButton
                                            as="span"
                                            size="2"
                                            radius="full"
                                            variant="solid"
                                            color="indigo"
                                            style={{
                                                position: 'absolute',
                                                bottom: 0,
                                                right: 0,
                                                boxShadow: 'var(--shadow-4)',
                                                cursor: 'pointer'
                                            }}
                                        >
                                            <CameraIcon width="16" height="16" />
                                        </IconButton>
                                    </label>
                                </Box>
                                <Text size="1" color="gray" mt="2">Allowed: JPEG, PNG</Text>
                            </Flex>

                            {/* SECTION 2: Personal Information */}
                            <Box>
                                <Heading size="3" mb="3" color="indigo">Personal Details</Heading>
                                <Card variant="surface">
                                    <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Full Name <Text color="red">*</Text></Text>
                                            <TextField.Root placeholder="Enter full name" value={form.data.name} onChange={(e) => handleChange('name', e.target.value)} onBlur={() => form.validate('name')} color={form.invalid('name') ? 'red' : undefined}>
                                                <TextField.Slot><PersonIcon /></TextField.Slot>
                                                {form.errors.name && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.name}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Username <Text color="red">*</Text></Text>
                                            <TextField.Root placeholder="Enter username" value={form.data.user_name} onChange={(e) => handleChange('user_name', e.target.value)} onBlur={() => form.validate('user_name')} color={form.invalid('user_name') ? 'red' : undefined}>
                                                <TextField.Slot><IdCardIcon /></TextField.Slot>
                                                {form.errors.user_name && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.user_name}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Email <Text color="red">*</Text></Text>
                                            <TextField.Root type="email" placeholder="user@example.com" value={form.data.email} onChange={(e) => handleChange('email', e.target.value)} onBlur={() => form.validate('email')} color={form.invalid('email') ? 'red' : undefined}>
                                                <TextField.Slot><EnvelopeClosedIcon /></TextField.Slot>
                                                {form.errors.email && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.email}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Phone</Text>
                                            <TextField.Root type="tel" placeholder="+1 (555) 000-0000" value={form.data.phone} onChange={(e) => handleChange('phone', e.target.value)} onBlur={() => form.validate('phone')} color={form.invalid('phone') ? 'red' : undefined}>
                                                <TextField.Slot><MobileIcon /></TextField.Slot>
                                                {form.errors.phone && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.phone}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Gender</Text>
                                            <Select.Root value={form.data.gender || undefined} onValueChange={(value) => handleChange('gender', value)}>
                                                <Select.Trigger placeholder="Select gender" style={{ width: '100%' }} />
                                                <Select.Content>
                                                    <Select.Item value="male">Male</Select.Item>
                                                    <Select.Item value="female">Female</Select.Item>
                                                    <Select.Item value="other">Other</Select.Item>
                                                </Select.Content>
                                            </Select.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Date of Birth</Text>
                                            <TextField.Root type="date" value={form.data.birthday} onChange={(e) => handleChange('birthday', e.target.value)} onBlur={() => form.validate('birthday')} color={form.invalid('birthday') ? 'red' : undefined}>
                                                <TextField.Slot><CalendarIcon /></TextField.Slot>
                                                {form.errors.birthday && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.birthday}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box gridColumn={{ initial: '1', sm: '1 / -1' }}>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Address</Text>
                                            <TextField.Root placeholder="Enter full address" value={form.data.address} onChange={(e) => handleChange('address', e.target.value)} onBlur={() => form.validate('address')} color={form.invalid('address') ? 'red' : undefined}>
                                                <TextField.Slot><HomeIcon /></TextField.Slot>
                                                {form.errors.address && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.address}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>
                                    </Grid>
                                </Card>
                            </Box>

                            <Separator size="4" />

                            {/* SECTION 3: Organization Info */}
                            <Box>
                                <Heading size="3" mb="3" color="indigo">Organization details</Heading>
                                <Card variant="surface">
                                    <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Employee ID</Text>
                                            <TextField.Root placeholder="e.g. EMP-1023" value={form.data.employee_id} onChange={(e) => handleChange('employee_id', e.target.value)} onBlur={() => form.validate('employee_id')} color={form.invalid('employee_id') ? 'red' : undefined}>
                                                <TextField.Slot><BadgeIcon /></TextField.Slot>
                                                {form.errors.employee_id && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.employee_id}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Date of Joining</Text>
                                            <TextField.Root type="date" value={form.data.date_of_joining} onChange={(e) => handleChange('date_of_joining', e.target.value)} onBlur={() => form.validate('date_of_joining')} color={form.invalid('date_of_joining') ? 'red' : undefined}>
                                                <TextField.Slot><CalendarIcon /></TextField.Slot>
                                                {form.errors.date_of_joining && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.date_of_joining}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Department</Text>
                                            <Select.Root value={form.data.department_id ? String(form.data.department_id) : undefined} onValueChange={(value) => handleChange('department_id', value)}>
                                                <Select.Trigger placeholder="Select department" style={{ width: '100%' }} />
                                                <Select.Content>
                                                    {departments?.map((department) => (
                                                        <Select.Item key={department.id} value={String(department.id)}>
                                                            {department.name}
                                                        </Select.Item>
                                                    ))}
                                                </Select.Content>
                                            </Select.Root>
                                            {form.errors.department_id && <Text color="red" size="1" mt="1" display="block">{form.errors.department_id}</Text>}
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Designation</Text>
                                            <Select.Root value={form.data.designation_id ? String(form.data.designation_id) : undefined} onValueChange={(value) => handleChange('designation_id', value)} disabled={!form.data.department_id || filteredDesignations.length === 0}>
                                                <Select.Trigger placeholder="Select designation" style={{ width: '100%' }} />
                                                <Select.Content>
                                                    {filteredDesignations?.map((designation) => (
                                                        <Select.Item key={designation.id} value={String(designation.id)}>
                                                            {designation.title || designation.name}
                                                        </Select.Item>
                                                    ))}
                                                </Select.Content>
                                            </Select.Root>
                                            {form.errors.designation_id && <Text color="red" size="1" mt="1" display="block">{form.errors.designation_id}</Text>}
                                        </Box>

                                        <Box gridColumn={{ initial: '1', sm: '1 / -1' }}>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Reports To</Text>
                                            <Select.Root value={form.data.report_to ? String(form.data.report_to) : undefined} onValueChange={(value) => handleChange('report_to', value)} disabled={!form.data.department_id || !form.data.designation_id || filteredReportTo.length === 0}>
                                                <Select.Trigger 
                                                    style={{ width: '100%' }}
                                                    placeholder={
                                                        !form.data.department_id 
                                                            ? "Select department first" 
                                                            : !form.data.designation_id
                                                            ? "Select designation first"
                                                            : filteredReportTo.length === 0
                                                            ? "No supervisor needed for this level"
                                                            : "Search for a supervisor..."
                                                    } 
                                                />
                                                <Select.Content>
                                                    {filteredReportTo?.map((user) => (
                                                        <Select.Item key={user.id} value={String(user.id)}>
                                                            {user.name}
                                                        </Select.Item>
                                                    ))}
                                                </Select.Content>
                                            </Select.Root>
                                        </Box>

                                        {/* Work Location Selection */}
                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Work Location</Text>
                                            <Select.Root value={form.data.work_location_id ? String(form.data.work_location_id) : 'none'} onValueChange={(value) => handleChange('work_location_id', value === 'none' ? '' : value)}>
                                                <Select.Trigger placeholder="Select work location" style={{ width: '100%' }} />
                                                <Select.Content>
                                                    <Select.Item value="none">Unassigned / Remote</Select.Item>
                                                    {workLocations?.map((loc) => (
                                                        <Select.Item key={loc.id} value={String(loc.id)}>
                                                            {loc.name}
                                                        </Select.Item>
                                                    ))}
                                                </Select.Content>
                                            </Select.Root>
                                            {form.errors.work_location_id && <Text color="red" size="1" mt="1" display="block">{form.errors.work_location_id}</Text>}
                                        </Box>

                                        {/* Attendance Rule Override */}
                                        <Box>
                                            <Flex align="center" justify="between" mb="2" mt="1">
                                                <Text as="label" size="2" weight="medium">Custom Attendance Override</Text>
                                                <Switch 
                                                    checked={hasOverride} 
                                                    onCheckedChange={(checked) => {
                                                        setHasOverride(checked);
                                                        if (!checked) {
                                                            handleChange('attendance_type_id', '');
                                                        } else {
                                                            handleChange('attendance_type_id', attendanceTypes?.[0]?.id ? String(attendanceTypes[0].id) : '');
                                                        }
                                                    }} 
                                                />
                                            </Flex>
                                            
                                            {hasOverride ? (
                                                <Select.Root value={form.data.attendance_type_id ? String(form.data.attendance_type_id) : undefined} onValueChange={(value) => handleChange('attendance_type_id', value)}>
                                                    <Select.Trigger placeholder="Select custom rule" style={{ width: '100%' }} />
                                                    <Select.Content>
                                                        {attendanceTypes?.map((type) => (
                                                            <Select.Item key={type.id} value={String(type.id)}>
                                                                {type.name}
                                                            </Select.Item>
                                                        ))}
                                                    </Select.Content>
                                                </Select.Root>
                                            ) : (
                                                <Box p="2" style={{ backgroundColor: 'var(--gray-2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-4)' }}>
                                                    <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>
                                                        {form.data.work_location_id ? (
                                                            `Inherits from ${workLocations?.find(w => String(w.id) === String(form.data.work_location_id))?.name || 'location'}: ${
                                                                workLocations?.find(w => String(w.id) === String(form.data.work_location_id))?.attendance_type?.name || 'Default'
                                                            }`
                                                        ) : (
                                                            'Unassigned. Uses default check-in verification.'
                                                        )}
                                                    </Text>
                                                </Box>
                                            )}
                                            {form.errors.attendance_type_id && <Text color="red" size="1" mt="1" display="block">{form.errors.attendance_type_id}</Text>}
                                        </Box>
                                    </Grid>
                                </Card>
                            </Box>

                            <Separator size="4" />

                            {/* SECTION 4: Access & Roles */}
                            <Box>
                                <Heading size="3" mb="3" color="indigo">Access & Security</Heading>
                                <Card variant="surface">
                                    <Flex direction="column" gap="5">
                                        
                                        {/* Roles Grid */}
                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="2" display="block">Assigned Roles</Text>
                                            <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="3" p="3" style={{ border: '1px dashed var(--gray-6)', borderRadius: 'var(--radius-3)' }}>
                                                {roles?.map((role) => {
                                                    const roleName = typeof role === 'object' ? role.name : role;
                                                    const checked = Array.isArray(form.data.roles) && form.data.roles.includes(roleName);
                                                    return (
                                                        <Text as="label" size="2" key={roleName} style={{ cursor: 'pointer' }}>
                                                            <Flex gap="2" align="center">
                                                                <Checkbox checked={checked} onCheckedChange={(value) => toggleRole(roleName, value === true)} />
                                                                {roleName}
                                                            </Flex>
                                                        </Text>
                                                    );
                                                })}
                                            </Grid>
                                            {form.data.roles && form.data.roles.length > 0 && (
                                                <Flex gap="2" wrap="wrap" mt="3">
                                                    {form.data.roles.map((role) => (
                                                        <Badge key={role} size="1" variant="soft" color="indigo">{role}</Badge>
                                                    ))}
                                                </Flex>
                                            )}
                                        </Box>

                                        {/* Single Device Feature */}
                                        <Box p="3" style={{ backgroundColor: 'var(--gray-2)', borderRadius: 'var(--radius-3)' }}>
                                            <Flex align="center" justify="between">
                                                <Flex gap="3" align="center">
                                                    <Box style={{ padding: '8px', backgroundColor: 'var(--indigo-3)', borderRadius: 'var(--radius-2)' }}>
                                                        <DesktopIcon color="var(--indigo-9)" />
                                                    </Box>
                                                    <Box>
                                                        <Text size="2" weight="bold" display="block">Single Device Login</Text>
                                                        <Text size="1" color="gray">Restrict user session to one device at a time</Text>
                                                    </Box>
                                                </Flex>
                                                <Switch checked={form.data.single_device_login_enabled} onCheckedChange={(checked) => handleChange('single_device_login_enabled', checked)} size="2" />
                                            </Flex>
                                        </Box>

                                        {/* Passwords (Only on Create) */}
                                        {!editMode && (
                                            <Box pt="2">
                                                <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                                    <Box>
                                                        <Text as="label" size="2" weight="medium" mb="1" display="block">Password <Text color="red">*</Text></Text>
                                                        <TextField.Root type={showPassword ? 'text' : 'password'} placeholder="Create password" value={form.data.password} onChange={(e) => handleChange('password', e.target.value)} onBlur={() => form.validate('password')} color={form.invalid('password') ? 'red' : undefined}>
                                                            <TextField.Slot><LockClosedIcon /></TextField.Slot>
                                                            <TextField.Slot side="right">
                                                                <IconButton size="1" variant="ghost" onClick={() => setShowPassword(!showPassword)} type="button">
                                                                    {showPassword ? <EyeClosedIcon /> : <EyeOpenIcon />}
                                                                </IconButton>
                                                            </TextField.Slot>
                                                        </TextField.Root>
                                                        {form.errors.password && <Text color="red" size="1" mt="1" display="block">{form.errors.password}</Text>}
                                                    </Box>

                                                    <Box>
                                                        <Text as="label" size="2" weight="medium" mb="1" display="block">Confirm Password <Text color="red">*</Text></Text>
                                                        <TextField.Root type={showPassword ? 'text' : 'password'} placeholder="Confirm password" value={form.data.password_confirmation} onChange={(e) => handleChange('password_confirmation', e.target.value)} onBlur={() => form.validate('password_confirmation')} color={form.invalid('password_confirmation') || (form.data.password !== form.data.password_confirmation && form.data.password_confirmation) ? 'red' : undefined}>
                                                            <TextField.Slot><LockClosedIcon /></TextField.Slot>
                                                            <TextField.Slot side="right">
                                                                <IconButton size="1" variant="ghost" onClick={() => setShowPassword(!showPassword)} type="button">
                                                                    {showPassword ? <EyeClosedIcon /> : <EyeOpenIcon />}
                                                                </IconButton>
                                                            </TextField.Slot>
                                                        </TextField.Root>
                                                        {form.errors.password_confirmation && <Text color="red" size="1" mt="1" display="block">{form.errors.password_confirmation}</Text>}
                                                        {(form.data.password !== form.data.password_confirmation && form.data.password_confirmation) && (
                                                            <Text color="red" size="1" mt="1" display="block">Passwords do not match</Text>
                                                        )}
                                                    </Box>
                                                </Grid>
                                            </Box>
                                        )}
                                    </Flex>
                                </Card>
                            </Box>
                        </form>
                    </Box>
                </ScrollArea>

                {/* Fixed Footer */}
                <Box p="4" style={{ backgroundColor: 'var(--color-panel-solid)', borderTop: '1px solid var(--gray-6)' }}>
                    <Flex gap="3" justify="end">
                        <Button variant="soft" color="gray" size="2" onClick={closeModal} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button size="2" color="indigo" onClick={handleSubmit} disabled={!isFormValid() || form.processing}>
                            {form.processing && <Spinner size="1" />}
                            {editMode ? 'Save Changes' : 'Create User'}
                        </Button>
                    </Flex>
                </Box>
            </Dialog.Content>
        </Dialog.Root>
    );
};

// Fallback component for icons not explicitly exported by radix-icons
const BadgeIcon = (props) => (
    <svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg" {...props}>
        <path d="M4.5 1C4.22386 1 4 1.22386 4 1.5V13.5C4 13.7761 4.22386 14 4.5 14H10.5C10.7761 14 11 13.7761 11 13.5V1.5C11 1.22386 10.7761 1 10.5 1H4.5ZM5 2H10V13H5V2Z" fill="currentColor" fillRule="evenodd" clipRule="evenodd" />
    </svg>
);

export default AddEditUserFormRadix;