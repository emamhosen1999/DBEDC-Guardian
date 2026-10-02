import React, { useState, useMemo, useCallback, useRef } from "react";
import { Link } from '@inertiajs/react';
import { showToast } from "@/utils/toastUtils";
import axios from 'axios';
import {
    Box, Flex, Text, Button, DropdownMenu, Badge,
    IconButton, Select, Spinner, Table, Dialog, TextField, Checkbox
} from '@radix-ui/themes';
import {
    BackpackIcon, ClockIcon, DotsVerticalIcon,
    EnvelopeClosedIcon, HomeIcon, MobileIcon,
    Pencil1Icon, PersonIcon, TrashIcon, LockClosedIcon,
    EyeOpenIcon, EyeNoneIcon, ReloadIcon, SewingPinIcon, IdCardIcon
} from '@radix-ui/react-icons';
import * as useEmployeesQuery from '@/api/queries/useEmployeesQuery';
import AddEditUserFormRadix from '@/Forms/AddEditUserFormRadix.jsx';
import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import LockedFieldHint from '@/Components/Common/LockedFieldHint';
import { useDepartmentScope } from '@/Hooks/useDepartmentScope';
import { deviceOptionsForLocation } from '@/utils/deviceOptions';
import { eligibleManagers } from '@/utils/reportingLine';

// Assumes these are moved to Pages/Organization/Components/
import TablePagination from '../../../Components/TablePagination.jsx';
import DeleteEmployeeModal from '../../../Components/DeleteEmployeeModal.jsx';
import ProfilePictureModal from '../../../Components/ProfilePictureModal.jsx';
import ProfileAvatar from '../../../Components/Profile/ProfileAvatar.jsx';

