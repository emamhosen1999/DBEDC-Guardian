import React, { useCallback } from 'react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { usePage, router } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';
import {
    Table,
    Badge,
    Tooltip,
    IconButton,
    DropdownMenu,
    Button,
    Flex,
    Text,
    Box,
    Card,
    Separator,
    ScrollArea,
    Spinner,
} from '@radix-ui/themes';
import {
    MapPinIcon,
    UserIcon,
    ClockIcon,
    DocumentTextIcon,
    PencilIcon,
    TrashIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ArrowPathIcon,
} from '@heroicons/react/24/outline';
import {
    CheckCircleIcon as CheckCircleSolid,
    ExclamationTriangleIcon as ExclamationTriangleSolid,
} from '@heroicons/react/24/solid';
import { DotsVerticalIcon } from '@radix-ui/react-icons';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar.jsx';

const WorkLocationsTable = ({
    allData,
    loading,
    handleClickOpen,
    openModal,
    setCurrentRow,
    auth,
}) => {
    const { permissions } = usePage().props.auth;
    const isMobile = useMediaQuery('(max-width: 640px)');

    const handleRefresh = useCallback(() => {
        router.reload({
            only: ['jurisdictions'],
            onSuccess: () => {
                showToast.success('Work locations data refreshed successfully');
            },
        });
    }, []);

    const canEdit = permissions?.includes('jurisdiction.update') || auth?.roles?.includes('Administrator');
    const canDelete = permissions?.includes('jurisdiction.delete') || auth?.roles?.includes('Administrator');

    const getLocationStatus = (location) => {
        if (location.incharge_user) {
            return { label: 'Active', color: 'green', icon: <CheckCircleSolid className="w-3 h-3" /> };
        }
        return { label: 'Pending', color: 'amber', icon: <ExclamationTriangleSolid className="w-3 h-3" /> };
    };

    const columns = [
        { name: 'Location', uid: 'location', icon: MapPinIcon },
        { name: 'Start Chainage', uid: 'start_chainage', icon: DocumentTextIcon },
        { name: 'End Chainage', uid: 'end_chainage', icon: DocumentTextIcon },
        { name: 'Incharge', uid: 'incharge', icon: UserIcon },
        { name: 'Status', uid: 'status', icon: CheckCircleIcon },
        { name: 'Created', uid: 'created_at', icon: ClockIcon },
        ...(canEdit || canDelete ? [{ name: 'Actions', uid: 'actions' }] : []),
    ];

    const renderActions = (location) => (
        <Flex align="center" gap="2">
            {canEdit && (
                <Tooltip content="Edit location">
                    <IconButton
                        size="1"
                        variant="ghost"
                        color="gray"
                        onClick={() => {
                            setCurrentRow(location);
                            openModal('editWorkLocation');
                        }}
                    >
                        <PencilIcon className="w-4 h-4" />
                    </IconButton>
                </Tooltip>
            )}
            {canDelete && (
                <Tooltip content="Delete location">
                    <IconButton
                        size="1"
                        variant="ghost"
                        color="red"
                        onClick={() => handleClickOpen(location.id, 'deleteWorkLocation')}
                    >
                        <TrashIcon className="w-4 h-4" />
                    </IconButton>
                </Tooltip>
            )}
        </Flex>
    );

    const renderCell = (location, columnKey) => {
        switch (columnKey) {
            case 'location':
                return (
                    <Flex align="center" gap="2">
                        <Box p="1" style={{ borderRadius: 'var(--radius-1)', background: 'var(--accent-a3)' }}>
                            <MapPinIcon className="w-3 h-3" style={{ color: 'var(--accent-9)' }} />
                        </Box>
                        <Text size="2" weight="medium">{location.location}</Text>
                    </Flex>
                );
            case 'start_chainage':
            case 'end_chainage':
                return <Text size="2">{location[columnKey]}</Text>;
            case 'incharge':
                return location.incharge_user ? (
                    <Flex align="center" gap="2">
                        <ProfileAvatar
                            src={location.incharge_user.profile_image_url || location.incharge_user.profile_image}
                            name={location.incharge_user.name}
                            size="1"
                        />
                        <Box>
                            <Text size="2" weight="medium">{location.incharge_user.name}</Text>
                            <Text size="1" color="gray">{location.incharge_user.email}</Text>
                        </Box>
                    </Flex>
                ) : (
                    <Flex align="center" gap="2">
                        <UserIcon className="w-4 h-4" style={{ color: 'var(--gray-9)' }} />
                        <Text size="2" color="gray">Not assigned</Text>
                    </Flex>
                );
            case 'status': {
                const status = getLocationStatus(location);
                return (
                    <Badge color={status.color} variant="soft" size="1">
                        {status.icon}
                        {status.label}
                    </Badge>
                );
            }
            case 'created_at':
                return (
                    <Flex align="center" gap="2">
                        <ClockIcon className="w-4 h-4" style={{ color: 'var(--gray-9)' }} />
                        <Text size="2" color="gray">
                            {new Date(location.created_at).toLocaleDateString()}
                        </Text>
                    </Flex>
                );
            case 'actions':
                return renderActions(location);
            default:
                return <Text size="2">{location[columnKey]}</Text>;
        }
    };

    const MobileWorkLocationCard = ({ location }) => {
        const status = getLocationStatus(location);

        return (
            <Card key={location.id} mb="3">
                <Box p="3" pb="2">
                    <Flex align="start" justify="between" gap="2">
                        <Flex align="center" gap="3" style={{ flex: 1, minWidth: 0 }}>
                            <Box p="2" style={{ borderRadius: 'var(--radius-2)', background: 'var(--accent-a3)' }}>
                                <MapPinIcon className="w-5 h-5" style={{ color: 'var(--accent-9)' }} />
                            </Box>
                            <Box style={{ minWidth: 0, flex: 1 }}>
                                <Text size="2" weight="bold" style={{ display: 'block' }} className="truncate">
                                    {location.location}
                                </Text>
                                <Badge color={status.color} variant="soft" size="1" mt="1">
                                    {status.icon}
                                    {status.label}
                                </Badge>
                            </Box>
                        </Flex>
                        {(canEdit || canDelete) && (
                            <DropdownMenu.Root>
                                <DropdownMenu.Trigger>
                                    <IconButton size="1" variant="ghost" color="gray">
                                        <DotsVerticalIcon />
                                    </IconButton>
                                </DropdownMenu.Trigger>
                                <DropdownMenu.Content align="end">
                                    {canEdit && (
                                        <DropdownMenu.Item
                                            onClick={() => {
                                                setCurrentRow(location);
                                                openModal('editWorkLocation');
                                            }}
                                        >
                                            <PencilIcon className="w-4 h-4" /> Edit Location
                                        </DropdownMenu.Item>
                                    )}
                                    {canDelete && (
                                        <DropdownMenu.Item
                                            color="red"
                                            onClick={() => handleClickOpen(location.id, 'deleteWorkLocation')}
                                        >
                                            <TrashIcon className="w-4 h-4" /> Delete Location
                                        </DropdownMenu.Item>
                                    )}
                                </DropdownMenu.Content>
                            </DropdownMenu.Root>
                        )}
                    </Flex>
                </Box>
                <Separator size="4" />
                <Box p="3" pt="2">
                    <Flex direction="column" gap="2">
                        <Flex align="center" gap="2">
                            <DocumentTextIcon className="w-4 h-4" style={{ color: 'var(--gray-9)' }} />
                            <Text size="2" color="gray">
                                Chainage: {location.start_chainage} - {location.end_chainage}
                            </Text>
                        </Flex>
                        <Flex align="center" gap="2">
                            <UserIcon className="w-4 h-4" style={{ color: 'var(--gray-9)' }} />
                            <Text size="2" color="gray">
                                Incharge: {location.incharge_user?.name || 'Not assigned'}
                            </Text>
                        </Flex>
                        <Flex align="center" gap="2">
                            <ClockIcon className="w-4 h-4" style={{ color: 'var(--gray-9)' }} />
                            <Text size="2" color="gray">
                                Created: {new Date(location.created_at).toLocaleDateString()}
                            </Text>
                        </Flex>
                    </Flex>
                </Box>
            </Card>
        );
    };

    const headerBar = (
        <Flex align="center" justify="between" mb="4" px="2">
            <Text size="4" weight="medium">Work Locations</Text>
            <Button variant="soft" color="blue" size="2" onClick={handleRefresh}>
                <ArrowPathIcon className="w-4 h-4" />
                Refresh
            </Button>
        </Flex>
    );

    if (isMobile) {
        return (
            <Box>
                {headerBar}
                <ScrollArea type="auto" scrollbars="horizontal" style={{ maxHeight: '70vh' }}>
                    <Box style={{ minWidth: 320 }}>
                        {loading ? (
                            <Flex justify="center" py="8">
                                <Spinner size="3" />
                            </Flex>
                        ) : (
                            allData?.map((location) => (
                                <MobileWorkLocationCard key={location.id} location={location} />
                            ))
                        )}
                    </Box>
                </ScrollArea>
            </Box>
        );
    }

    return (
        <Box style={{ maxHeight: '84vh', overflowY: 'auto' }}>
            {headerBar}
            <ScrollArea type="auto" scrollbars="horizontal" style={{ maxHeight: '70vh' }}>
                {loading ? (
                    <Flex justify="center" py="8">
                        <Spinner size="3" />
                    </Flex>
                ) : (
                    <Table.Root variant="surface" style={{ minWidth: 800 }}>
                        <Table.Header>
                            <Table.Row>
                                {columns.map((column) => (
                                    <Table.ColumnHeaderCell
                                        key={column.uid}
                                        justify={column.uid === 'actions' ? 'center' : 'start'}
                                    >
                                        <Flex align="center" gap="1">
                                            {column.icon && <column.icon className="w-3 h-3" />}
                                            <Text size="1" weight="bold">{column.name}</Text>
                                        </Flex>
                                    </Table.ColumnHeaderCell>
                                ))}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {!allData || allData.length === 0 ? (
                                <Table.Row>
                                    <Table.Cell colSpan={columns.length}>
                                        <Text size="2" color="gray">No work locations found</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ) : (
                                allData.map((location) => (
                                    <Table.Row key={location.id}>
                                        {columns.map((col) => (
                                            <Table.Cell key={col.uid}>
                                                {renderCell(location, col.uid)}
                                            </Table.Cell>
                                        ))}
                                    </Table.Row>
                                ))
                            )}
                        </Table.Body>
                    </Table.Root>
                )}
            </ScrollArea>
        </Box>
    );
};

export default WorkLocationsTable;
