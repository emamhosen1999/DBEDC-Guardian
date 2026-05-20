import React from 'react';
import { usePage } from "@inertiajs/react";
import {
    Table, Badge, Tooltip, IconButton, DropdownMenu,
    Button, Flex, Text, Box
} from '@radix-ui/themes';
import {
    Pencil1Icon, TrashIcon, PersonIcon,
    CheckCircledIcon, CrossCircledIcon, DotsVerticalIcon
} from '@radix-ui/react-icons';
import NoDataMessage from '@/Components/NoDataMessage';
import TablePagination from '@/Components/TablePagination.jsx';

const DesignationTable = ({
    designations,
    onEdit,
    onDelete,
    loading,
    isMobile,
    pagination,
    onPageChange,
    onRowsPerPageChange,
    canEditDesignation = false,
    canDeleteDesignation = false
}) => {
    const { auth } = usePage().props;
    const hasEditPermission = canEditDesignation || auth.permissions?.includes('designations.update') || false;
    const hasDeletePermission = canDeleteDesignation || auth.permissions?.includes('designations.delete') || false;

    // Helper for Hierarchy Colors
    const getLevelColor = (level) => {
        const colors = { 1: 'indigo', 2: 'cyan', 3: 'green', 4: 'orange', 5: 'red' };
        return colors[level] || 'gray';
    };

    // Calculate Pagination
    const totalPages = Math.ceil((designations?.total || 0) / pagination.perPage);
    const startRecord = ((pagination.currentPage - 1) * pagination.perPage) + 1;
    const endRecord = Math.min(pagination.currentPage * pagination.perPage, designations?.total || 0);

    // Empty State Handling
    if (!loading && (!designations || !designations.data || designations.data.length === 0)) {
        return (
            <Box py="6">
                <NoDataMessage 
                    message="No designations found" 
                    description="Try adjusting your search or filters"
                />
            </Box>
        );
    }

    return (
        <Box>
            <Table.Root variant="surface">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>TITLE</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>DEPARTMENT</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>HIERARCHY</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>EMPLOYEES</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>STATUS</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell justify="end">ACTIONS</Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>

                <Table.Body>
                    {designations.data?.map((designation) => (
                        <Table.Row key={designation.id} align="center">
                            
                            {/* Title */}
                            <Table.Cell>
                                <Text weight="bold" size="2">{designation.title}</Text>
                            </Table.Cell>

                            {/* Department */}
                            <Table.Cell>
                                <Text color="gray" size="2">{designation.department_name || '-'}</Text>
                            </Table.Cell>

                            {/* Hierarchy Level */}
                            <Table.Cell>
                                <Badge color={getLevelColor(designation.hierarchy_level)} variant="soft" size="1">
                                    Level {designation.hierarchy_level || 1}
                                </Badge>
                            </Table.Cell>

                            {/* Employees Count */}
                            <Table.Cell>
                                <Flex align="center" gap="2">
                                    <PersonIcon color="gray" />
                                    <Text size="2">{designation.employee_count || 0}</Text>
                                </Flex>
                            </Table.Cell>

                            {/* Status */}
                            <Table.Cell>
                                <Badge 
                                    color={designation.is_active ? 'green' : 'red'} 
                                    variant={designation.is_active ? 'solid' : 'soft'}
                                    size="1"
                                >
                                    {designation.is_active ? <CheckCircledIcon /> : <CrossCircledIcon />}
                                    {designation.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </Table.Cell>

                            {/* Actions */}
                            <Table.Cell justify="end">
                                {!isMobile ? (
                                    <Flex gap="3" justify="end" align="center">
                                        {hasEditPermission && (
                                            <Tooltip content="Edit Designation">
                                                <IconButton 
                                                    size="1" 
                                                    variant="ghost" 
                                                    color="gray"
                                                    style={{ cursor: 'pointer' }}
                                                    onClick={() => onEdit(designation)}
                                                >
                                                    <Pencil1Icon width="16" height="16" />
                                                </IconButton>
                                            </Tooltip>
                                        )}
                                        {hasDeletePermission && (
                                            <Tooltip content={designation.employee_count > 0 ? "Cannot delete designation with employees" : "Delete Designation"}>
                                                <IconButton 
                                                    size="1" 
                                                    variant="ghost" 
                                                    color="red"
                                                    style={{ cursor: designation.employee_count > 0 ? 'not-allowed' : 'pointer' }}
                                                    disabled={designation.employee_count > 0}
                                                    onClick={() => onDelete(designation)}
                                                >
                                                    <TrashIcon width="16" height="16" />
                                                </IconButton>
                                            </Tooltip>
                                        )}
                                    </Flex>
                                ) : (
                                    <DropdownMenu.Root>
                                        <DropdownMenu.Trigger>
                                            <IconButton size="1" variant="ghost" color="gray">
                                                <DotsVerticalIcon />
                                            </IconButton>
                                        </DropdownMenu.Trigger>
                                        <DropdownMenu.Content align="end">
                                            {hasEditPermission && (
                                                <DropdownMenu.Item onClick={() => onEdit(designation)}>
                                                    <Pencil1Icon /> Edit Designation
                                                </DropdownMenu.Item>
                                            )}
                                            {hasDeletePermission && (
                                                <DropdownMenu.Item 
                                                    color="red" 
                                                    disabled={designation.employee_count > 0}
                                                    onClick={() => onDelete(designation)}
                                                >
                                                    <TrashIcon /> Delete Designation
                                                </DropdownMenu.Item>
                                            )}
                                        </DropdownMenu.Content>
                                    </DropdownMenu.Root>
                                )}
                            </Table.Cell>

                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>

            {/* Pagination */}
            <TablePagination
                pagination={pagination}
                onPageChange={onPageChange}
                onRowsPerPageChange={onRowsPerPageChange}
                loading={loading}
            />
        </Box>
    );
};

export default DesignationTable;