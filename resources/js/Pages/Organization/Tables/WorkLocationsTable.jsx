import React from "react";
import { usePage } from "@inertiajs/react";
import {
    Table, Badge, Tooltip, IconButton, DropdownMenu,
    Flex, Text, Box, Spinner
} from '@radix-ui/themes';
import {
    SewingPinIcon, Pencil1Icon, TrashIcon,
    DotsVerticalIcon
} from '@radix-ui/react-icons';

const WorkLocationsTable = ({ 
    allData = [], 
    loading, 
    onEdit, 
    onDelete, 
    isMobile,
    auth
}) => {
    const hasEditPermission = auth?.permissions?.includes('jurisdiction.update') || auth?.roles?.includes('Super Administrator') || false;
    const hasDeletePermission = auth?.permissions?.includes('jurisdiction.delete') || auth?.roles?.includes('Super Administrator') || false;

    if (loading && allData.length === 0) {
        return (
            <Flex justify="center" align="center" py="8" direction="column" gap="3">
                <Spinner size="3" />
                <Text color="gray">Loading work locations...</Text>
            </Flex>
        );
    }

    if (!loading && allData.length === 0) {
        return (
            <Flex direction="column" align="center" justify="center" py="9" gap="2">
                <SewingPinIcon style={{ width: 40, height: 40, color: 'var(--gray-8)' }} />
                <Text size="3" weight="medium">No work locations found</Text>
                <Text size="2" color="gray">Add a location or adjust your search.</Text>
            </Flex>
        );
    }

    return (
        <Box style={{ overflowX: 'auto' }}>
            <Table.Root variant="surface">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Location Name</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Default Attendance Rule</Table.ColumnHeaderCell>
                        {(hasEditPermission || hasDeletePermission) && (
                            <Table.ColumnHeaderCell justify="end">Actions</Table.ColumnHeaderCell>
                        )}
                    </Table.Row>
                </Table.Header>

                <Table.Body>
                    {allData.map((location) => (
                        <Table.Row key={location.id} align="center">
                            
                            {/* Location Name */}
                            <Table.Cell>
                                <Flex align="center" gap="2">
                                    <Box p="1" style={{ background: 'var(--amber-a3)', borderRadius: 'var(--radius-2)' }}>
                                        <SewingPinIcon style={{ color: 'var(--amber-9)' }} />
                                    </Box>
                                    <Text weight="bold" size="2">{location.name}</Text>
                                </Flex>
                            </Table.Cell>

                            {/* Default Rule */}
                            <Table.Cell>
                                {location.attendance_type ? (
                                    <Badge color="violet" variant="soft" size="2">
                                        {location.attendance_type.name}
                                    </Badge>
                                ) : (
                                    <Text color="gray" size="2" style={{ fontStyle: 'italic' }}>None (Default Validation)</Text>
                                )}
                            </Table.Cell>

                            {/* Actions */}
                            {(hasEditPermission || hasDeletePermission) && (
                                <Table.Cell justify="end">
                                    {!isMobile ? (
                                        <Flex gap="3" justify="end" align="center">
                                            {hasEditPermission && (
                                                <Tooltip content="Edit Location">
                                                    <IconButton size="1" variant="ghost" color="gray" onClick={() => onEdit(location)}>
                                                        <Pencil1Icon />
                                                    </IconButton>
                                                </Tooltip>
                                            )}
                                            {hasDeletePermission && (
                                                <Tooltip content="Delete Location">
                                                    <IconButton size="1" variant="ghost" color="red" onClick={() => onDelete(location)}>
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
                                                {hasEditPermission && (
                                                    <DropdownMenu.Item onClick={() => onEdit(location)}>
                                                        Edit
                                                    </DropdownMenu.Item>
                                                )}
                                                {hasDeletePermission && (
                                                    <DropdownMenu.Item color="red" onClick={() => onDelete(location)}>
                                                        Delete
                                                    </DropdownMenu.Item>
                                                )}
                                            </DropdownMenu.Content>
                                        </DropdownMenu.Root>
                                    )}
                                </Table.Cell>
                            )}
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
};

export default WorkLocationsTable;