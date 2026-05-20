import React from "react";
import { usePage } from "@inertiajs/react";
import {
    Table, Badge, Tooltip, IconButton, DropdownMenu,
    Flex, Text, Box, Spinner
} from '@radix-ui/themes';
import {
    SewingPinIcon, PersonIcon, Pencil1Icon, TrashIcon,
    DotsVerticalIcon, TimerIcon, TrackNextIcon
} from '@radix-ui/react-icons';

import ProfileAvatar from '../../../Components/Profile/ProfileAvatar.jsx';

const WorkLocationsTable = ({ 
    allData, 
    loading, 
    onEdit, 
    onDelete, 
    isMobile,
    auth
}) => {
    const hasEditPermission = auth.permissions?.includes('work_locations.update') || false;
    const hasDeletePermission = auth.permissions?.includes('work_locations.delete') || false;

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
                        {!isMobile && <Table.ColumnHeaderCell>Start Chainage</Table.ColumnHeaderCell>}
                        {!isMobile && <Table.ColumnHeaderCell>End Chainage</Table.ColumnHeaderCell>}
                        <Table.ColumnHeaderCell>In-Charge</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell justify="end">Actions</Table.ColumnHeaderCell>
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
                                    <Text weight="bold" size="2">{location.location}</Text>
                                </Flex>
                            </Table.Cell>

                            {/* Start Chainage */}
                            {!isMobile && (
                                <Table.Cell>
                                    {location.start_chainage ? (
                                        <Flex align="center" gap="1">
                                            <TimerIcon color="var(--gray-9)" />
                                            <Text size="2">{location.start_chainage}</Text>
                                        </Flex>
                                    ) : <Text color="gray" size="2">—</Text>}
                                </Table.Cell>
                            )}

                            {/* End Chainage */}
                            {!isMobile && (
                                <Table.Cell>
                                    {location.end_chainage ? (
                                        <Flex align="center" gap="1">
                                            <TrackNextIcon color="var(--gray-9)" />
                                            <Text size="2">{location.end_chainage}</Text>
                                        </Flex>
                                    ) : <Text color="gray" size="2">—</Text>}
                                </Table.Cell>
                            )}

                            {/* In-Charge */}
                            <Table.Cell>
                                {location.incharge_user ? (
                                    <Flex align="center" gap="2">
                                        <ProfileAvatar 
                                            src={location.incharge_user.profile_image_url || location.incharge_user.profile_image} 
                                            name={location.incharge_user.name} 
                                            size="1" 
                                        />
                                        <Text size="2" weight="medium">{location.incharge_user.name}</Text>
                                    </Flex>
                                ) : (
                                    <Badge color="gray" variant="soft" size="1">Unassigned</Badge>
                                )}
                            </Table.Cell>

                            {/* Actions */}
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
                                                    <Pencil1Icon /> Edit Location
                                                </DropdownMenu.Item>
                                            )}
                                            {hasDeletePermission && (
                                                <DropdownMenu.Item color="red" onClick={() => onDelete(location)}>
                                                    <TrashIcon /> Delete Location
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
        </Box>
    );
};

export default WorkLocationsTable;