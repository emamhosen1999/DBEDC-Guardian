import React, { useState, useMemo, useCallback, useRef } from "react";
import { Link, router } from '@inertiajs/react';
import { showToast } from "@/utils/toastUtils";
import axios from 'axios';
import {
    Avatar,
    Badge,
    Box,
    Button,
    DropdownMenu,
    Flex,
    IconButton,
    Select,
    Separator,
    Spinner,
    Table,
    Text,
} from '@radix-ui/themes';
import {
    BackpackIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ClockIcon,
    DotsVerticalIcon,
    EnvelopeClosedIcon,
    HomeIcon,
    MobileIcon,
    Pencil1Icon,
    PersonIcon,
    TrashIcon,
    ExclamationTriangleIcon,
} from '@radix-ui/react-icons';
import DeleteEmployeeModal from '@/Components/DeleteEmployeeModal';
import ProfilePictureModal from '@/Components/ProfilePictureModal';
import ProfileAvatar, { getProfileAvatarTokens } from '@/Components/ProfileAvatar';

/* ─── helpers ─── */
function getBaseSlug(slug) {
    if (!slug) return '';
    return slug.replace(/_\d+$/, '');
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

/* ─── Main Table Component ─── */
const EmployeeTable = ({
    employees: allUsers = [],
    allManagers = [],
    departments,
    designations,
    attendanceTypes,
    isMobile,
    isTablet,
    pagination,
    totalRows = 0,
    loading = false,
    updateEmployeeOptimized,
    deleteEmployeeOptimized,
    onPageChange,
    onRowsPerPageChange,
}) => {
    /* ── modal state ── */
    const [deleteModalOpen, setDeleteModalOpen] = useState(false);
    const [employeeToDelete, setEmployeeToDelete] = useState(null);
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [profilePictureModal, setProfilePictureModal] = useState({ isOpen: false, employee: null });

    /* ── debounce ref for report_to ── */
    const reportToDebounceRef = useRef({});

    /* ── grouped attendance types ── */
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
            await axios.put(route('users.update-department', { id: userId }), { department_id: departmentId });
            const dept = departments.find(d => d.id === parseInt(departmentId)) || null;
            updateEmployeeOptimized?.(userId, { department_id: departmentId, department_name: dept?.name || null, designation_id: null, designation_name: null });
            showToast.success('Department updated');
        } catch {
            showToast.error('Failed to update department');
        }
    };

    const handleDesignationChange = async (userId, designationId) => {
        try {
            await axios.post(route('users.updateDesignation', { id: userId }), { designation_id: designationId });
            const desig = designations.find(d => d.id === parseInt(designationId)) || null;
            updateEmployeeOptimized?.(userId, { designation_id: designationId, designation_name: desig?.title || null });
            showToast.success('Designation updated');
        } catch {
            showToast.error('Failed to update designation');
        }
    };

    const handleAttendanceTypeChange = async (userId, attendanceTypeId) => {
        try {
            await axios.post(route('users.updateAttendanceType', { id: userId }), { attendance_type_id: attendanceTypeId });
            const type = attendanceTypes.find(t => t.id === parseInt(attendanceTypeId)) || null;
            const devices = (type?.biometric_devices ?? []).map(d => ({
                id: d.id, name: d.name, serial_number: d.serial_number, location: d.location,
            }));
            updateEmployeeOptimized?.(userId, {
                attendance_type_id: attendanceTypeId,
                attendance_type_name: type?.name || null,
                attendance_type_devices: devices,
                biometric_device_id: null,
            });
            showToast.success('Attendance type updated');
        } catch {
            showToast.error('Failed to update attendance type');
        }
    };

    const handleBiometricDeviceChange = async (userId, deviceId) => {
        try {
            const { data } = await axios.post(route('users.updateBiometricDevice', { id: userId }), {
                biometric_device_id: deviceId || null,
            });
            updateEmployeeOptimized?.(userId, {
                biometric_device_id: data.biometric_device_id ?? null,
                biometric_device_name: data.biometric_device_name ?? null,
            });
            showToast.success(data.message || 'Device assigned');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to assign device');
        }
    };

    const debouncedUpdateReportTo = useCallback((userId, reportToId) => {
        if (reportToDebounceRef.current[userId]) clearTimeout(reportToDebounceRef.current[userId]);
        reportToDebounceRef.current[userId] = setTimeout(async () => {
            try {
                const { data } = await axios.post(route('users.updateReportTo', { id: userId }), { report_to: reportToId || null });
                updateEmployeeOptimized?.(userId, { report_to: reportToId || null, reports_to: data.user?.reports_to || null });
                showToast.success('Manager assigned');
            } catch {
                showToast.error('Failed to update manager');
            }
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

    /* ── delete ── */
    const handleDeleteClick = (user) => { setEmployeeToDelete(user); setDeleteModalOpen(true); };
    const handleDeleteCancel = () => { setDeleteModalOpen(false); setEmployeeToDelete(null); };
    const handleDeleteConfirm = async () => {
        if (!employeeToDelete) return;
        setDeleteLoading(true);
        try {
            await axios.delete(route('user.delete', { id: employeeToDelete.id }));
            deleteEmployeeOptimized?.(employeeToDelete.id);
            setDeleteModalOpen(false);
            setEmployeeToDelete(null);
            showToast.success('Employee deleted');
        } catch (err) {
            const msg = err.response?.status === 403 ? 'Permission denied'
                : err.response?.status === 404 ? 'Employee not found'
                : err.response?.data?.error || 'Failed to delete employee';
            showToast.error(msg);
        } finally {
            setDeleteLoading(false);
        }
    };

    /* ── profile picture ── */
    const handleProfilePictureClick = (emp) => setProfilePictureModal({ isOpen: true, employee: emp });
    const handleProfilePictureClose = () => setProfilePictureModal({ isOpen: false, employee: null });
    const handleImageUpdate = (id, url) => updateEmployeeOptimized?.(id, { profile_image_url: url });

    /* ── pagination ── */
    const totalPages = Math.ceil(pagination.total / pagination.perPage);
    const startRow = ((pagination.currentPage - 1) * pagination.perPage) + 1;
    const endRow = Math.min(pagination.currentPage * pagination.perPage, pagination.total);

    /* ─────────────────────── RENDER ─────────────────────── */
    return (
        <Box style={{ position: 'relative', overflow: 'hidden' }}>
            {/* Loading overlay */}
            {loading && (
                <Flex
                    align="center"
                    justify="center"
                    style={{
                        position: 'absolute',
                        inset: 0,
                        zIndex: 10,
                        background: 'var(--color-overlay)',
                    }}
                >
                    <Flex direction="column" align="center" gap="3" p="6">
                        <Spinner size="3" />
                        <Text size="2" color="gray">Loading employees…</Text>
                    </Flex>
                </Flex>
            )}

            {/* Scrollable table */}
            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface" size={isMobile ? '1' : '2'}>
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell style={{ width: 48 }}>#</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Contact</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell>Department</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Designation</Table.ColumnHeaderCell>
                            {!isMobile && !isTablet && <Table.ColumnHeaderCell>Attendance Type</Table.ColumnHeaderCell>}
                            {!isMobile && <Table.ColumnHeaderCell>Reports To</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell style={{ width: 56, textAlign: 'center' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>

                    <Table.Body>
                        {allUsers.length === 0 && !loading ? (
                            <Table.Row>
                                <Table.Cell colSpan={8}>
                                    <Flex direction="column" align="center" justify="center" py="8" gap="2">
                                        <PersonIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                                        <Text size="3" weight="medium">No employees found</Text>
                                        <Text size="2" color="gray">Try adjusting your search or filter criteria.</Text>
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                        ) : allUsers.map((user, idx) => {
                            const serialNumber = startRow + idx;
                            const deptId = user.department_id;
                            const filtDesignations = designations?.filter(d => d.department_id === parseInt(deptId)) || [];
                            const biometricType = attendanceTypes?.find(t => t.slug === 'biometric');
                            const isBiometricSelected = biometricType && parseInt(user.attendance_type_id) === biometricType.id;
                            const eligibleManagers = getEligibleManagers(user, user.report_to);
                            const currentReportsTo = user.reports_to;

                            return (
                                <Table.Row key={user.id}>
                                    {/* # */}
                                    <Table.Cell>
                                        <Flex align="center" justify="center">
                                            <Text size="1" color="gray" weight="medium">{serialNumber}</Text>
                                        </Flex>
                                    </Table.Cell>

                                    {/* Employee */}
                                    <Table.Cell>
                                        <Flex align="center" gap="3">
                                            <Box style={{ flexShrink: 0, cursor: 'pointer' }} onClick={() => handleProfilePictureClick(user)}>
                                                <ProfileAvatar
                                                    src={user?.profile_image_url || user?.profile_image}
                                                    name={user?.name}
                                                    size={isMobile ? 'sm' : 'md'}
                                                />
                                            </Box>
                                            <Box>
                                                <Text weight="bold" size="2" as="div" style={{ whiteSpace: 'nowrap' }}>
                                                    {user?.name}
                                                </Text>
                                                <Text size="1" color="gray" as="div">ID: {user?.employee_id || 'N/A'}</Text>
                                                {isMobile && (
                                                    <Flex direction="column" gap="1" mt="1">
                                                        <Flex align="center" gap="1">
                                                            <EnvelopeClosedIcon style={{ width: 11, height: 11, color: 'var(--gray-9)' }} />
                                                            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 140 }}>
                                                                {user?.email}
                                                            </Text>
                                                        </Flex>
                                                        {user?.phone && (
                                                            <Flex align="center" gap="1">
                                                                <MobileIcon style={{ width: 11, height: 11, color: 'var(--gray-9)' }} />
                                                                <Text size="1" color="gray">{user.phone}</Text>
                                                            </Flex>
                                                        )}
                                                    </Flex>
                                                )}
                                            </Box>
                                        </Flex>
                                    </Table.Cell>

                                    {/* Contact — desktop only */}
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Flex direction="column" gap="1">
                                                <Flex align="center" gap="2">
                                                    <EnvelopeClosedIcon style={{ width: 13, height: 13, color: 'var(--gray-9)', flexShrink: 0 }} />
                                                    <Text size="1" color="gray" style={{ whiteSpace: 'nowrap' }}>{user?.email}</Text>
                                                </Flex>
                                                {user?.phone && (
                                                    <Flex align="center" gap="2">
                                                        <MobileIcon style={{ width: 13, height: 13, color: 'var(--gray-9)', flexShrink: 0 }} />
                                                        <Text size="1" color="gray">{user.phone}</Text>
                                                    </Flex>
                                                )}
                                            </Flex>
                                        </Table.Cell>
                                    )}

                                    {/* Department */}
                                    <Table.Cell>
                                        <Box style={{ minWidth: 140 }}>
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger>
                                                    <Button
                                                        size="1"
                                                        variant="surface"
                                                        color="gray"
                                                        style={{ width: '100%', justifyContent: 'space-between', minWidth: 140 }}
                                                    >
                                                        <Flex align="center" gap="1" style={{ flex: 1, overflow: 'hidden' }}>
                                                            <HomeIcon style={{ width: 12, height: 12, flexShrink: 0 }} />
                                                            <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                {user.department_name || 'Select…'}
                                                            </Text>
                                                        </Flex>
                                                        <Text size="1" color="gray">▾</Text>
                                                    </Button>
                                                </DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    {departments?.map(dept => (
                                                        <DropdownMenu.Item
                                                            key={dept.id}
                                                            onSelect={() => handleDepartmentChange(user.id, dept.id)}
                                                        >
                                                            {dept.name}
                                                        </DropdownMenu.Item>
                                                    ))}
                                                </DropdownMenu.Content>
                                            </DropdownMenu.Root>
                                        </Box>
                                    </Table.Cell>

                                    {/* Designation */}
                                    <Table.Cell>
                                        <Box style={{ minWidth: 140 }}>
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger>
                                                    <Button
                                                        size="1"
                                                        variant="surface"
                                                        color="gray"
                                                        disabled={!deptId}
                                                        style={{ width: '100%', justifyContent: 'space-between', minWidth: 140, opacity: !deptId ? 0.5 : 1 }}
                                                    >
                                                        <Flex align="center" gap="1" style={{ flex: 1, overflow: 'hidden' }}>
                                                            <BackpackIcon style={{ width: 12, height: 12, flexShrink: 0 }} />
                                                            <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                {!deptId ? 'Select dept first' : (user.designation_name || 'Select…')}
                                                            </Text>
                                                        </Flex>
                                                        {deptId && <Text size="1" color="gray">▾</Text>}
                                                    </Button>
                                                </DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    {filtDesignations.length === 0 ? (
                                                        <DropdownMenu.Item disabled>No designations</DropdownMenu.Item>
                                                    ) : filtDesignations.map(desig => (
                                                        <DropdownMenu.Item
                                                            key={desig.id}
                                                            onSelect={() => handleDesignationChange(user.id, desig.id)}
                                                        >
                                                            {desig.title}
                                                        </DropdownMenu.Item>
                                                    ))}
                                                </DropdownMenu.Content>
                                            </DropdownMenu.Root>
                                        </Box>
                                    </Table.Cell>

                                    {/* Attendance Type — desktop only */}
                                    {!isMobile && !isTablet && (
                                        <Table.Cell>
                                            <Box style={{ minWidth: 160 }}>
                                                <DropdownMenu.Root>
                                                    <DropdownMenu.Trigger>
                                                        <Button
                                                            size="1"
                                                            variant="surface"
                                                            color="gray"
                                                            style={{ width: '100%', justifyContent: 'space-between', minWidth: 160 }}
                                                        >
                                                            <Flex align="center" gap="1" style={{ flex: 1, overflow: 'hidden' }}>
                                                                <ClockIcon style={{ width: 12, height: 12, flexShrink: 0 }} />
                                                                <Text size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                                    {user.attendance_type_name || 'Select…'}
                                                                </Text>
                                                            </Flex>
                                                            <Text size="1" color="gray">▾</Text>
                                                        </Button>
                                                    </DropdownMenu.Trigger>
                                                    <DropdownMenu.Content size="1">
                                                        {groupedAttendanceTypes.length === 0 ? (
                                                            <DropdownMenu.Item disabled>No configured types</DropdownMenu.Item>
                                                        ) : groupedAttendanceTypes.map((cat, ci) => (
                                                            <React.Fragment key={cat.slug}>
                                                                {ci > 0 && <DropdownMenu.Separator />}
                                                                <DropdownMenu.Label>{cat.label}</DropdownMenu.Label>
                                                                {cat.types.map(type => (
                                                                    <DropdownMenu.Item
                                                                        key={type.id}
                                                                        onSelect={() => handleAttendanceTypeChange(user.id, type.id)}
                                                                    >
                                                                        {type.name}
                                                                    </DropdownMenu.Item>
                                                                ))}
                                                            </React.Fragment>
                                                        ))}
                                                    </DropdownMenu.Content>
                                                </DropdownMenu.Root>

                                                {/* Per-employee device picker — required when biometric AT selected */}
                                                {isBiometricSelected && (
                                                    <Box mt="1">
                                                        {user.attendance_type_devices?.length > 0 ? (
                                                            <>
                                                                <Select.Root
                                                                    size="1"
                                                                    value={user.biometric_device_id ? String(user.biometric_device_id) : ''}
                                                                    onValueChange={(v) => handleBiometricDeviceChange(user.id, v ? parseInt(v) : null)}
                                                                >
                                                                    <Select.Trigger
                                                                        style={{
                                                                            width: '100%',
                                                                            outline: !user.biometric_device_id ? '1px solid var(--red-8)' : undefined,
                                                                        }}
                                                                        placeholder="Select device…"
                                                                    />
                                                                    <Select.Content>
                                                                        {user.attendance_type_devices.map(device => (
                                                                            <Select.Item key={device.id} value={String(device.id)}>
                                                                                {device.name}{device.location ? ` (${device.location})` : ''}
                                                                            </Select.Item>
                                                                        ))}
                                                                    </Select.Content>
                                                                </Select.Root>
                                                                {!user.biometric_device_id && (
                                                                    <Badge color="red" variant="soft" size="1" mt="1">Device required</Badge>
                                                                )}
                                                            </>
                                                        ) : (
                                                            <Badge color="orange" variant="soft" size="1">No devices in pool</Badge>
                                                        )}
                                                    </Box>
                                                )}
                                            </Box>
                                        </Table.Cell>
                                    )}

                                    {/* Reports To — desktop only */}
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Box style={{ minWidth: 160 }}>
                                                {eligibleManagers.length === 0 ? (
                                                    <Text size="1" color="gray">No eligible managers</Text>
                                                ) : (
                                                    <Select.Root
                                                        size="1"
                                                        value={user.report_to ? String(user.report_to) : ''}
                                                        onValueChange={(v) => {
                                                            const id = v ? parseInt(v) : null;
                                                            if (id !== user.report_to) debouncedUpdateReportTo(user.id, id);
                                                        }}
                                                    >
                                                        <Select.Trigger
                                                            style={{ width: '100%' }}
                                                            placeholder={currentReportsTo ? currentReportsTo.name : 'Select manager…'}
                                                        />
                                                        <Select.Content>
                                                            {eligibleManagers
                                                                .sort((a, b) => (a.designation_hierarchy_level ?? 999) - (b.designation_hierarchy_level ?? 999))
                                                                .map(mgr => (
                                                                    <Select.Item key={mgr.id} value={String(mgr.id)}>
                                                                        {mgr.name}
                                                                        {mgr.designation_name ? ` — ${mgr.designation_name}` : ''}
                                                                    </Select.Item>
                                                                ))}
                                                        </Select.Content>
                                                    </Select.Root>
                                                )}
                                            </Box>
                                        </Table.Cell>
                                    )}

                                    {/* Actions */}
                                    <Table.Cell>
                                        <Flex justify="center">
                                            <DropdownMenu.Root>
                                                <DropdownMenu.Trigger>
                                                    <IconButton size="1" variant="ghost" color="gray" aria-label="Actions">
                                                        <DotsVerticalIcon style={{ width: 16, height: 16 }} />
                                                    </IconButton>
                                                </DropdownMenu.Trigger>
                                                <DropdownMenu.Content size="1">
                                                    <DropdownMenu.Item asChild>
                                                        <Link href={route('profile', { user: user.id })}>
                                                            <Flex align="center" gap="2">
                                                                <Pencil1Icon style={{ width: 13, height: 13 }} />
                                                                Edit Profile
                                                            </Flex>
                                                        </Link>
                                                    </DropdownMenu.Item>
                                                    <DropdownMenu.Separator />
                                                    <DropdownMenu.Item
                                                        color="red"
                                                        onSelect={() => handleDeleteClick(user)}
                                                    >
                                                        <Flex align="center" gap="2">
                                                            <TrashIcon style={{ width: 13, height: 13 }} />
                                                            Delete
                                                        </Flex>
                                                    </DropdownMenu.Item>
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

            {/* ── Pagination Footer ── */}
            {pagination && !loading && pagination.total > 0 && (
                <Flex
                    align="center"
                    justify="between"
                    pt="3"
                    mt="2"
                    style={{ borderTop: '1px solid var(--gray-a4)' }}
                    wrap="wrap"
                    gap="3"
                >
                    {/* Rows per page */}
                    <Flex align="center" gap="2">
                        <Text size="1" color="gray">Rows per page</Text>
                        <Select.Root
                            size="1"
                            value={String(pagination.perPage)}
                            onValueChange={(v) => onRowsPerPageChange?.(parseInt(v))}
                        >
                            <Select.Trigger />
                            <Select.Content>
                                {[10, 20, 30, 50].map(n => (
                                    <Select.Item key={n} value={String(n)}>{n}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Flex>

                    {/* Info + nav */}
                    <Flex align="center" gap="3">
                        <Text size="1" color="gray">
                            {startRow}–{endRow} of {pagination.total}
                        </Text>
                        <Flex gap="1">
                            <IconButton
                                size="1"
                                variant="soft"
                                color="gray"
                                disabled={pagination.currentPage <= 1}
                                onClick={() => onPageChange?.(pagination.currentPage - 1)}
                                aria-label="Previous page"
                            >
                                <ChevronLeftIcon />
                            </IconButton>
                            {/* Page number pills */}
                            {Array.from({ length: Math.min(totalPages, 5) }, (_, i) => {
                                let page;
                                if (totalPages <= 5) {
                                    page = i + 1;
                                } else if (pagination.currentPage <= 3) {
                                    page = i + 1;
                                } else if (pagination.currentPage >= totalPages - 2) {
                                    page = totalPages - 4 + i;
                                } else {
                                    page = pagination.currentPage - 2 + i;
                                }
                                return (
                                    <Button
                                        key={page}
                                        size="1"
                                        variant={page === pagination.currentPage ? 'solid' : 'soft'}
                                        color={page === pagination.currentPage ? 'indigo' : 'gray'}
                                        onClick={() => onPageChange?.(page)}
                                    >
                                        {page}
                                    </Button>
                                );
                            })}
                            <IconButton
                                size="1"
                                variant="soft"
                                color="gray"
                                disabled={pagination.currentPage >= totalPages}
                                onClick={() => onPageChange?.(pagination.currentPage + 1)}
                                aria-label="Next page"
                            >
                                <ChevronRightIcon />
                            </IconButton>
                        </Flex>
                    </Flex>
                </Flex>
            )}

            {/* ── Modals ── */}
            <DeleteEmployeeModal
                open={deleteModalOpen}
                onClose={handleDeleteCancel}
                employee={employeeToDelete}
                onConfirm={handleDeleteConfirm}
                loading={deleteLoading}
            />
            <ProfilePictureModal
                isOpen={profilePictureModal.isOpen}
                onClose={handleProfilePictureClose}
                employee={profilePictureModal.employee}
                onImageUpdate={handleImageUpdate}
            />
        </Box>
    );
};

export default EmployeeTable;