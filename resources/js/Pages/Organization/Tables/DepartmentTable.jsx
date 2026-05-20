import React from 'react';
import { usePage } from "@inertiajs/react";
import {
    Table, Badge, Tooltip, IconButton, DropdownMenu,
    Button, Flex, Text, Box, Spinner
} from '@radix-ui/themes';
import {
    Pencil1Icon, TrashIcon, EyeOpenIcon,
    HomeIcon, PersonIcon, SewingPinIcon,
    CalendarIcon, CheckCircledIcon, CrossCircledIcon,
    DotsVerticalIcon
} from '@radix-ui/react-icons';
import dayjs from 'dayjs';
import TablePagination from '../../../Components/TablePagination.jsx';
import ProfileAvatar from '../../../Components/Profile/ProfileAvatar.jsx';

const DepartmentTable = ({
    departments, onEdit, onDelete, onView, loading,
    isMobile, isTablet, pagination, onPageChange, onRowsPerPageChange,
    canEditDepartment = false, canDeleteDepartment = false
}) => {
    const { auth } = usePage().props;
    const hasEditPermission = canEditDepartment || auth.permissions?.includes('departments.update') || false;
    const hasDeletePermission = canDeleteDepartment || auth.permissions?.includes('departments.delete') || false;

    // Pagination bounds
    const totalRows = departments?.total || 0;
    const startRow = ((pagination.currentPage - 1) * pagination.perPage) + 1;
    const endRow = Math.min(pagination.currentPage * pagination.perPage, totalRows);

    if (!loading && (!departments?.data || departments.data.length === 0)) {
        return (
            <Flex direction="column" align="center" justify="center" py="9" gap="2">
                <HomeIcon style={{ width: 40, height: 40, color: 'var(--gray-8)' }} />
                <Text size="3" weight="medium">No departments found</Text>
                <Text size="2" color="gray">Try adjusting your search or filters.</Text>
            </Flex>
        );
    }

    return (
        <Box style={{ overflowX: 'auto' }}>
            <Table.Root variant="surface" size={isMobile ? '1' : '2'}>
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Department</Table.ColumnHeaderCell>
                        {!isMobile && <Table.ColumnHeaderCell>Code</Table.ColumnHeaderCell>}
                        <Table.ColumnHeaderCell>Manager</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Employees</Table.ColumnHeaderCell>
                        {!isMobile && <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>}
                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                        {!isMobile && !isTablet && <Table.ColumnHeaderCell>Established</Table.ColumnHeaderCell>}
                        <Table.ColumnHeaderCell justify="end">Actions</Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {loading ? (
                        <Table.Row>
                            <Table.Cell colSpan={8}>
                                <Flex justify="center" py="8"><Spinner size="3" /></Flex>
                            </Table.Cell>
                        </Table.Row>
                    ) : (
                        departments.data.map(department => (
                            <Table.Row key={department.id} align="center">
                                {/* Name */}
                                <Table.Cell>
                                    <Flex align="center" gap="3">
                                        <Box p="2" style={{ background: 'var(--blue-a3)', borderRadius: 'var(--radius-2)' }}>
                                            <HomeIcon style={{ color: 'var(--blue-9)' }} />
                                        </Box>
                                        <Box>
                                            <Text weight="bold" size="2" as="div">{department.name}</Text>
                                            <Text size="1" color="gray">
                                                {department.parent ? department.parent.name : 'Top-level'}
                                            </Text>
                                        </Box>
                                    </Flex>
                                </Table.Cell>

                                {/* Code */}
                                {!isMobile && (
                                    <Table.Cell>
                                        {department.code ? (
                                            <Badge color="blue" variant="soft" size="1">{department.code}</Badge>
                                        ) : <Text color="gray" size="2">—</Text>}
                                    </Table.Cell>
                                )}

                                {/* Manager */}
                                <Table.Cell>
                                    {department.manager ? (
                                        <Flex align="center" gap="2">
                                            <ProfileAvatar src={department.manager.profile_image_url || department.manager.profile_image} name={department.manager.name} size="1" />
                                            <Box>
                                                <Text size="2" weight="medium" as="div">{department.manager.name}</Text>
                                                {!isMobile && department.manager.email && (
                                                    <Text size="1" color="gray">{department.manager.email}</Text>
                                                )}
                                            </Box>
                                        </Flex>
                                    ) : (
                                        <Text size="2" color="gray">Not assigned</Text>
                                    )}
                                </Table.Cell>

                                {/* Employees */}
                                <Table.Cell>
                                    <Flex align="center" gap="2">
                                        <PersonIcon color="var(--gray-9)" />
                                        <Text size="2">{department.employee_count || 0}</Text>
                                    </Flex>
                                </Table.Cell>

                                {/* Location */}
                                {!isMobile && (
                                    <Table.Cell>
                                        {department.location ? (
                                            <Flex align="center" gap="2">
                                                <SewingPinIcon color="var(--gray-9)" />
                                                <Text size="2">{department.location}</Text>
                                            </Flex>
                                        ) : <Text color="gray" size="2">—</Text>}
                                    </Table.Cell>
                                )}

                                {/* Status */}
                                <Table.Cell>
                                    <Badge color={department.is_active ? 'green' : 'red'} variant={department.is_active ? 'solid' : 'soft'} size="1">
                                        {department.is_active ? <CheckCircledIcon /> : <CrossCircledIcon />}
                                        {department.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </Table.Cell>

                                {/* Established Date */}
                                {!isMobile && !isTablet && (
                                    <Table.Cell>
                                        {department.established_date ? (
                                            <Flex align="center" gap="2">
                                                <CalendarIcon color="var(--gray-9)" />
                                                <Text size="2">{dayjs(department.established_date).format('MMM D, YYYY')}</Text>
                                            </Flex>
                                        ) : <Text color="gray" size="2">—</Text>}
                                    </Table.Cell>
                                )}

                                {/* Actions */}
                                <Table.Cell justify="end">
                                    {!isMobile ? (
                                        <Flex gap="3" justify="end" align="center">
                                            <Tooltip content="View Department">
                                                <IconButton size="1" variant="ghost" color="gray" onClick={() => onView(department)}>
                                                    <EyeOpenIcon />
                                                </IconButton>
                                            </Tooltip>
                                            {hasEditPermission && (
                                                <Tooltip content="Edit Department">
                                                    <IconButton size="1" variant="ghost" color="blue" onClick={() => onEdit(department)}>
                                                        <Pencil1Icon />
                                                    </IconButton>
                                                </Tooltip>
                                            )}
                                            {hasDeletePermission && (
                                                <Tooltip content={department.employee_count > 0 ? "Cannot delete department with employees" : "Delete Department"}>
                                                    <IconButton size="1" variant="ghost" color="red" disabled={department.employee_count > 0} onClick={() => onDelete(department)}>
                                                        <TrashIcon />
                                                    </IconButton>
                                                </Tooltip>
                                            )}
                                        </Flex>
                                    ) : (
                                        <DropdownMenu.Root>
                                            <DropdownMenu.Trigger>
                                                <IconButton size="1" variant="ghost" color="gray"><DotsVerticalIcon /></IconButton>
                                            </DropdownMenu.Trigger>
                                            <DropdownMenu.Content align="end">
                                                <DropdownMenu.Item onClick={() => onView(department)}><EyeOpenIcon /> View Details</DropdownMenu.Item>
                                                {hasEditPermission && <DropdownMenu.Item onClick={() => onEdit(department)}><Pencil1Icon /> Edit Department</DropdownMenu.Item>}
                                                {hasDeletePermission && <DropdownMenu.Item color="red" disabled={department.employee_count > 0} onClick={() => onDelete(department)}><TrashIcon /> Delete Department</DropdownMenu.Item>}
                                            </DropdownMenu.Content>
                                        </DropdownMenu.Root>
                                    )}
                                </Table.Cell>
                            </Table.Row>
                        ))
                    )}
                </Table.Body>
            </Table.Root>

            <TablePagination
                pagination={{ ...pagination, total: departments?.total || 0 }}
                onPageChange={onPageChange}
                onRowsPerPageChange={onRowsPerPageChange}
                loading={loading}
            />
        </Box>
    );
};

export default DepartmentTable;