/* Read-only value with the lock hint, only when the server's per-row `can` says it is locked for this viewer. */
function LockedCell({ show, row, field, children }) {
    return show ? <LockedFieldHint row={row} field={field}>{children}</LockedFieldHint> : children;
}

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
    employees: allUsers = [], allManagers = [], departments, designations, attendanceTypes, workLocations = [], biometricDevices = [],
    isMobile, isTablet, pagination, totalRows = 0, loading = false,
    updateEmployeeOptimized, deleteEmployeeOptimized, onPageChange, onRowsPerPageChange,
    auth, roles,
}) => {
    const [deleteModalOpen, setDeleteModalOpen] = useState(false);
    const [employeeToDelete, setEmployeeToDelete] = useState(null);
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [profilePictureModal, setProfilePictureModal] = useState({ isOpen: false, employee: null });
    const reportToDebounceRef = useRef({});

    /* ── capabilities ──
       Every row arrives with `can`: the policy abilities the routes enforce (scope, outranking, never
       oneself), so a control is offered exactly when the server would accept it. Rows without it
       (never the directory list) fall back to the actor's permission alone. */
    const holds = (permission) => Boolean(auth?.isSuperAdmin || auth?.permissions?.includes(permission));
    const canOn = (row, key, permission) => (row?.can ? Boolean(row.can[key]) : holds(permission));
    // Department scope: one department -> the inline cell is locked, several -> a limited list.
    const deptScope = useDepartmentScope(departments);

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

    /* ── bulk deactivate (soft delete): only rows the actor may delete are selectable ── */
    const [selected, setSelected] = useState([]);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkLoading, setBulkLoading] = useState(false);
    const selectableIds = useMemo(
        () => allUsers.filter(u => !u.deleted_at && canOn(u, 'delete', 'employees.delete')).map(u => u.id),
        [allUsers, auth],
    );
    const showSelection = selectableIds.length > 0;
    const allSelected = showSelection && selectableIds.every(id => selected.includes(id));
    const toggleSelected = (id) => setSelected(prev => (prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]));

    const submitBulkDelete = async () => {
        setBulkLoading(true);
        try {
            await axios.post(route('users.bulk.delete'), { user_ids: selected });
            selected.forEach(id => deleteEmployeeOptimized?.(id));
            showToast.success(`${selected.length} employee(s) deactivated.`);
            setSelected([]);
        } catch (e) {
            const dependencies = e.response?.data?.dependencies;
            showToast.error(e.response?.data?.message || e.response?.data?.error || (dependencies ? 'Some selected employees have active dependencies.' : 'Failed to delete the selected employees.'));
        } finally {
            setBulkLoading(false);
            setBulkOpen(false);
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

    const actorId = auth?.user?.employee_id ?? null;
    const getEligibleManagers = useCallback((user, currentManagerId = null) => eligibleManagers({
        managers: allManagers,
        subject: { id: user.id, department_id: user.department_id },
        subjectLevel: user?.designation_hierarchy_level ?? null,
        actorId,
        currentManagerId,
        crossDepartmentHead: true,
    }), [allManagers, actorId]);

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
            {selected.length > 0 && (
                <Flex align="center" justify="between" gap="3" mb="2" p="2" style={{ background: 'var(--red-a3)', borderRadius: 8 }} data-testid="bulk-bar">
                    <Text size="2" weight="medium">{selected.length} selected</Text>
                    <Flex gap="2">
                        <Button size="1" variant="soft" color="gray" onClick={() => setSelected([])}>Clear</Button>
                        <Button size="1" color="red" onClick={() => setBulkOpen(true)}><TrashIcon /> Delete selected</Button>
                    </Flex>
                </Flex>
            )}
            <ConfirmDialog
                open={bulkOpen}
                onClose={() => !bulkLoading && setBulkOpen(false)}
                onConfirm={submitBulkDelete}
                title="Deactivate selected employees?"
                description={`${selected.length} employee(s) will be deactivated (soft deleted). They can be restored later.`}
                confirmText="Delete"
                confirmColor="red"
            />
            <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch' }}>
                <Table.Root size={isMobile ? '1' : '2'} style={{ minWidth: isMobile ? 900 : 1480, width: '100%' }}>
                    <Table.Header>
                        <Table.Row>
                            {showSelection && (
                                <Table.ColumnHeaderCell style={{ width: 36, textAlign: 'center' }}>
                                    <Checkbox
                                        aria-label="Select all employees on this page"
                                        checked={allSelected}
                                        onCheckedChange={(v) => setSelected(v === true ? selectableIds : [])}
                                    />
                                </Table.ColumnHeaderCell>
                            )}
                            <Table.ColumnHeaderCell style={{ width: 44, textAlign: 'center' }}>#</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ minWidth: 190 }}>Employee</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell style={{ minWidth: 180 }}>Contact</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell style={{ minWidth: 160 }}>Department</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ minWidth: 170 }}>Designation</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ minWidth: 110 }}>Role</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ width: 90 }}>Status</Table.ColumnHeaderCell>
                            {!isMobile && !isTablet && <Table.ColumnHeaderCell style={{ minWidth: 170 }}>Work Location</Table.ColumnHeaderCell>}
                            {!isMobile && !isTablet && <Table.ColumnHeaderCell style={{ minWidth: 180 }}>Attendance Type</Table.ColumnHeaderCell>}
                            {!isMobile && <Table.ColumnHeaderCell style={{ minWidth: 150 }}>Reports To</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell style={{ width: 56, textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {allUsers.length === 0 && !loading ? (
                            <Table.Row><Table.Cell colSpan={12}><Flex justify="center" py="8"><Text color="gray">No employees found.</Text></Flex></Table.Cell></Table.Row>
                        ) : allUsers.map((user, idx) => {
                            // The department's ACTIVE designations (the one already held stays listed even if since deactivated).
                            const filtDesignations = designations?.filter(d => d.department_id === parseInt(user.department_id) && (d.is_active !== false || d.id === user.designation_id)) || [];
                            const selectedAttType = attendanceTypes?.find(t => t.id === parseInt(user.attendance_type_id));
                            const isBiometricSelected = selectedAttType && getBaseSlug(selectedAttType.slug) === 'biometric';
                            // Terminals: the active ones linked to the employee's work location, else every
                            // active terminal (infrastructure reference data — never empty just because the
                            // department has no staff at that site yet).
                            const rowDevices = deviceOptionsForLocation(user.work_location_id, workLocations, biometricDevices);
                            return (
                                <Table.Row key={user.id} style={user.deleted_at ? { opacity: 0.65 } : undefined}>
                                    {showSelection && (
                                        <Table.Cell style={{ textAlign: 'center' }}>
                                            {selectableIds.includes(user.id) && (
                                                <Checkbox aria-label={`Select ${user.name}`} checked={selected.includes(user.id)} onCheckedChange={() => toggleSelected(user.id)} />
                                            )}
                                        </Table.Cell>
                                    )}
                                    <Table.Cell style={{ textAlign: 'center' }}><Text size="1" color="gray" weight="medium" style={{ fontVariantNumeric: 'tabular-nums' }}>{startRow + idx}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Flex align="center" gap="3" style={{ minWidth: 180, maxWidth: 220 }}>
                                            <Box style={{ cursor: 'pointer', flexShrink: 0 }} onClick={() => setProfilePictureModal({ isOpen: true, employee: user })}>
                                                <ProfileAvatar src={user?.profile_image_url || user?.profile_image} name={user?.name} size={isMobile ? 'sm' : 'md'} />
                                            </Box>
                                            <Box style={{ minWidth: 0, flex: 1 }}>
                                                <Text weight="bold" size="2" as="div" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontFamily: `'Space Grotesk', system-ui, sans-serif`, color: 'var(--gray-12)' }}>{user?.name}</Text>
                                                <Text size="1" color="gray" as="div" style={{ display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'wrap', fontVariantNumeric: 'tabular-nums' }}>
                                                    ID: {user?.employee_id || 'N/A'}
                                                    {user?.single_device_login_enabled && (
                                                        <Badge color="amber" size="1" variant="soft" style={{ borderRadius: 999 }}>
                                                            <LockClosedIcon style={{ width: 10, height: 10 }} /> Locked
                                                        </Badge>
                                                    )}
                                                </Text>
                                            </Box>
                                        </Flex>
                                    </Table.Cell>
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Flex direction="column" gap="1" style={{ minWidth: 170, maxWidth: 210 }}>
                                                <Flex align="center" gap="2" style={{ overflow: 'hidden' }}>
                                                    <EnvelopeClosedIcon color="var(--gray-9)" style={{ flexShrink: 0, width: 13, height: 13 }} />
                                                    <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user?.email}</Text>
                                                </Flex>
                                                {user?.phone && (
                                                    <Flex align="center" gap="2" style={{ overflow: 'hidden' }}>
                                                        <MobileIcon color="var(--gray-9)" style={{ flexShrink: 0, width: 13, height: 13 }} />
                                                        <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>{user.phone}</Text>
                                                    </Flex>
                                                )}
                                            </Flex>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        <Box style={{ minWidth: 150, maxWidth: 180 }}>
                                            {(deptScope.isSingle || !canOn(user, 'transfer', 'employees.update')) ? (
                                                // One department only (or no transfer right over this person): nothing to choose,
                                                // so no dropdown that would offer a move the server would refuse.
                                                <LockedCell show={!canOn(user, 'transfer', 'employees.update') && !!user.can} row={user} field="department">
                                                <Badge color="blue" variant="soft" size="1" data-testid="department-cell-locked" style={{ borderRadius: 8, padding: '4px 8px' }}>
                                                    <HomeIcon style={{ width: 13, height: 13 }} /> {user.department_name || deptScope.single?.name || '—'}
                                                </Badge>
                                                </LockedCell>
                                            ) : (
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger>
                                                    <Button size="1" variant="surface" color="gray" style={{ width: '100%', justifyContent: 'space-between', borderRadius: 8 }}>
                                                        <Flex align="center" gap="1" style={{ overflow: 'hidden', minWidth: 0, flex: 1 }}>
                                                            <HomeIcon style={{ flexShrink: 0, width: 13, height: 13 }} />
                                                            <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user.department_name || 'Select…'}</Text>
                                                        </Flex>
                                                        <Text size="1" color="gray" style={{ flexShrink: 0, marginLeft: 2 }}>▾</Text>
                                                    </Button>
                                                </DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    {deptScope.departments.map(dept => <DropdownMenu.Item key={dept.id} onSelect={() => handleDepartmentChange(user.id, dept.id)}>{dept.name}</DropdownMenu.Item>)}
                                                </DropdownMenu.Content>
                                            </DropdownMenu.Root>
                                            )}
                                        </Box>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Box style={{ minWidth: 155, maxWidth: 190 }}>
                                            {!canOn(user, 'placement', 'employees.placement.update') ? (
                                                <LockedCell show={!!user.can} row={user} field="designation">
                                                <Badge color="violet" variant="soft" size="1" style={{ borderRadius: 8, padding: '4px 8px' }}>
                                                    <BackpackIcon style={{ width: 13, height: 13 }} /> {user.designation_name || '—'}
                                                </Badge>
                                                </LockedCell>
                                            ) : (
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger>
                                                    <Button size="1" variant="surface" color="gray" disabled={!user.department_id} style={{ width: '100%', justifyContent: 'space-between', borderRadius: 8 }}>
                                                        <Flex align="center" gap="1" style={{ overflow: 'hidden', minWidth: 0, flex: 1 }}>
                                                            <BackpackIcon style={{ flexShrink: 0, width: 13, height: 13 }} />
                                                            <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{!user.department_id ? 'Select dept first' : (user.designation_name || (filtDesignations.length ? 'Select…' : 'No designations yet'))}</Text>
                                                        </Flex>
                                                        <Text size="1" color="gray" style={{ flexShrink: 0, marginLeft: 2 }}>▾</Text>
                                                    </Button>
                                                </DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    {filtDesignations.length === 0 && <DropdownMenu.Item disabled>No designations in this department</DropdownMenu.Item>}
                                                    {filtDesignations.map(desig => <DropdownMenu.Item key={desig.id} onSelect={() => handleDesignationChange(user.id, desig.id)}>{desig.title}</DropdownMenu.Item>)}
                                                </DropdownMenu.Content>
                                            </DropdownMenu.Root>
                                            )}
                                        </Box>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="1" wrap="wrap" style={{ minWidth: 100, maxWidth: 130 }}>
                                            {(user.roles || []).map(r => (
                                                <Badge key={r.id || r.name} size="1" variant="soft" color="blue" style={{ borderRadius: 999, padding: '2px 8px' }}>
                                                    {r.name}
                                                </Badge>
                                            ))}
                                            {(!user.roles || user.roles.length === 0) && (
                                                <Text size="1" color="gray">—</Text>
                                            )}
                                            {user.can && !user.can.manage_access && <LockedFieldHint row={user} field="role" />}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color={!user.deleted_at ? 'jade' : 'red'} style={{ borderRadius: 999, fontWeight: 700, padding: '2px 8px' }}>
                                            {!user.deleted_at ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </Table.Cell>
                                    {!isMobile && !isTablet && (
                                        <Table.Cell>
                                            <Box style={{ minWidth: 160, maxWidth: 190 }}>
                                                {!canOn(user, 'placement', 'employees.placement.update') ? (
                                                    <LockedCell show={!!user.can} row={user} field="work location"><Text size="1" color="gray">{user.work_location_name || 'Unassigned'}</Text></LockedCell>
                                                ) : (
                                                <Select.Root size="1" value={user.work_location_id ? String(user.work_location_id) : 'none'} onValueChange={(v) => handleWorkLocationChange(user.id, v === 'none' ? null : parseInt(v))}>
                                                    <Select.Trigger style={{ width: '100%', borderRadius: 8, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} placeholder="Unassigned" />
                                                    <Select.Content>
                                                        <Select.Item value="none">Unassigned / Remote</Select.Item>
                                                        {workLocations?.map(loc => <Select.Item key={loc.id} value={String(loc.id)}>{loc.name}</Select.Item>)}
                                                    </Select.Content>
                                                </Select.Root>
                                                )}
                                                {!user.has_attendance_override && user.work_location_attendance_type_name && (
                                                    <Text size="1" color="gray" mt="1" as="div" style={{ fontStyle: 'italic', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontSize: 10 }}>
                                                        Inherits: {user.work_location_attendance_type_name}
                                                    </Text>
                                                )}
                                            </Box>
                                        </Table.Cell>
                                    )}
                                    {!isMobile && !isTablet && (
                                        <Table.Cell>
                                            <Box style={{ minWidth: 170, maxWidth: 200 }}>
                                                {!canOn(user, 'attendance_config', 'employees.attendance-config.update') ? (
                                                    <LockedCell show={!!user.can} row={user} field="attendance method">
                                                    <Text size="1" color="gray">
                                                        {user.attendance_type_name || (user.work_location_attendance_type_name ? `${user.work_location_attendance_type_name} (inherited)` : '—')}
                                                    </Text>
                                                    </LockedCell>
                                                ) : (<>
                                                <DropdownMenu.Root>
                                                    <DropdownMenu.Trigger>
                                                        <Button size="1" variant="surface" color="gray" style={{ width: '100%', justifyContent: 'space-between', borderRadius: 8 }}>
                                                            <Flex align="center" gap="1" style={{ overflow: 'hidden', minWidth: 0, flex: 1 }}>
                                                                <ClockIcon style={{ flexShrink: 0, width: 13, height: 13 }} />
                                                                <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                    {user.attendance_type_name || (user.has_attendance_override ? 'Select…' : (user.work_location_attendance_type_name ? `${user.work_location_attendance_type_name} (inherited)` : 'Select…'))}
                                                                </Text>
                                                            </Flex>
                                                            <Text size="1" color="gray" style={{ flexShrink: 0, marginLeft: 2 }}>▾</Text>
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
                                                            <Select.Trigger style={{ width: '100%', borderRadius: 8, fontSize: 11 }} placeholder={rowDevices.length ? 'Select device…' : 'No devices configured'} />
                                                            <Select.Content>
                                                                {rowDevices.map(device => <Select.Item key={device.id} value={String(device.id)}>{device.name}</Select.Item>)}
                                                            </Select.Content>
                                                        </Select.Root>
                                                    </Box>
                                                )}
                                                </>)}
                                                {user.attendance_method_missing && !user.has_attendance_override && !user.attendance_type_name && !user.work_location_attendance_type_name && !user.deleted_at && (
                                                    <Badge color="amber" variant="soft" size="1" mt="1" data-testid="no-attendance-method-badge" title="This employee cannot check in until a method is assigned">
                                                        No check-in method
                                                    </Badge>
                                                )}
                                            </Box>
                                        </Table.Cell>
                                    )}
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Box style={{ minWidth: 145, maxWidth: 175 }}>
                                                {!canOn(user, 'placement', 'employees.placement.update') ? (
                                                    <LockedCell show={!!user.can} row={user} field="reporting line"><Text size="1" color="gray">{user.reports_to?.name || '—'}</Text></LockedCell>
                                                ) : (
                                                <Select.Root size="1" value={user.report_to ? String(user.report_to) : ''} onValueChange={(v) => debouncedUpdateReportTo(user.id, v || null)}>
                                                    <Select.Trigger style={{ width: '100%', borderRadius: 8, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} placeholder={user.reports_to?.name || 'Select manager…'} />
                                                    <Select.Content>
                                                        {getEligibleManagers(user, user.report_to).map(mgr => <Select.Item key={mgr.id} value={String(mgr.id)}>{mgr.name}</Select.Item>)}
                                                    </Select.Content>
                                                </Select.Root>
                                                )}
                                            </Box>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        <Flex justify="center">
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger><IconButton size="1" variant="ghost" color="gray"><DotsVerticalIcon /></IconButton></DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    <DropdownMenu.Item asChild><Link href={route('profile', { user: user.id })}><Flex gap="2"><Pencil1Icon />Full Profile</Flex></Link></DropdownMenu.Item>
                                                    {canOn(user, 'view_compensation', 'employees.compensation.view') && !user.can?.is_self && (
                                                        <DropdownMenu.Item asChild>
                                                            <Link href={route('profile', { user: user.id, tab: 'employment' })}>
                                                                <Flex gap="2"><IdCardIcon />{canOn(user, 'update_compensation', 'employees.compensation.update') ? 'Salary & Compensation' : 'View Compensation'}</Flex>
                                                            </Link>
                                                        </DropdownMenu.Item>
                                                    )}
                                                    {(canOn(user, 'update', 'employees.update') || canOn(user, 'manage_access', 'employees.access.manage') || canOn(user, 'reset_password', 'employees.password.reset') || canOn(user, 'manage_devices', 'employees.devices.manage')) && <DropdownMenu.Separator />}
                                                    {canOn(user, 'update', 'employees.update') && (
                                                        <DropdownMenu.Item onSelect={() => openEditDialog(user, 'profile')}>
                                                            <Flex gap="2"><Pencil1Icon />Edit Profile</Flex>
                                                        </DropdownMenu.Item>
                                                    )}
                                                    {canOn(user, 'manage_access', 'employees.access.manage') && (
                                                        <DropdownMenu.Item onSelect={() => openEditDialog(user, 'access')}>
                                                            <Flex gap="2"><LockClosedIcon />Manage Access</Flex>
                                                        </DropdownMenu.Item>
                                                    )}
                                                    {canOn(user, 'reset_password', 'employees.password.reset') && (
                                                        <DropdownMenu.Item onSelect={() => openPwDialog(user)}>
                                                            <Flex gap="2"><LockClosedIcon />Reset Password</Flex>
                                                        </DropdownMenu.Item>
                                                    )}
                                                    {canOn(user, 'manage_devices', 'employees.devices.manage') && (
                                                        <>
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
                                                    {(user.deleted_at ? canOn(user, 'restore', 'employees.restore') : canOn(user, 'delete', 'employees.delete')) && <DropdownMenu.Separator />}
                                                    {user.deleted_at ? (
                                                        canOn(user, 'restore', 'employees.restore') && (
                                                            <DropdownMenu.Item color="green" onSelect={() => handleRestoreClick(user)}>
                                                                <Flex gap="2"><ReloadIcon />Restore</Flex>
                                                            </DropdownMenu.Item>
                                                        )
                                                    ) : (
                                                        canOn(user, 'delete', 'employees.delete') && (
                                                            <DropdownMenu.Item color="red" onSelect={() => handleDeleteClick(user)}>
                                                                <Flex gap="2"><TrashIcon />Delete</Flex>
                                                            </DropdownMenu.Item>
                                                        )
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
                    biometricDevices={biometricDevices}
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
                        Set a temporary password for this user. They can sign in with it on web and mobile, and will be asked to choose their own at first sign-in.
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
