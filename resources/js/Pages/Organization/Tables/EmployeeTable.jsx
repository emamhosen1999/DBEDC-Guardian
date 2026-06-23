import React, { useState, useMemo, useCallback, useRef } from "react";
import { Link } from '@inertiajs/react';
import { showToast } from "@/utils/toastUtils";
import axios from 'axios';
import {
    Box, Flex, Text, Button, DropdownMenu, Badge,
    IconButton, Select, Spinner, Table, Dialog, TextField
} from '@radix-ui/themes';
import {
    BackpackIcon, ClockIcon, DotsVerticalIcon,
    EnvelopeClosedIcon, HomeIcon, MobileIcon,
    Pencil1Icon, PersonIcon, TrashIcon, LockClosedIcon,
    EyeOpenIcon, EyeNoneIcon, ReloadIcon, SewingPinIcon
} from '@radix-ui/react-icons';
import * as useEmployeesQuery from '@/api/queries/useEmployeesQuery';
import AddEditUserFormRadix from '@/Forms/AddEditUserFormRadix.jsx';

// Assumes these are moved to Pages/Organization/Components/
import TablePagination from '../../../Components/TablePagination.jsx';
import DeleteEmployeeModal from '../../../Components/DeleteEmployeeModal.jsx';
import ProfilePictureModal from '../../../Components/ProfilePictureModal.jsx';
import ProfileAvatar from '../../../Components/Profile/ProfileAvatar.jsx';

/* ─── helpers ─── */
function getBaseSlug(slug) {
    return slug ? slug.replace(/_\d+$/, '') : '';
}

function hasValidConfig(type) {
    const config = type?.config;
    if (!config) return false;
    const base = getBaseSlug(type.slug);
    switch (base) {
        case 'geo_polygon': return (config.polygon?.length >= 3) || (config.polygons?.some(p => p.points?.length >= 3));
        case 'wifi_ip': return (config.allowed_ips?.length > 0) || (config.allowed_ranges?.length > 0) || (config.ip_locations?.some(l => l.allowed_ips?.length > 0 || l.allowed_ranges?.length > 0));
        case 'route_waypoint': return (config.waypoints?.length >= 2) || (config.routes?.some(r => r.waypoints?.length >= 2));
        case 'qr_code': return !!config.code || (config.qr_codes?.length > 0);
        case 'biometric': return true;
        default: return false;
    }
}

