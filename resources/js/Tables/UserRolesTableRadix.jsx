import React, { useMemo } from "react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Table as RadixTable,
    Button,
    Badge,
    Text,
    Flex,
    Box,
    Spinner,
    ScrollArea,
    Avatar
} from '@radix-ui/themes';
import {
    PersonIcon,
    EnvelopeClosedIcon
} from '@radix-ui/react-icons';

const UserRolesTableRadix = ({ 
    users = [], 
    roles = [],
    onRowClick,
    pagination,
    onPageChange,
    loading = false
}) => {
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    const getColumns = () => {
        const baseColumns = [
            { name: "#", uid: "sl" },
            { name: "User", uid: "user" },
            { name: "Email", uid: "email" },
            { name: "Assigned Roles", uid: "roles" },
            { name: "Status", uid: "status" }
        ];

        if (isMobile) {
            return baseColumns.filter(col => ['sl', 'user', 'roles'].includes(col.uid));
        }

        if (isTablet) {
            return baseColumns.filter(col => !['status'].includes(col.uid));
        }
        
        return baseColumns;
    };

    const columns = useMemo(() => getColumns(), [isMobile, isTablet]);

    const renderCell = (user, columnKey, index) => {
        const startIndex = pagination?.currentPage && pagination?.perPage 
            ? Number((pagination.currentPage - 1) * pagination.perPage) 
            : 0;
        const safeIndex = typeof index === 'number' ? index : 0;
        const serialNumber = startIndex + safeIndex + 1;
        
        const userRoles = user.roles || [];
        
        switch (columnKey) {
            case "sl":
                return (
                    <RadixTable.Cell style={{ textAlign: 'center' }}>
                        <Text size="2" weight="bold">{serialNumber}</Text>
                    </RadixTable.Cell>
                );

            case "user":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="3">
                            <Avatar
                                size="2"
                                src={user.profile_image_url || user.avatar}
                                fallback={user?.name?.charAt(0) || 'U'}
                                radius="full"
                            />
                            <Flex direction="column">
                                <Text size="2" weight="medium">{user.name || 'Unknown User'}</Text>
                                {isMobile && user.email && (
                                    <Text size="1" color="gray" style={{ maxWidth: '150px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                        {user.email}
                                    </Text>
                                )}
                            </Flex>
                        </Flex>
                    </RadixTable.Cell>
                );

            case "email":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="2">
                            <EnvelopeClosedIcon color="var(--gray-7)" />
                            <Text size="2">{user.email || 'No email'}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );

            case "roles":
                return (
                    <RadixTable.Cell>
                        <Flex gap="1" wrap style={{ maxWidth: '300px' }}>
                            {userRoles.length > 0 ? (
                                <>
                                    {userRoles.slice(0, 3).map((role, idx) => (
                                        <Badge key={idx} size="1" variant="soft" color="indigo">
                                            {role.name}
                                        </Badge>
                                    ))}
                                    {userRoles.length > 3 && (
                                        <Badge size="1" variant="soft" color="violet">
                                            +{userRoles.length - 3} more
                                        </Badge>
                                    )}
                                </>
                            ) : (
                                <Badge size="1" variant="soft" color="gray">
                                    No roles assigned
                                </Badge>
                            )}
                        </Flex>
                    </RadixTable.Cell>
                );

            case "status":
                return (
                    <RadixTable.Cell>
                        <Badge size="1" variant={user.is_active !== false ? 'soft' : 'outline'} color={user.is_active !== false ? 'green' : 'gray'}>
                            {user.is_active !== false ? "Active" : "Inactive"}
                        </Badge>
                    </RadixTable.Cell>
                );

            default:
                return null;
        }
    };

    // Loading skeleton
    const TableSkeleton = () => (
        <RadixTable.Root variant="surface">
            <RadixTable.Header>
                <RadixTable.Row>
                    {columns.map((column) => (
                        <RadixTable.ColumnHeaderCell key={column.uid}>
                            <Text size="1" weight="bold">{column.name}</Text>
                        </RadixTable.ColumnHeaderCell>
                    ))}
                </RadixTable.Row>
            </RadixTable.Header>
            <RadixTable.Body>
                {Array.from({ length: 5 }).map((_, rowIndex) => (
                    <RadixTable.Row key={rowIndex}>
                        {columns.map((column, colIndex) => (
                            <RadixTable.Cell key={colIndex}>
                                <Spinner size="1" />
                            </RadixTable.Cell>
                        ))}
                    </RadixTable.Row>
                ))}
            </RadixTable.Body>
        </RadixTable.Root>
    );

    // Empty state
    const EmptyState = () => (
        <Flex direction="column" align="center" justify="center" py="8" gap="3">
            <PersonIcon width="40" height="40" style={{ color: 'var(--gray-7)' }} />
            <Text size="3" weight="medium" color="gray">No users found</Text>
            <Text size="2" color="gray">No users with role assignments found</Text>
        </Flex>
    );

    if (loading) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold" color="gray">User Roles</Text>
                    <Button variant="soft" color="indigo" size="1" disabled>
                        <Spinner size="1" />
                        Loading...
                    </Button>
                </Flex>
                <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                    <TableSkeleton />
                </ScrollArea>
            </Box>
        );
    }

    if (!users || users.length === 0) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold">User Roles</Text>
                </Flex>
                <Card>
                    <EmptyState />
                </Card>
            </Box>
        );
    }

    return (
        <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
            {/* Header */}
            <Flex align="center" justify="between" mb="4" px="2">
                <Text size={{ initial: '3', md: '4' }} weight="bold">User Roles</Text>
                <Text size="2" color="gray">Total: {pagination?.total || users.length}</Text>
            </Flex>

            {/* Table */}
            <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                <RadixTable.Root variant="surface" style={{ width: '100%', minWidth: '800px' }}>
                    <RadixTable.Header>
                        <RadixTable.Row>
                            {columns.map((column) => (
                                <RadixTable.ColumnHeaderCell 
                                    key={column.uid}
                                    style={{ textAlign: column.uid === 'sl' ? 'center' : 'left' }}
                                >
                                    <Text size="1" weight="bold">{column.name}</Text>
                                </RadixTable.ColumnHeaderCell>
                            ))}
                        </RadixTable.Row>
                    </RadixTable.Header>
                    <RadixTable.Body>
                        {users.map((user, index) => (
                            <RadixTable.Row 
                                key={user.id}
                                onClick={() => onRowClick?.(user)}
                                style={{ cursor: onRowClick ? 'pointer' : 'default' }}
                            >
                                {columns.map((column) => renderCell(user, column.uid, index))}
                            </RadixTable.Row>
                        ))}
                    </RadixTable.Body>
                </RadixTable.Root>
            </ScrollArea>

            {/* Pagination */}
            {pagination && pagination.total > 10 && (
                <Flex 
                    py="4" 
                    justify="center" 
                    gap="3" 
                    align="center"
                    display={{ initial: 'none', md: 'flex' }}
                >
                    <Button 
                        variant="surface" 
                        color="gray"
                        size="2" 
                        onClick={() => onPageChange && onPageChange(Math.max(1, pagination.currentPage - 1))} 
                        disabled={pagination.currentPage <= 1}
                    >
                        Prev
                    </Button>
                    <Text size="2" weight="medium" color="gray">
                        Page {pagination.currentPage} of {Math.ceil(pagination.total / pagination.perPage)}
                    </Text>
                    <Button 
                        variant="surface" 
                        color="gray"
                        size="2" 
                        onClick={() => onPageChange && onPageChange(Math.min(Math.ceil(pagination.total / pagination.perPage), pagination.currentPage + 1))} 
                        disabled={pagination.currentPage >= Math.ceil(pagination.total / pagination.perPage)}
                    >
                        Next
                    </Button>
                </Flex>
            )}
        </Box>
    );
};

export default UserRolesTableRadix;
