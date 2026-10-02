import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useMemo, useRef, useState } from "react";
import { Dialog, Button, TextField, Select, Flex, Box, Text, Avatar, Switch, Grid, Badge, ScrollArea, IconButton, Checkbox, Spinner, Heading, Separator, Tooltip } from '@radix-ui/themes';
import DateTimePicker from '@/Components/DateTimePicker';
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
    MagnifyingGlassIcon,
    PlusIcon
} from '@radix-ui/react-icons';
import { useForm } from 'laravel-precognition-react';
import LockedFieldHint from '@/Components/Common/LockedFieldHint';
import { usePage, router } from '@inertiajs/react';
import DepartmentScopeSection from '@/Components/Access/DepartmentScopeSection';
import DepartmentField from '@/Components/Access/DepartmentField';
import DesignationForm from '@/Pages/Organization/Components/DesignationForm.jsx';
import { deviceOptionsForLocation } from '@/utils/deviceOptions';
import { eligibleManagers } from '@/utils/reportingLine';
import { showToast } from "@/utils/toastUtils";

const AddEditUserFormRadix = ({ user, allUsers, departments, designations, roles, workLocations = [], attendanceTypes = [], biometricDevices = [], setUsers, open, closeModal, editMode = false, onSuccess, scope = 'full' }) => {
    // scope: 'full' (create — everything), 'profile' (identity/personal/org), 'access' (roles & security)
    const showProfile = scope !== 'access';
    const showAccess = scope !== 'profile';
    const { auth } = usePage().props;

    /* ── what the actor may change ──
       The server enforces every group independently (a group he may not change is refused when it
       would change something); these flags only keep the form from offering controls that would be
       refused. In the directory the row carries its own `can` (scope, outranking, never oneself). */
    const holds = (permission) => Boolean(auth?.isSuperAdmin || auth?.permissions?.includes(permission));
    const rowCan = editMode ? (user?.can ?? null) : null;
    const isSelfEdit = editMode && !auth?.isSuperAdmin && String(user?.id ?? '') === String(auth?.user?.employee_id ?? '');
    const canPlace = !isSelfEdit && (rowCan ? rowCan.placement : holds('employees.placement.update'));
    const canConfigureAttendance = !isSelfEdit && (rowCan ? rowCan.attendance_config : holds('employees.attendance-config.update'));
    const canTransfer = !isSelfEdit && (rowCan ? rowCan.transfer : holds('employees.update'));
    const canLockDevices = rowCan ? rowCan.manage_devices : holds('employees.devices.manage');
    // Roles: only someone who manages access sees (or sends) them. Everyone else creates plain
    // Employees — the server forces the base role regardless of what is sent.
    const canManageAccess = holds('employees.access.manage') && !isSelfEdit && (!rowCan || rowCan.manage_access);
    // HR-created staff default to the base role plus the functional roles of the SELECTED department
    // (departments.default_roles, e.g. Quality Control -> Daily Works Contributor).
    const roleNameOf = (r) => (typeof r === 'object' ? r.name : r);
    const roleExists = (name) => (roles || []).some((r) => roleNameOf(r) === name);
    const departmentDefaultRoles = (departmentId) => {
        const dept = (departments || []).find((d) => String(d.id) === String(departmentId));
        return Array.isArray(dept?.default_roles) ? dept.default_roles.filter(roleExists) : [];
    };
    const managedRoleNames = [...new Set((departments || []).flatMap((d) => (Array.isArray(d.default_roles) ? d.default_roles : [])))];
    const defaultRoles = ['Employee', ...departmentDefaultRoles(user?.department_id)].filter(roleExists);
    // Read-only because of who is looking (self, rank or scope): say so instead of silently disabling.
    const lockedRow = { can: { is_self: isSelfEdit } };
    const lockHint = (locked, field) => (editMode && locked ? <LockedFieldHint row={lockedRow} field={field} /> : null);
    const canManageScopes = editMode && !!user?.id && (auth?.permissions?.includes('department.scopes.manage') || false);
    const [showPassword, setShowPassword] = useState(false);
    const [selectedImage, setSelectedImage] = useState(user?.profile_image_url || user?.profile_image || null);
    const [selectedImageFile, setSelectedImageFile] = useState(null);
    // Designations the actor creates from this form ("+ New designation") join the pool at once; the
    // page props follow via a partial reload.
    const [createdDesignations, setCreatedDesignations] = useState([]);
    const [designationDialogOpen, setDesignationDialogOpen] = useState(false);
    const designationPool = useMemo(() => {
        const seen = new Set();
        return [...(designations || []), ...createdDesignations].filter((d) => !seen.has(String(d.id)) && seen.add(String(d.id)));
    }, [designations, createdDesignations]);
    const [filteredReportTo, setFilteredReportTo] = useState(allUsers || []);
    const [hasOverride, setHasOverride] = useState(user?.has_attendance_override || (user?.attendance_types?.length > 0) || false);

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
            roles: user?.roles?.map(r => typeof r === 'object' ? r.name : r) || (holds('employees.access.manage') && !user ? defaultRoles : []),
            single_device_login_enabled: user?.single_device_login_enabled || user?.single_device_login || false,
            work_location_id: user?.work_location_id || '',
            attendance_type_ids: (user?.attendance_types?.map(t => Number(t.id))
                || (user?.has_attendance_override && user?.attendance_type_id ? [Number(user.attendance_type_id)] : [])),
            biometric_device_ids: (user?.override_biometric_devices?.map(d => Number(d.id)) || []),
            profile_image: null,
        }
    );

    // The department's ACTIVE designations (the one the employee already holds stays listed even when it
    // has since been deactivated). Derived, not stored: a designation created from this form is selectable
    // in the very same render.
    const heldDesignationId = String(user?.designation_id ?? user?.designation?.id ?? '');
    const filteredDesignations = useMemo(() => (form.data.department_id
        ? designationPool.filter(
            (designation) => String(designation.department_id) === String(form.data.department_id)
                && (designation.is_active !== false || String(designation.id) === heldDesignationId),
        )
        : designationPool), [form.data.department_id, designationPool, heldDesignationId]);

    // Create mode: the pre-ticked roles follow the selected department (managed defaults swap, hand-picked roles stay).
    useEffect(() => {
        if (editMode || !holds('employees.access.manage')) return;
        const current = Array.isArray(form.data.roles) ? form.data.roles : [];
        const kept = current.filter((name) => !managedRoleNames.includes(name));
        const next = [...new Set([...kept, ...(kept.includes('Employee') || !roleExists('Employee') ? [] : ['Employee']), ...departmentDefaultRoles(form.data.department_id)])];
        if (next.length !== current.length || next.some((n) => !current.includes(n))) handleChange('roles', next);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.department_id]);

    // A department change invalidates a designation of the previous one.
    useEffect(() => {
        if (form.data.department_id && form.data.designation_id
            && !filteredDesignations.some((d) => String(d.id) === String(form.data.designation_id))) {
            handleChange('designation_id', '');
        }
    }, [form.data.department_id, filteredDesignations]);

    // Reports-to: in-scope people of the chosen department, the person administering included
    // (a department admin is the natural supervisor of staff he registers — even in a department
    // that has no designations yet). Rank is only compared where both sides have a designation;
    // a top-level designation needs no supervisor at all.
    useEffect(() => {
        const selectedDesignation = form.data.designation_id
            ? designationPool.find((d) => String(d.id) === String(form.data.designation_id))
            : null;

        if (!form.data.department_id || selectedDesignation?.hierarchy_level === 1) {
            setFilteredReportTo([]);
            if (selectedDesignation?.hierarchy_level === 1 && form.data.report_to) {
                handleChange('report_to', '');
            }
            return;
        }

        const filtered = eligibleManagers({
            managers: allUsers || [],
            subject: { id: editMode ? form.data.id : null, department_id: form.data.department_id },
            subjectLevel: selectedDesignation?.hierarchy_level ?? null,
            actorId: auth?.user?.employee_id ?? null,
            currentManagerId: editMode ? user?.report_to : null,
        });
        setFilteredReportTo(filtered);

        if (form.data.report_to && !filtered.some((u) => String(u.id) === String(form.data.report_to))) {
            handleChange('report_to', '');
        }
    }, [form.data.department_id, form.data.designation_id, allUsers, form.data.id, editMode, designationPool]);

    useEffect(() => {
        if (user?.profile_image_url || user?.profile_image) {
            setSelectedImage(user.profile_image_url || user.profile_image);
        }
        setHasOverride(user?.has_attendance_override || (user?.attendance_types?.length > 0) || false);
    }, [user]);

    // Once the form has been saved, a late blur (the dialog closing) must not fire another precognitive
    // validation: it would run against the record just created and answer 422 "already registered".
    const settled = useRef(false);
    const validateField = (key) => {
        if (!settled.current) form.validate(key);
    };

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
                    const { roles: submittedRoles, ...rest } = data;
                    return {
                        ...rest,
                        // Roles travel only from someone who manages access; the server enforces the same.
                        ...(canManageAccess ? { roles: submittedRoles } : {}),
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
                    settled.current = true;
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
            validateField(key);
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
                            <Text size="5" weight="bold">{!editMode ? 'Add New User' : (scope === 'access' ? 'Manage Access' : scope === 'profile' ? 'Edit Profile' : 'Edit User Profile')}</Text>
                        </Flex>
                    </Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        {!editMode
                            ? 'Fill in the details to create a new user account.'
                            : (scope === 'access'
                                ? 'Manage roles, permissions and device security.'
                                : scope === 'profile'
                                    ? 'Update identity, personal and organization details.'
                                    : 'Update user information and access controls.')}
                    </Dialog.Description>
                </Box>

                {/* Scrollable Body */}
                <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 140px)' }}>
                    <Box p="4" pt="5">
                        <form onSubmit={handleSubmit} className="space-y-6">

                            {showProfile && (<>
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
                                <Panel variant="surface">
                                    <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Full Name <Text color="red">*</Text></Text>
                                            <TextField.Root placeholder="Enter full name" value={form.data.name} onChange={(e) => handleChange('name', e.target.value)} onBlur={() => validateField('name')} color={form.invalid('name') ? 'red' : undefined}>
                                                <TextField.Slot><PersonIcon /></TextField.Slot>
                                                {form.errors.name && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.name}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Username <Text color="red">*</Text></Text>
                                            <TextField.Root placeholder="Enter username" value={form.data.user_name} onChange={(e) => handleChange('user_name', e.target.value)} onBlur={() => validateField('user_name')} color={form.invalid('user_name') ? 'red' : undefined}>
                                                <TextField.Slot><IdCardIcon /></TextField.Slot>
                                                {form.errors.user_name && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.user_name}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Email <Text color="red">*</Text></Text>
                                            <TextField.Root type="email" placeholder="user@example.com" value={form.data.email} onChange={(e) => handleChange('email', e.target.value)} onBlur={() => validateField('email')} color={form.invalid('email') ? 'red' : undefined}>
                                                <TextField.Slot><EnvelopeClosedIcon /></TextField.Slot>
                                                {form.errors.email && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.email}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Phone</Text>
                                            <TextField.Root type="tel" placeholder="+1 (555) 000-0000" value={form.data.phone} onChange={(e) => handleChange('phone', e.target.value)} onBlur={() => validateField('phone')} color={form.invalid('phone') ? 'red' : undefined}>
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
                                            <DateTimePicker
                                                mode="date"
                                                value={form.data.birthday}
                                                onChange={(val) => {
                                                    handleChange('birthday', val);
                                                    validateField('birthday');
                                                }}
                                                error={form.errors.birthday}
                                            />
                                        </Box>

                                        <Box gridColumn={{ initial: '1', sm: '1 / -1' }}>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Address</Text>
                                            <TextField.Root placeholder="Enter full address" value={form.data.address} onChange={(e) => handleChange('address', e.target.value)} onBlur={() => validateField('address')} color={form.invalid('address') ? 'red' : undefined}>
                                                <TextField.Slot><HomeIcon /></TextField.Slot>
                                                {form.errors.address && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.address}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>
                                    </Grid>
                                </Panel>
                            </Box>

                            <Separator size="4" />

                            {/* SECTION 3: Organization Info */}
                            <Box>
                                <Heading size="3" mb="3" color="indigo">Organization details</Heading>
                                <Panel variant="surface">
                                    <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Employee ID</Text>
                                            <TextField.Root placeholder="e.g. EMP-1023" value={form.data.employee_id} onChange={(e) => handleChange('employee_id', e.target.value)} onBlur={() => validateField('employee_id')} color={form.invalid('employee_id') ? 'red' : undefined}>
                                                <TextField.Slot><BadgeIcon /></TextField.Slot>
                                                {form.errors.employee_id && <TextField.Slot side="right"><Text color="red" size="1">{form.errors.employee_id}</Text></TextField.Slot>}
                                            </TextField.Root>
                                        </Box>

                                        <Box>
                                            <Text as="label" size="2" weight="medium" mb="1" display="block">Date of Joining</Text>
                                            <DateTimePicker
                                                mode="date"
                                                value={form.data.date_of_joining}
                                                onChange={(val) => {
                                                    handleChange('date_of_joining', val);
                                                    validateField('date_of_joining');
                                                }}
                                                error={form.errors.date_of_joining}
                                            />
                                        </Box>

                                        <DepartmentField
                                            value={form.data.department_id}
                                            onChange={(value) => handleChange('department_id', value)}
                                            departments={departments}
                                            disabled={editMode && !canTransfer}
                                            error={form.errors.department_id}
                                        />

                                        <Box>
                                            <Flex align="center" justify="between" mb="1">
                                                <Flex align="center" gap="1"><Text as="label" size="2" weight="medium">Designation</Text>{lockHint(!canPlace, 'designation')}</Flex>
                                                {holds('designations.create') && form.data.department_id && canPlace && (
                                                    <Button type="button" size="1" variant="ghost" onClick={() => setDesignationDialogOpen(true)} data-testid="new-designation">
                                                        <PlusIcon /> New designation
                                                    </Button>
                                                )}
                                            </Flex>
                                            <Select.Root value={form.data.designation_id ? String(form.data.designation_id) : undefined} onValueChange={(value) => { if (value) handleChange('designation_id', value); /* Radix emits '' while a just-added item registers: never treat that as a clear */ }} disabled={!form.data.department_id || filteredDesignations.length === 0 || !canPlace}>
                                                <Select.Trigger
                                                    placeholder={!form.data.department_id ? 'Select department first' : (filteredDesignations.length === 0 ? 'No designations in this department yet' : 'Select designation')}
                                                    style={{ width: '100%' }}
                                                />
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
                                            <Flex align="center" gap="1" mb="1"><Text as="label" size="2" weight="medium">Reports To</Text>{lockHint(!canPlace, 'reporting line')}</Flex>
                                            <Select.Root value={form.data.report_to ? String(form.data.report_to) : undefined} onValueChange={(value) => { if (value) handleChange('report_to', value); }} disabled={!form.data.department_id || filteredReportTo.length === 0 || !canPlace}>
                                                <Select.Trigger
                                                    style={{ width: '100%' }}
                                                    placeholder={
                                                        !form.data.department_id
                                                            ? "Select department first"
                                                            : filteredReportTo.length === 0
                                                            ? "No supervisor available for this department"
                                                            : "Select a supervisor"
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
                                            <Flex align="center" gap="1" mb="1"><Text as="label" size="2" weight="medium">Work Location</Text>{lockHint(!canPlace, 'work location')}</Flex>
                                            <Select.Root value={form.data.work_location_id ? String(form.data.work_location_id) : 'none'} onValueChange={(value) => handleChange('work_location_id', value === 'none' ? '' : value)} disabled={!canPlace}>
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

                                        {/* Attendance Method Override (multi-method, OR-validated) */}
                                        <Box>
                                            <Flex align="center" justify="between" mb="2" mt="1">
                                                <Flex align="center" gap="1"><Text as="label" size="2" weight="medium">Custom Attendance Override</Text>{lockHint(!canConfigureAttendance, 'attendance method')}</Flex>
                                                <Switch
                                                    disabled={!canConfigureAttendance}
                                                    checked={hasOverride}
                                                    onCheckedChange={(checked) => {
                                                        setHasOverride(checked);
                                                        if (!checked) {
                                                            handleChange('attendance_type_ids', []);
                                                            handleChange('biometric_device_ids', []);
                                                        }
                                                    }}
                                                />
                                            </Flex>

                                            {hasOverride ? (
                                                <Box>
                                                    <Text size="1" color="gray" mb="2" as="div">
                                                        Select one or more methods — the employee can punch via <strong>any</strong> of them. This replaces the work-location methods.
                                                    </Text>
                                                    <Flex direction="column" gap="2" style={{ border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-2)', padding: '10px' }}>
                                                        {attendanceTypes?.map((type) => (
                                                            <Text as="label" size="2" key={type.id} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                                                                <Checkbox
                                                                    disabled={!canConfigureAttendance}
                                                                    checked={form.data.attendance_type_ids.includes(type.id)}
                                                                    onCheckedChange={() => {
                                                                        const cur = form.data.attendance_type_ids;
                                                                        handleChange('attendance_type_ids', cur.includes(type.id) ? cur.filter(x => x !== type.id) : [...cur, type.id]);
                                                                    }}
                                                                />
                                                                {type.name}
                                                            </Text>
                                                        ))}
                                                    </Flex>

                                                    {(() => {
                                                        // Terminals only matter for a biometric method. Offer the active ones linked to
                                                        // the chosen work location, else every active terminal (devices are
                                                        // infrastructure reference data, not something a department owns).
                                                        const usesBiometric = attendanceTypes
                                                            ?.some(t => form.data.attendance_type_ids.includes(t.id) && /^biometric/.test(String(t.slug || '')));
                                                        if (!usesBiometric) return null;
                                                        const offered = deviceOptionsForLocation(form.data.work_location_id, workLocations, biometricDevices);
                                                        // A terminal already assigned stays listed (so it can be unticked) even when the
                                                        // chosen location does not offer it.
                                                        const devs = [
                                                            ...offered,
                                                            ...(user?.override_biometric_devices ?? []).filter(d => !offered.some(o => o.id === d.id)),
                                                        ];
                                                        const linkedToLocation = form.data.work_location_id
                                                            && (workLocations?.find(w => String(w.id) === String(form.data.work_location_id))?.biometric_devices ?? []).some(d => d.is_active !== false);
                                                        return (
                                                            <Box mt="3">
                                                                <Text size="2" weight="medium" mb="1" as="div">Biometric Devices</Text>
                                                                <Text size="1" color="gray" mb="2" as="div">
                                                                    {devs.length === 0
                                                                        ? 'No active biometric terminals are configured yet.'
                                                                        : `${linkedToLocation ? 'Terminals linked to the selected work location.' : 'All active terminals.'} Leave all unchecked to accept any device of the biometric type.`}
                                                                </Text>
                                                                {devs.length > 0 && (
                                                                    <Flex direction="column" gap="2" style={{ border: '1px solid var(--gray-5)', borderRadius: 'var(--radius-2)', padding: '10px' }}>
                                                                        {devs.map(device => (
                                                                            <Text as="label" size="2" key={device.id} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                                                                                <Checkbox
                                                                                    disabled={!canConfigureAttendance}
                                                                                    checked={form.data.biometric_device_ids.includes(device.id)}
                                                                                    onCheckedChange={() => {
                                                                                        const cur = form.data.biometric_device_ids;
                                                                                        handleChange('biometric_device_ids', cur.includes(device.id) ? cur.filter(x => x !== device.id) : [...cur, device.id]);
                                                                                    }}
                                                                                />
                                                                                {device.name}{device.serial_number ? ` (${device.serial_number})` : ''}
                                                                            </Text>
                                                                        ))}
                                                                    </Flex>
                                                                )}
                                                            </Box>
                                                        );
                                                    })()}
                                                </Box>
                                            ) : (
                                                <Box p="2" style={{ backgroundColor: 'var(--gray-2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-4)' }}>
                                                    <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>
                                                        {form.data.work_location_id ? (
                                                            `Inherits from ${workLocations?.find(w => String(w.id) === String(form.data.work_location_id))?.name || 'location'}: ${
                                                                (workLocations?.find(w => String(w.id) === String(form.data.work_location_id))?.attendance_types?.map(t => t.name).join(', ')
                                                                    || workLocations?.find(w => String(w.id) === String(form.data.work_location_id))?.attendance_type?.name
                                                                    || 'Default')
                                                            }`
                                                        ) : (
                                                            'Unassigned. Uses default check-in verification.'
                                                        )}
                                                    </Text>
                                                </Box>
                                            )}
                                            {form.errors.attendance_type_ids && <Text color="red" size="1" mt="1" display="block">{form.errors.attendance_type_ids}</Text>}
                                        </Box>
                                    </Grid>
                                </Panel>
                            </Box>
                            </>)}

                            {showProfile && showAccess && <Separator size="4" />}

                            {showAccess && (<>
                            {/* SECTION 4: Access & Roles */}
                            <Box>
                                <Heading size="3" mb="3" color="indigo">Access & Security</Heading>
                                <Panel variant="surface">
                                    <Flex direction="column" gap="5">
                                        
                                        {/* Roles Grid — only for someone who manages access (employees.access.manage);
                                            a department admin never sees it and the server gives his hires the base role. */}
                                        {canManageAccess && (
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
                                        )}

                                        {/* No role management (a department admin): the target's roles stay visible, read-only. */}
                                        {!canManageAccess && editMode && (user?.roles || []).length > 0 && (
                                        <Box data-testid="roles-readonly">
                                            <Flex align="center" gap="1" mb="2">
                                                <Text as="label" size="2" weight="medium">Roles</Text>
                                                <LockedFieldHint row={lockedRow} field="role" />
                                            </Flex>
                                            <Flex gap="2" wrap="wrap">
                                                {(user.roles || []).map((role) => {
                                                    const name = typeof role === 'object' ? role.name : role;
                                                    return <Badge key={name} size="1" variant="soft" color="gray">{name}</Badge>;
                                                })}
                                            </Flex>
                                        </Box>
                                        )}

                                        {canManageScopes && (
                                            <DepartmentScopeSection userId={user.id} departments={departments} />
                                        )}

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
                                                <Switch checked={form.data.single_device_login_enabled} onCheckedChange={(checked) => handleChange('single_device_login_enabled', checked)} size="2" disabled={!canLockDevices} />
                                            </Flex>
                                        </Box>

                                        {/* Passwords (Only on Create) */}
                                        {!editMode && (
                                            <Box pt="2">
                                                <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                                                    <Box>
                                                        <Text as="label" size="2" weight="medium" mb="1" display="block">Password <Text color="red">*</Text></Text>
                                                        <TextField.Root type={showPassword ? 'text' : 'password'} placeholder="Create password" value={form.data.password} onChange={(e) => handleChange('password', e.target.value)} onBlur={() => validateField('password')} color={form.invalid('password') ? 'red' : undefined}>
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
                                                        <TextField.Root type={showPassword ? 'text' : 'password'} placeholder="Confirm password" value={form.data.password_confirmation} onChange={(e) => handleChange('password_confirmation', e.target.value)} onBlur={() => validateField('password_confirmation')} color={form.invalid('password_confirmation') || (form.data.password !== form.data.password_confirmation && form.data.password_confirmation) ? 'red' : undefined}>
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
                                </Panel>
                            </Box>
                            </>)}
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
                {designationDialogOpen && (
                    <DesignationForm
                        open
                        onClose={() => setDesignationDialogOpen(false)}
                        departments={departments}
                        designations={designationPool}
                        defaultDepartmentId={form.data.department_id}
                        onSuccess={(created) => {
                            if (!created) return;
                            setCreatedDesignations((prev) => [...prev, created]);
                            handleChange('designation_id', String(created.id));
                            router.reload({
                                only: ['designations', 'allDesignations', 'initialDesignations', 'designationStats', 'overviewStats'],
                                preserveScroll: true,
                            });
                        }}
                    />
                )}
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