const EmployeeTable = ({
    employees: allUsers = [], allManagers = [], departments, designations, attendanceTypes, workLocations = [],
    isMobile, isTablet, pagination, totalRows = 0, loading = false,
    updateEmployeeOptimized, deleteEmployeeOptimized, onPageChange, onRowsPerPageChange,
    auth, roles,
}) => {
    const [deleteModalOpen, setDeleteModalOpen] = useState(false);
    const [employeeToDelete, setEmployeeToDelete] = useState(null);
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [profilePictureModal, setProfilePictureModal] = useState({ isOpen: false, employee: null });
    const reportToDebounceRef = useRef({});

    /* ── permission check ── */
    const canManageUsers = auth?.permissions?.includes('users.update') || false;

    /* ── user edit state ── */
    const [editUser, setEditUser] = useState(null);
    const [editScope, setEditScope] = useState('full');
    const openEditDialog = (user, scope) => { setEditScope(scope); setEditUser(user); };

    /* ── reset-password dialog state ── */
    const [pwUser, setPwUser]       = useState(null);
    const [pwValues, setPwValues]   = useState({ password: '', password_confirmation: '' });
    const [pwVisible, setPwVisible] = useState(false);
    const [pwError, setPwError]     = useState('');
    const [pwLoading, setPwLoading] = useState(false);

    const openPwDialog = (user) => {
        setPwValues({ password: '', password_confirmation: '' });
        setPwError('');
        setPwVisible(false);
        setPwUser(user);
    };

    const submitPasswordReset = async () => {
        if (!pwUser) return;
        const { password, password_confirmation } = pwValues;

        if (password.length < 8) {
            setPwError('Password must be at least 8 characters.');
            return;
        }
        if (password !== password_confirmation) {
            setPwError('Passwords do not match.');
            return;
        }

        setPwLoading(true);
        setPwError('');
        try {
            await axios.post(route('users.changePassword', { id: pwUser.id }), {
                password,
                password_confirmation,
            });
            showToast.success(`Password reset for ${pwUser.name}.`);
            setPwUser(null);
        } catch (e) {
            const msg = e.response?.data?.message
                || e.response?.data?.error
                || 'Failed to reset password.';
            setPwError(msg);
            showToast.error(msg);
        } finally {
            setPwLoading(false);
        }
    };

    /* ── device lock state & toggle ── */
    const [devAction, setDevAction] = useState({}); // { [userId]: bool }
    const toggleDeviceLock = async (user) => {
        setDevAction(p => ({ ...p, [user.id]: true }));
        try {
            const { data } = await axios.post(route('admin.users.devices.toggle', { userId: user.id }));
            updateEmployeeOptimized?.(user.id, { single_device_login_enabled: data.single_device_login_enabled });
            showToast.success(data.message || 'Device lock updated.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to toggle device lock.');
        } finally {
            setDevAction(p => ({ ...p, [user.id]: false }));
        }
    };

    // React Query mutations
    const updateDepartment = useEmployeesQuery.useUpdateDepartment();
    const updateDesignation = useEmployeesQuery.useUpdateDesignation();
    const updateAttendanceType = useEmployeesQuery.useUpdateAttendanceType();
    const updateBiometricDevice = useEmployeesQuery.useUpdateBiometricDevice();
    const updateReportTo = useEmployeesQuery.useUpdateReportTo();
    const updateWorkLocation = useEmployeesQuery.useUpdateWorkLocation();
    const deleteEmployee = useEmployeesQuery.useDeleteEmployee();
    const isMutating = updateDepartment.isPending || updateDesignation.isPending || updateAttendanceType.isPending || updateBiometricDevice.isPending || updateReportTo.isPending || deleteEmployee.isPending;

    const groupedAttendanceTypes = useMemo(() => {
        if (!attendanceTypes) return [];
        const grouped = {};
        attendanceTypes.forEach(type => {
            if (!type.is_active || !hasValidConfig(type)) return;
            const base = getBaseSlug(type.slug);
            if (!grouped[base]) grouped[base] = { slug: base, label: base.replace(/_/g, ' '), types: [] };
            grouped[base].types.push(type);
        });
        return Object.values(grouped).filter(c => c.types.length > 0);
    }, [attendanceTypes]);

    /* ── handlers ── */
    const handleDepartmentChange = async (userId, departmentId) => {
        try {
            await updateDepartment.mutateAsync({ id: userId, department: departmentId });
            const dept = departments.find(d => d.id === parseInt(departmentId)) || null;
            updateEmployeeOptimized?.(userId, { department_id: departmentId, department_name: dept?.name || null, designation_id: null, designation_name: null });
            showToast.success('Department updated');
        } catch { showToast.error('Failed to update department'); }
    };

    const handleDesignationChange = async (userId, designationId) => {
        try {
            await updateDesignation.mutateAsync({ id: userId, designation_id: designationId });
            const desig = designations.find(d => d.id === parseInt(designationId)) || null;
            updateEmployeeOptimized?.(userId, { designation_id: designationId, designation_name: desig?.title || null });
            showToast.success('Designation updated');
        } catch { showToast.error('Failed to update designation'); }
    };

    const handleAttendanceTypeChange = async (userId, attendanceTypeId) => {
        try {
            await updateAttendanceType.mutateAsync({ id: userId, attendance_type_id: attendanceTypeId });
            if (attendanceTypeId === null) {
                // Cleared personal override → inherit from work location.
                updateEmployeeOptimized?.(userId, { attendance_type_id: null, attendance_type_name: null, attendance_type_devices: [], has_attendance_override: false, biometric_device_id: null });
                showToast.success('Reverted to work location attendance rule');
            } else {
                const type = attendanceTypes.find(t => t.id === parseInt(attendanceTypeId)) || null;
                const devices = (type?.biometric_devices ?? []).map(d => ({ id: d.id, name: d.name, serial_number: d.serial_number, location: d.location }));
                updateEmployeeOptimized?.(userId, { attendance_type_id: attendanceTypeId, attendance_type_name: type?.name || null, attendance_type_devices: devices, has_attendance_override: true, biometric_device_id: null });
                showToast.success('Attendance type updated');
            }
        } catch { showToast.error('Failed to update attendance type'); }
    };

    const handleBiometricDeviceChange = async (userId, deviceId) => {
        try {
            // mutateAsync resolves to the response body, not an axios response — read fields directly.
            const data = await updateBiometricDevice.mutateAsync({ id: userId, biometric_device_id: deviceId || null });
            updateEmployeeOptimized?.(userId, { biometric_device_id: data?.biometric_device_id ?? null, biometric_device_name: data?.biometric_device_name ?? null });
            showToast.success(data?.message || 'Device assigned');
        } catch (e) { showToast.error(e.response?.data?.message || 'Failed to assign device'); }
    };

    const handleWorkLocationChange = async (userId, workLocationId) => {
        try {
            const loc = workLocations.find(w => String(w.id) === String(workLocationId)) || null;
            // mutateAsync resolves to the response body ({ message, user }), not an axios response —
            // so read fields off it directly (do NOT destructure `.data`).
            const result = await updateWorkLocation.mutateAsync({ id: userId, work_location_id: workLocationId || null });
            updateEmployeeOptimized?.(userId, {
                work_location_id: workLocationId || null,
                work_location_name: loc?.name || null,
                work_location_attendance_type_name: loc?.attendance_type?.name || null,
            });
            showToast.success(result?.message || 'Work location updated');
        } catch (e) { showToast.error(e.response?.data?.message || 'Failed to update work location'); }
    };

    const debouncedUpdateReportTo = useCallback((userId, reportToId) => {
        if (reportToDebounceRef.current[userId]) clearTimeout(reportToDebounceRef.current[userId]);
        reportToDebounceRef.current[userId] = setTimeout(async () => {
            try {
                const data = await updateReportTo.mutateAsync({ id: userId, report_to: reportToId || null });
                updateEmployeeOptimized?.(userId, { report_to: reportToId || null, reports_to: data?.user?.reports_to || null });
                showToast.success('Manager assigned');
            } catch { showToast.error('Failed to update manager'); }
        }, 500);
    }, [updateEmployeeOptimized]);

    const getEligibleManagers = useCallback((user, currentManagerId = null) => {
        if (!allManagers.length) return [];
        const userDeptId = user?.department_id;
        const userLevel = user?.designation_hierarchy_level ?? 999;
        const sameDept = allManagers.filter(m => String(m.department_id) === String(userDeptId) && m.id !== user.id);
        const isDeptHead = !sameDept.some(m => (m.designation_hierarchy_level ?? 999) < userLevel);
        return allManagers.filter(m => {
            if (m.id === user.id) return false;
            if (currentManagerId && m.id === currentManagerId) return true;
            const mLevel = m.designation_hierarchy_level ?? 999;
            if (mLevel >= userLevel) return false;
            if (String(m.department_id) === String(userDeptId)) return true;
            return isDeptHead;
        });
    }, [allManagers]);

    const handleDeleteClick = (user) => { setEmployeeToDelete(user); setDeleteModalOpen(true); };
    const handleDeleteConfirm = async () => {
        if (!employeeToDelete) return;
        
        setDeleteLoading(true);
        try {
            await deleteEmployee.mutateAsync(employeeToDelete.id);
            deleteEmployeeOptimized?.(employeeToDelete.id);
            setDeleteModalOpen(false); setEmployeeToDelete(null);
            showToast.success('Employee deleted');
        } catch (err) { showToast.error(err.response?.data?.error || 'Failed to delete employee'); }
        finally { setDeleteLoading(false); }
    };

    const handleRestoreClick = async (user) => {
        try {
            await axios.post(route('users.restore', { id: user.id }));
            showToast.success(`${user.name} restored successfully.`);
            updateEmployeeOptimized?.(user.id, { deleted_at: null });
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to restore employee.');
        }
    };

    const startRow = ((pagination.currentPage - 1) * pagination.perPage) + 1;

    return (
        <Box style={{ position: 'relative', overflow: 'hidden' }}>
            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface" size={isMobile ? '1' : '2'}>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell style={{ width: 48 }}>#</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Contact</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell>Department</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Designation</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Role</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ width: 90 }}>Status</Table.ColumnHeaderCell>
                            {!isMobile && !isTablet && <Table.ColumnHeaderCell>Work Location</Table.ColumnHeaderCell>}
                            {!isMobile && !isTablet && <Table.ColumnHeaderCell>Attendance Type</Table.ColumnHeaderCell>}
                            {!isMobile && <Table.ColumnHeaderCell>Reports To</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell style={{ width: 56, textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {allUsers.length === 0 && !loading ? (
                            <Table.Row><Table.Cell colSpan={8}><Flex justify="center" py="8"><Text color="gray">No employees found.</Text></Flex></Table.Cell></Table.Row>
                        ) : allUsers.map((user, idx) => {
                            const filtDesignations = designations?.filter(d => d.department_id === parseInt(user.department_id)) || [];
                            const selectedAttType = attendanceTypes?.find(t => t.id === parseInt(user.attendance_type_id));
                            const isBiometricSelected = selectedAttType && getBaseSlug(selectedAttType.slug) === 'biometric';
                            // Device options come from the resolved attendance type definition so they
                            // appear on initial load too (not only right after a change).
                            const biometricDevices = selectedAttType?.biometric_devices || user.attendance_type_devices || [];
                            return (
                                <Table.Row key={user.id} style={user.deleted_at ? { opacity: 0.65 } : undefined}>
                                    <Table.Cell><Text size="1" color="gray" weight="medium">{startRow + idx}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Flex align="center" gap="3">
                                            <Box style={{ cursor: 'pointer' }} onClick={() => setProfilePictureModal({ isOpen: true, employee: user })}>
                                                <ProfileAvatar src={user?.profile_image_url || user?.profile_image} name={user?.name} size={isMobile ? 'sm' : 'md'} />
                                            </Box>
                                            <Box>
                                                <Text weight="bold" size="2" as="div" style={{ whiteSpace: 'nowrap' }}>{user?.name}</Text>
                                                <Text size="1" color="gray" as="div" style={{ display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'wrap' }}>
                                                    ID: {user?.employee_id || 'N/A'}
                                                    {user?.single_device_login_enabled && (
                                                        <Badge color="amber" size="1" variant="soft">
                                                            <LockClosedIcon style={{ width: 10, height: 10 }} /> Locked
                                                        </Badge>
                                                    )}
                                                </Text>
                                            </Box>
                                        </Flex>
                                    </Table.Cell>
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Flex direction="column" gap="1">
                                                <Flex align="center" gap="2"><EnvelopeClosedIcon color="var(--gray-9)" /><Text size="1" color="gray">{user?.email}</Text></Flex>
                                                {user?.phone && <Flex align="center" gap="2"><MobileIcon color="var(--gray-9)" /><Text size="1" color="gray">{user.phone}</Text></Flex>}
                                            </Flex>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        <DropdownMenu.Root>
                                            <DropdownMenu.Trigger>
                                                <Button size="1" variant="surface" color="gray" style={{ width: '100%', minWidth: 140, justifyContent: 'space-between' }}>
                                                    <Flex align="center" gap="1"><HomeIcon /><Text size="1">{user.department_name || 'Select…'}</Text></Flex><Text size="1" color="gray">▾</Text>
                                                </Button>
                                            </DropdownMenu.Trigger>
                                            <DropdownMenu.Content size="1">
                                                {departments?.map(dept => <DropdownMenu.Item key={dept.id} onSelect={() => handleDepartmentChange(user.id, dept.id)}>{dept.name}</DropdownMenu.Item>)}
                                            </DropdownMenu.Content>
                                        </DropdownMenu.Root>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <DropdownMenu.Root>
                                            <DropdownMenu.Trigger>
                                                <Button size="1" variant="surface" color="gray" disabled={!user.department_id} style={{ width: '100%', minWidth: 140, justifyContent: 'space-between' }}>
                                                    <Flex align="center" gap="1"><BackpackIcon /><Text size="1">{!user.department_id ? 'Select dept first' : (user.designation_name || 'Select…')}</Text></Flex>
                                                </Button>
                                            </DropdownMenu.Trigger>
                                            <DropdownMenu.Content size="1">
                                                {filtDesignations.map(desig => <DropdownMenu.Item key={desig.id} onSelect={() => handleDesignationChange(user.id, desig.id)}>{desig.title}</DropdownMenu.Item>)}
                                            </DropdownMenu.Content>
                                        </DropdownMenu.Root>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="1" wrap="wrap" style={{ minWidth: 110 }}>
                                            {(user.roles || []).map(r => (
                                                <Badge key={r.id || r.name} size="1" variant="soft" color="violet" radius="full">
                                                    {r.name}
                                                </Badge>
                                            ))}
                                            {(!user.roles || user.roles.length === 0) && (
                                                <Text size="1" color="gray">—</Text>
                                            )}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color={!user.deleted_at ? 'green' : 'red'}>
                                            {!user.deleted_at ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </Table.Cell>
                                    {!isMobile && !isTablet && (
                                        <Table.Cell>
                                            <Select.Root size="1" value={user.work_location_id ? String(user.work_location_id) : 'none'} onValueChange={(v) => handleWorkLocationChange(user.id, v === 'none' ? null : parseInt(v))}>
                                                <Select.Trigger style={{ width: '100%', minWidth: 150 }} placeholder="Unassigned" />
                                                <Select.Content>
                                                    <Select.Item value="none">Unassigned / Remote</Select.Item>
                                                    {workLocations?.map(loc => <Select.Item key={loc.id} value={String(loc.id)}>{loc.name}</Select.Item>)}
                                                </Select.Content>
                                            </Select.Root>
                                            {!user.has_attendance_override && user.work_location_attendance_type_name && (
                                                <Text size="1" color="gray" mt="1" as="div" style={{ fontStyle: 'italic' }}>
                                                    Inherits: {user.work_location_attendance_type_name}
                                                </Text>
                                            )}
                                        </Table.Cell>
                                    )}
                                    {!isMobile && !isTablet && (
                                        <Table.Cell>
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger>
                                                    <Button size="1" variant="surface" color="gray" style={{ width: '100%', minWidth: 160, justifyContent: 'space-between' }}>
                                                        <Flex align="center" gap="1"><ClockIcon /><Text size="1">{user.attendance_type_name || (user.has_attendance_override ? 'Select…' : (user.work_location_attendance_type_name ? `${user.work_location_attendance_type_name} (inherited)` : 'Select…'))}</Text></Flex>
                                                    </Button>
                                                </DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    <DropdownMenu.Item onSelect={() => handleAttendanceTypeChange(user.id, null)}>
                                                        <Flex align="center" gap="1">
                                                            <SewingPinIcon />
                                                            Inherit from work location
                                                            {user.work_location_attendance_type_name ? ` (${user.work_location_attendance_type_name})` : ''}
                                                        </Flex>
                                                    </DropdownMenu.Item>
                                                    <DropdownMenu.Separator />
                                                    {groupedAttendanceTypes.map((cat, ci) => (
                                                        <React.Fragment key={cat.slug}>
                                                            {ci > 0 && <DropdownMenu.Separator />}<DropdownMenu.Label>{cat.label}</DropdownMenu.Label>
                                                            {cat.types.map(type => <DropdownMenu.Item key={type.id} onSelect={() => handleAttendanceTypeChange(user.id, type.id)}>{type.name}</DropdownMenu.Item>)}
                                                        </React.Fragment>
                                                    ))}
                                                </DropdownMenu.Content>
                                            </DropdownMenu.Root>
                                            {isBiometricSelected && (
                                                <Box mt="1">
                                                    <Select.Root size="1" value={user.biometric_device_id ? String(user.biometric_device_id) : ''} onValueChange={(v) => handleBiometricDeviceChange(user.id, v ? parseInt(v) : null)}>
                                                        <Select.Trigger style={{ width: '100%' }} placeholder={biometricDevices.length ? 'Select device…' : 'No devices configured'} />
                                                        <Select.Content>
                                                            {biometricDevices.map(device => <Select.Item key={device.id} value={String(device.id)}>{device.name}</Select.Item>)}
                                                        </Select.Content>
                                                    </Select.Root>
                                                </Box>
                                            )}
                                        </Table.Cell>
                                    )}
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Select.Root size="1" value={user.report_to ? String(user.report_to) : ''} onValueChange={(v) => debouncedUpdateReportTo(user.id, v ? parseInt(v) : null)}>
                                                <Select.Trigger style={{ width: '100%', minWidth: 140 }} placeholder={user.reports_to?.name || 'Select manager…'} />
                                                <Select.Content>
                                                    {getEligibleManagers(user, user.report_to).map(mgr => <Select.Item key={mgr.id} value={String(mgr.id)}>{mgr.name}</Select.Item>)}
                                                </Select.Content>
                                            </Select.Root>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        <Flex justify="center">
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger><IconButton size="1" variant="ghost" color="gray"><DotsVerticalIcon /></IconButton></DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    <DropdownMenu.Item asChild><Link href={route('profile', { user: user.id })}><Flex gap="2"><Pencil1Icon />Full Profile</Flex></Link></DropdownMenu.Item>
                                                    {canManageUsers && (
                                                        <>
                                                            <DropdownMenu.Separator />
                                                            <DropdownMenu.Item onSelect={() => openEditDialog(user, 'profile')}>
                                                                <Flex gap="2"><Pencil1Icon />Edit Profile</Flex>
                                                            </DropdownMenu.Item>
                                                            <DropdownMenu.Item onSelect={() => openEditDialog(user, 'access')}>
                                                                <Flex gap="2"><LockClosedIcon />Manage Access</Flex>
                                                            </DropdownMenu.Item>
                                                            <DropdownMenu.Item onSelect={() => openPwDialog(user)}>
                                                                <Flex gap="2"><LockClosedIcon />Reset Password</Flex>
                                                            </DropdownMenu.Item>
                                                            <DropdownMenu.Item onSelect={() => toggleDeviceLock(user)} disabled={!!devAction[user.id]}>
                                                                <Flex gap="2">
                                                                    <LockClosedIcon />
                                                                    {user.single_device_login_enabled ? 'Disable Device Lock' : 'Enable Device Lock'}
                                                                    {devAction[user.id] && <Spinner size="1" />}
                                                                </Flex>
                                                            </DropdownMenu.Item>
                                                            <DropdownMenu.Item asChild>
                                                                <Link href={route('admin.users.devices', { userId: user.id })}>
                                                                    <Flex gap="2"><MobileIcon />Device History</Flex>
                                                                </Link>
                                                            </DropdownMenu.Item>
                                                        </>
                                                    )}
                                                    <DropdownMenu.Separator />
                                                    {user.deleted_at ? (
                                                        <DropdownMenu.Item color="green" onSelect={() => handleRestoreClick(user)}>
                                                            <Flex gap="2"><ReloadIcon />Restore</Flex>
                                                        </DropdownMenu.Item>
                                                    ) : (
                                                        <DropdownMenu.Item color="red" onSelect={() => handleDeleteClick(user)}>
                                                            <Flex gap="2"><TrashIcon />Delete</Flex>
                                                        </DropdownMenu.Item>
                                                    )}
                                                </DropdownMenu.Content>
                                            </DropdownMenu.Root>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}
                    </Table.Body>
                </Table.Root>
            </Box>

            <TablePagination pagination={pagination} onPageChange={onPageChange} onRowsPerPageChange={onRowsPerPageChange} loading={loading} />
            <DeleteEmployeeModal open={deleteModalOpen} onClose={() => setDeleteModalOpen(false)} employee={employeeToDelete} onConfirm={handleDeleteConfirm} loading={deleteLoading} />
            <ProfilePictureModal isOpen={profilePictureModal.isOpen} onClose={() => setProfilePictureModal({ isOpen: false })} employee={profilePictureModal.employee} onImageUpdate={(id, url) => updateEmployeeOptimized?.(id, { profile_image_url: url })} />

            {/* ── Edit Profile / Manage Access Modal ── */}
            {editUser && (
                <AddEditUserFormRadix
                    user={editUser}
                    open={!!editUser}
                    closeModal={() => setEditUser(null)}
                    departments={departments}
                    designations={designations}
                    roles={roles}
                    workLocations={workLocations}
                    attendanceTypes={attendanceTypes}
                    allUsers={allManagers}
                    editMode={true}
                    scope={editScope}
                    onSuccess={(response) => {
                        setEditUser(null);
                        updateEmployeeOptimized?.(response.data.user.id, {
                            name: response.data.user.name,
                            email: response.data.user.email,
                            phone: response.data.user.phone,
                            employee_id: response.data.user.employee_id,
                            department_id: response.data.user.department_id,
                            department_name: response.data.user.department?.name,
                            designation_id: response.data.user.designation_id,
                            designation_name: response.data.user.designation?.title,
                            attendance_type_id: response.data.user.attendance_type_id,
                            attendance_type_name: response.data.user.attendance_type?.name,
                        });
                    }}
                />
            )}

            {/* ── Reset Password Dialog ── */}
            <Dialog.Root open={!!pwUser} onOpenChange={o => { if (!o && !pwLoading) setPwUser(null); }}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Reset Password — {pwUser?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Set a new password for this user. They can sign in with it immediately on web and mobile.
                    </Dialog.Description>

                    <Flex direction="column" gap="3" mt="4">
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">New password</Text>
                            <TextField.Root
                                type={pwVisible ? 'text' : 'password'}
                                value={pwValues.password}
                                placeholder="At least 8 characters"
                                autoComplete="new-password"
                                onChange={e => { setPwValues(v => ({ ...v, password: e.target.value })); setPwError(''); }}
                            >
                                <TextField.Slot side="right">
                                    <IconButton size="1" variant="ghost" color="gray" type="button"
                                        aria-label={pwVisible ? 'Hide password' : 'Show password'}
                                        onClick={() => setPwVisible(v => !v)}>
                                        {pwVisible ? <EyeNoneIcon /> : <EyeOpenIcon />}
                                    </IconButton>
                                </TextField.Slot>
                            </TextField.Root>
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Confirm password</Text>
                            <TextField.Root
                                type={pwVisible ? 'text' : 'password'}
                                value={pwValues.password_confirmation}
                                placeholder="Re-enter the new password"
                                autoComplete="new-password"
                                onChange={e => { setPwValues(v => ({ ...v, password_confirmation: e.target.value })); setPwError(''); }}
                                onKeyDown={e => { if (e.key === 'Enter' && !pwLoading) submitPasswordReset(); }}
                            />
                        </Box>

                        {pwError && (
                            <Text color="red" size="2" weight="medium">{pwError}</Text>
                        )}
                    </Flex>

                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close>
                            <Button variant="soft" color="gray" disabled={pwLoading}>Cancel</Button>
                        </Dialog.Close>
                        <Button onClick={submitPasswordReset} disabled={pwLoading}>
                            {pwLoading ? <Spinner size="1" /> : 'Reset Password'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
};

export default EmployeeTable;
