import React, { useMemo } from "react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Table as RadixTable,
    Button,
    Badge,
    Tooltip,
    Text,
    Flex,
    Box,
    IconButton,
    Spinner,
    ScrollArea
} from '@radix-ui/themes';
import {
    PersonIcon,
    Pencil1Icon,
    TrashIcon,
    LockClosedIcon
} from '@radix-ui/react-icons';

const RolesTableRadix = ({ 
    roles = [], 
    permissions = [],
    getRolePermissions,
    onEdit,
    onDelete,
    canManageRole,
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
            { name: "Role", uid: "role" },
            { name: "Description", uid: "description" },
            { name: "Permissions", uid: "permissions" },
            { name: "Status", uid: "status" },
            { name: "Actions", uid: "actions" }
        ];

        if (isMobile) {
            return baseColumns.filter(col => ['sl', 'role', 'status', 'actions'].includes(col.uid));
        }

        if (isTablet) {
            return baseColumns.filter(col => !['description'].includes(col.uid));
        }
        
        return baseColumns;
    };

    const columns = useMemo(() => getColumns(), [isMobile, isTablet]);

    const renderCell = (role, columnKey, index) => {
        const startIndex = pagination?.currentPage && pagination?.perPage 
            ? Number((pagination.currentPage - 1) * pagination.perPage) 
            : 0;
        const safeIndex = typeof index === 'number' ? index : 0;
        const serialNumber = startIndex + safeIndex + 1;
        
        const rolePerms = getRolePermissions ? getRolePermissions(role.id) : [];
        const permissionNames = rolePerms
            .map(permId => permissions.find(p => p.id === permId)?.name)
            .filter(Boolean);
            
        switch (columnKey) {
            case "sl":
                return (
                    <RadixTable.Cell style={{ textAlign: 'center' }}>
                        <Text size="2" weight="bold">{serialNumber}</Text>
                    </RadixTable.Cell>
                );

            case "role":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="3">
                            <Box 
                                width="10" 
                                height="10" 
                                style={{ 
                                    borderRadius: '50%', 
                                    background: 'var(--accent-9)',
                                    color: 'white',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontWeight: 'bold',
                                    flexShrink: 0
                                }}
                            >
                                {role.name.charAt(0).toUpperCase()}
                            </Box>
                            <Flex direction="column">
                                <Flex align="center" gap="2">
                                    <Text size="2" weight="medium">{role.name}</Text>
                                    {role.name === 'Super Administrator' && (
                                        <Badge size="1" color="amber" variant="soft">System</Badge>
                                    )}
                                </Flex>
                                {isMobile && role.description && (
                                    <Text size="1" color="gray" style={{ maxWidth: '150px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                        {role.description}
                                    </Text>
                                )}
                            </Flex>
                        </Flex>
                    </RadixTable.Cell>
                );

            case "description":
                return (
                    <RadixTable.Cell>
                        <Text size="2" style={{ maxWidth: '250px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                            {role.description || <Text size="2" color="gray" style={{ fontStyle: 'italic' }}>No description</Text>}
                        </Text>
                    </RadixTable.Cell>
                );

            case "permissions":
                return (
                    <RadixTable.Cell>
                        <Flex gap="1" wrap style={{ maxWidth: '200px' }}>
                            {permissionNames.slice(0, 3).map((permission, idx) => (
                                <Badge key={idx} size="1" variant="soft" color="indigo">
                                    {permission}
                                </Badge>
                            ))}
                            {permissionNames.length > 3 && (
                                <Badge size="1" variant="soft" color="violet">
                                    +{permissionNames.length - 3} more
                                </Badge>
                            )}
                            {permissionNames.length === 0 && (
                                <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>No permissions</Text>
                            )}
                        </Flex>
                    </RadixTable.Cell>
                );

            case "status":
                return (
                    <RadixTable.Cell>
                        <Badge size="1" variant={role.is_active !== false ? 'soft' : 'outline'} color={role.is_active !== false ? 'green' : 'gray'}>
                            {role.is_active !== false ? "Active" : "Inactive"}
                        </Badge>
                    </RadixTable.Cell>
                );

            case "actions":
                const canManage = canManageRole ? canManageRole(role) : true;
                return (
                    <RadixTable.Cell>
                        <Flex gap="2" justify="center">
                            <Tooltip content="Edit Role">
                                <IconButton
                                    size="1"
                                    variant="ghost"
                                    color="indigo"
                                    onClick={() => onEdit?.(role)}
                                    disabled={!canManage}
                                >
                                    <Pencil1Icon />
                                </IconButton>
                            </Tooltip>
                            <Tooltip content="Delete Role">
                                <IconButton
                                    size="1"
                                    variant="ghost"
                                    color="red"
                                    onClick={() => onDelete?.(role)}
                                    disabled={!canManage}
                                >
                                    <TrashIcon />
                                </IconButton>
                            </Tooltip>
                        </Flex>
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
            <Text size="3" weight="medium" color="gray">No roles found</Text>
            <Text size="2" color="gray">No roles available in the system</Text>
        </Flex>
    );

    if (loading) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold" color="gray">Roles</Text>
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

    if (!roles || roles.length === 0) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold">Roles</Text>
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
                <Text size={{ initial: '3', md: '4' }} weight="bold">Roles</Text>
                <Text size="2" color="gray">Total: {pagination?.total || roles.length}</Text>
            </Flex>

            {/* Table */}
            <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                <RadixTable.Root variant="surface" style={{ width: '100%', minWidth: '800px' }}>
                    <RadixTable.Header>
                        <RadixTable.Row>
                            {columns.map((column) => (
                                <RadixTable.ColumnHeaderCell 
                                    key={column.uid}
                                    style={{ textAlign: column.uid === 'actions' ? 'center' : column.uid === 'sl' ? 'center' : 'left' }}
                                >
                                    <Text size="1" weight="bold">{column.name}</Text>
                                </RadixTable.ColumnHeaderCell>
                            ))}
                        </RadixTable.Row>
                    </RadixTable.Header>
                    <RadixTable.Body>
                        {roles.map((role, index) => (
                            <RadixTable.Row key={role.id}>
                                {columns.map((column) => (
                                    <RadixTable.Cell key={`${role.id}-${column.uid}`}>
                                        {renderCell(role, column.uid, index)}
                                    </RadixTable.Cell>
                                ))}
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

export default RolesTableRadix;
