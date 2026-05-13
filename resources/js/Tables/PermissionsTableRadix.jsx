import React, { useMemo } from "react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Table as RadixTable,
    Button,
    Tooltip,
    Text,
    Flex,
    Box,
    IconButton,
    Spinner,
    ScrollArea,
    Code
} from '@radix-ui/themes';
import {
    PersonIcon,
    Pencil1Icon,
    TrashIcon
} from '@radix-ui/react-icons';

const PermissionsTableRadix = ({ 
    permissions = [], 
    onEdit,
    onDelete,
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
            { name: "Permission", uid: "permission" },
            { name: "Name (Key)", uid: "name_key" },
            { name: "Description", uid: "description" },
            { name: "Actions", uid: "actions" }
        ];

        if (isMobile) {
            return baseColumns.filter(col => ['sl', 'permission', 'actions'].includes(col.uid));
        }

        if (isTablet) {
            return baseColumns.filter(col => !['description'].includes(col.uid));
        }
        
        return baseColumns;
    };

    const columns = useMemo(() => getColumns(), [isMobile, isTablet]);

    const renderCell = (permission, columnKey, index) => {
        const startIndex = pagination?.currentPage && pagination?.perPage 
            ? Number((pagination.currentPage - 1) * pagination.perPage) 
            : 0;
        const safeIndex = typeof index === 'number' ? index : 0;
        const serialNumber = startIndex + safeIndex + 1;
        
        switch (columnKey) {
            case "sl":
                return (
                    <RadixTable.Cell style={{ textAlign: 'center' }}>
                        <Text size="2" weight="bold">{serialNumber}</Text>
                    </RadixTable.Cell>
                );

            case "permission":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="3">
                            <Box 
                                width="10" 
                                height="10" 
                                style={{ 
                                    borderRadius: '50%', 
                                    background: 'var(--green-9)',
                                    color: 'white',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontWeight: 'bold',
                                    flexShrink: 0
                                }}
                            >
                                {permission.name ? permission.name.charAt(0).toUpperCase() : 'P'}
                            </Box>
                            <Flex direction="column">
                                <Text size="2" weight="medium">{permission.display_name || permission.name}</Text>
                                {isMobile && (
                                    <Text size="1" color="gray" style={{ maxWidth: '150px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                        {permission.name}
                                    </Text>
                                )}
                            </Flex>
                        </Flex>
                    </RadixTable.Cell>
                );

            case "name_key":
                return (
                    <RadixTable.Cell>
                        <Code size="1">{permission.name}</Code>
                    </RadixTable.Cell>
                );

            case "description":
                return (
                    <RadixTable.Cell>
                        <Text size="2" style={{ maxWidth: '250px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                            {permission.description || <Text size="2" color="gray" italic>No description</Text>}
                        </Text>
                    </RadixTable.Cell>
                );

            case "actions":
                return (
                    <RadixTable.Cell>
                        <Flex gap="2" justify="center">
                            <Tooltip content="Edit Permission">
                                <IconButton
                                    size="1"
                                    variant="ghost"
                                    color="indigo"
                                    onClick={() => onEdit?.(permission)}
                                >
                                    <Pencil1Icon />
                                </IconButton>
                            </Tooltip>
                            <Tooltip content="Delete Permission">
                                <IconButton
                                    size="1"
                                    variant="ghost"
                                    color="red"
                                    onClick={() => onDelete?.(permission)}
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
            <Text size="3" weight="medium" color="gray">No permissions found</Text>
            <Text size="2" color="gray">No permissions available in the system</Text>
        </Flex>
    );

    if (loading) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold" color="gray">Permissions</Text>
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

    if (!permissions || permissions.length === 0) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size={{ initial: '3', md: '4' }} weight="bold">Permissions</Text>
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
                <Text size={{ initial: '3', md: '4' }} weight="bold">Permissions</Text>
                <Text size="2" color="gray">Total: {pagination?.total || permissions.length}</Text>
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
                        {permissions.map((permission, index) => (
                            <RadixTable.Row key={permission.id}>
                                {columns.map((column) => renderCell(permission, column.uid, index))}
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

export default PermissionsTableRadix;
