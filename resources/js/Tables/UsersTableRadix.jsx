import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { showToast } from '@/utils/toastUtils';
import { Link } from '@inertiajs/react';
import {
    DesktopIcon,
    LockClosedIcon,
    MobileIcon,
    Pencil1Icon,
     ReloadIcon,
     EnvelopeClosedIcon,
     DotsVerticalIcon,
     PersonIcon
} from '@radix-ui/react-icons';
import {
    Avatar,
    Badge,
    Box,
    Button,
    DropdownMenu,
    Flex,
    IconButton,
    Table as RadixTable,
   
    ScrollArea,
    Select,
    Skeleton,
    Spinner,
    Switch,
    Text
} from '@radix-ui/themes';
import axios from 'axios';
import { useMemo, useState } from "react";

const UsersTableRadix = ({ 
    allUsers, 
    roles, 
    pagination,
    onPageChange,
    onRowsPerPageChange,
    totalUsers = 0,
    onEdit,
    loading = false,
    updateUserOptimized,
    deleteUserOptimized,
    toggleUserStatusOptimized,
    updateUserRolesOptimized,
    toggleSingleDeviceLogin,
    resetUserDevice,
    deviceActions = {},
}) => {
    const isMobile = useMediaQuery('(max-width: 640px)');
    const isTablet = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isDesktop = useMediaQuery('(min-width: 1025px)');

    const [loadingStates, setLoadingStates] = useState({});

    // Device detection functions
    const getDeviceIcon = (userAgent) => {
        const agent = userAgent?.toLowerCase() || '';
        if (agent.includes('android') || agent.includes('iphone') || agent.includes('mobile')) {
            return <MobileIcon />;
        }
        if (agent.includes('ipad') || agent.includes('tablet')) {
            return <DesktopIcon />;
        }
        return <DesktopIcon />;
    };

    const getDeviceType = (userAgent) => {
        const ua = userAgent?.toLowerCase() || '';
        
        if (ua.includes('mobile') || ua.includes('android') || ua.includes('iphone')) {
            return 'Mobile Device';
        } else if (ua.includes('tablet') || ua.includes('ipad')) {
            return 'Tablet';
        } else {
            return 'Desktop';
        }
    };

    // Set loading state for specific operations
    const setLoading = (userId, operation, isLoading) => {
        setLoadingStates(prev => ({
            ...prev,
            [`${userId}-${operation}`]: isLoading
        }));
    };

    const isLoading = (userId, operation) => {
        return loadingStates[`${userId}-${operation}`] || false;
    };

    // Handle role change
    const handleRoleChange = async (userId, newRoleNames) => {
        setLoading(userId, 'role', true);
        try {
            const response = await axios.post(`/user/updateRole/${userId}`, {
                roles: newRoleNames,
            });
            if (response.status === 200) {
                if (updateUserRolesOptimized) {
                    updateUserRolesOptimized(userId, newRoleNames);
                }
                showToast.success('Role updated successfully');
            }
        } catch (error) {
            console.error('Error updating role:', error);
            showToast.error('Failed to update user role');
        } finally {
            setLoading(userId, 'role', false);
        }
    };

    // Handle delete
    const handleDelete = async (userId) => {
        setLoading(userId, 'delete', true);
        try {
            const response = await fetch('/profile/delete', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ user_id: userId }),
            });
            const data = await response.json();
            if (response.ok) {
                if (deleteUserOptimized) {
                    deleteUserOptimized(userId);
                }
                showToast.success(data.message);
            } else {
                showToast.error(data.message);
            }
        } catch (error) {
            showToast.error('An error occurred while deleting user');
        } finally {
            setLoading(userId, 'delete', false);
        }
    };

    // Toggle user status
    const toggleUserStatus = async (userId, currentStatus) => {
        if (isLoading(userId, 'status')) return;
        
        setLoading(userId, 'status', true);
        try {
            if (toggleUserStatusOptimized) {
                toggleUserStatusOptimized(userId, !currentStatus);
                showToast.success(`User status ${!currentStatus ? 'activated' : 'deactivated'} successfully`);
            }
        } catch (error) {
            console.error('Error toggling user status:', error);
            showToast.error('Failed to update user status');
        } finally {
            setLoading(userId, 'status', false);
        }
    };

    // Columns definition
    const columns = useMemo(() => {
        const baseColumns = [
            { name: "#", uid: "sl" },
            { name: "User", uid: "user" },
            { name: "Email", uid: "email" },
            { name: "Department", uid: "department" },
            { name: "Device Status", uid: "device_status" },
            { name: "Status", uid: "status" },
            { name: "Roles", uid: "roles" },
            { name: "Actions", uid: "actions" }
        ];

        // Add phone column for desktop
        if (!isMobile && !isTablet) {
            baseColumns.splice(4, 0, { name: "Phone", uid: "phone" });
        }

        // Remove department and device status on mobile
        if (isMobile) {
            baseColumns.splice(baseColumns.findIndex(col => col.uid === "department"), 1);
            baseColumns.splice(baseColumns.findIndex(col => col.uid === "device_status"), 1);
        }

        return baseColumns;
    }, [isMobile, isTablet]);

    // Render cell content
    const renderCell = (user, columnKey, rowIndex) => {
        switch (columnKey) {
            case "sl":
                const startIndex = pagination?.currentPage && pagination?.perPage 
                    ? Number((pagination.currentPage - 1) * pagination.perPage) 
                    : 0;
                const safeIndex = typeof rowIndex === 'number' ? rowIndex : 0;
                const serialNumber = startIndex + safeIndex + 1;
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
                                src={user?.profile_image_url || user?.profile_image}
                                fallback={user?.name?.charAt(0) || 'U'}
                                radius="full"
                            />
                            <Flex direction="column">
                                <Text size="2" weight="medium">{user?.name || "Unnamed User"}</Text>
                                <Text size="1" color="gray">ID: {user?.id}</Text>
                            </Flex>
                        </Flex>
                    </RadixTable.Cell>
                );
                
            case "email":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="2">
                            <EnvelopeClosedIcon color="var(--gray-9)" />
                            <Text size="2">{user.email}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
                
            case "phone":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="2">
                            <MobileIcon color="var(--gray-9)" />
                            <Text size="2">{user.phone || "N/A"}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
                
            case "department":
                return (
                    <RadixTable.Cell>
                        <Text size="2">{user?.department?.name || "N/A"}</Text>
                    </RadixTable.Cell>
                );

            case "device_status":
                const isToggling = isLoading(user.id, 'deviceToggle');
                return (
                    <RadixTable.Cell>
                        {user.single_device_login ? (
                            <Badge size="1" variant={user.active_device ? 'soft' : 'solid'} color={user.active_device ? 'orange' : 'green'}>
                                {isToggling ? 'Loading...' : user.active_device ? 'Locked' : 'Free'}
                            </Badge>
                        ) : (
                            <Badge size="1" variant="soft" color="gray">
                                {isToggling ? 'Loading...' : 'Disabled'}
                            </Badge>
                        )}
                    </RadixTable.Cell>
                );

            case "status":
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="3">
                            <Switch
                                checked={user.active}
                                onCheckedChange={() => toggleUserStatus(user.id, user.active)}
                                disabled={isLoading(user.id, 'status')}
                            />
                            <Text size="1" color={user.active ? 'green' : 'red'}>
                                {user.active ? "Active" : "Inactive"}
                            </Text>
                        </Flex>
                    </RadixTable.Cell>
                );
                
            case "roles":
                const roleNames = user.roles?.map(role => 
                    typeof role === 'object' && role !== null ? role.name : role
                ) || [];
                const roleSet = new Set(roleNames);
                const selectedValue = Array.from(roleSet).join(", ") || "No Roles";
                
                return (
                    <RadixTable.Cell>
                        <Select.Root
                            disabled={isLoading(user.id, 'role')}
                            value={Array.from(roleSet)}
                            onValueChange={(values) => handleRoleChange(user.id, values)}
                            multiple
                        >
                            <Select.Trigger>
                                {selectedValue}
                            </Select.Trigger>
                            <Select.Content>
                                {(roles || []).map((role) => (
                                    <Select.Item 
                                        key={typeof role === 'object' && role !== null ? role.name : role}
                                        value={typeof role === 'object' && role !== null ? role.name : role}
                                    >
                                        {typeof role === 'object' && role !== null ? role.name : role}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </RadixTable.Cell>
                );
                
            case "actions":
                return (
                    <RadixTable.Cell>
                        <DropdownMenu.Root>
                            <DropdownMenu.Trigger>
                                <IconButton size="1" variant="ghost" color="gray">
                                    <DotsVerticalIcon />
                                </IconButton>
                            </DropdownMenu.Trigger>
                            <DropdownMenu.Content>
                                <DropdownMenu.Item asChild>
                                    <Link href={`/profile/${user.id}`}>
                                        <Flex align="center" gap="2">
                                            <PersonIcon />
                                            View Profile
                                        </Flex>
                                    </Link>
                                </DropdownMenu.Item>
                                <DropdownMenu.Separator />
                                <DropdownMenu.Item onClick={() => onEdit && onEdit(user)}>
                                    <Flex align="center" gap="2">
                                        <Pencil1Icon />
                                        Edit
                                    </Flex>
                                </DropdownMenu.Item>
                                <DropdownMenu.Separator />
                                <DropdownMenu.Item
                                    onClick={() => {
                                        if (toggleSingleDeviceLogin && !isLoading(user.id, 'deviceToggle')) {
                                            setLoading(user.id, 'deviceToggle', true);
                                            toggleSingleDeviceLogin(user.id, !user.single_device_login)
                                                .finally(() => {
                                                    setLoading(user.id, 'deviceToggle', false);
                                                });
                                        }
                                    }}
                                    disabled={deviceActions[user.id] || isLoading(user.id, 'deviceToggle')}
                                >
                                    <Flex align="center" gap="2">
                                        {isLoading(user.id, 'deviceToggle') ? (
                                            <Spinner size="1" />
                                        ) : user.single_device_login ? (
                                            <LockClosedIcon />
                                        ) : (
                                            <LockClosedIcon />
                                        )}
                                        {isLoading(user.id, 'deviceToggle') 
                                            ? 'Processing...' 
                                            : user.single_device_login 
                                                ? 'Disable Device Lock' 
                                                : 'Enable Device Lock'
                                        }
                                    </Flex>
                                </DropdownMenu.Item>
                                {user.single_device_login && user.active_device && (
                                    <DropdownMenu.Item
                                        onClick={() => {
                                            if (resetUserDevice && !isLoading(user.id, 'deviceReset')) {
                                                setLoading(user.id, 'deviceReset', true);
                                                resetUserDevice(user.id)
                                                    .finally(() => {
                                                        setLoading(user.id, 'deviceReset', false);
                                                    });
                                            }
                                        }}
                                        disabled={deviceActions[user.id] || isLoading(user.id, 'deviceReset')}
                                    >
                                        <Flex align="center" gap="2">
                                            <ReloadIcon />
                                            {isLoading(user.id, 'deviceReset') ? 'Resetting...' : 'Reset Device'}
                                        </Flex>
                                    </DropdownMenu.Item>
                                )}
                                <DropdownMenu.Separator />
                                <DropdownMenu.Item asChild>
                                    <Link href={`/admin/users/devices/${user.id}`}>
                                        <Flex align="center" gap="2">
                                            <DesktopIcon />
                                            View Device History
                                        </Flex>
                                    </Link>
                                </DropdownMenu.Item>
                            </DropdownMenu.Content>
                        </DropdownMenu.Root>
                    </RadixTable.Cell>
                );
                
            default:
                return <RadixTable.Cell>{user[columnKey]}</RadixTable.Cell>;
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
                                <Skeleton height="20px" />
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
            <Text size="2" color="gray">No users available in the system</Text>
        </Flex>
    );

    if (loading) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)', display: 'flex', flexDirection: 'column' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size="4" weight="bold" color="gray">Users</Text>
                    <Button variant="soft" color="indigo" size="1" disabled>
                        <ReloadIcon style={{ animation: 'spin 1s linear infinite' }} />
                        Loading...
                    </Button>
                </Flex>
                <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                    <TableSkeleton />
                </ScrollArea>
            </Box>
        );
    }

    if (!allUsers || allUsers.length === 0) {
        return (
            <Box style={{ maxHeight: 'calc(100vh - 280px)' }}>
                <Flex align="center" justify="between" mb="4" px="2">
                    <Text size="4" weight="bold" color="gray">Users</Text>
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
                <Text size={{ initial: '3', md: '4' }} weight="bold">Users</Text>
                <Text size="2" color="gray">Total: {totalUsers}</Text>
            </Flex>

            {/* Table */}
            <ScrollArea type="auto" style={{ flexGrow: 1, minHeight: '400px' }}>
                <RadixTable.Root variant="surface" style={{ width: '100%', minWidth: '800px' }}>
                    <RadixTable.Header>
                        <RadixTable.Row>
                            {columns.map((column) => (
                                <RadixTable.ColumnHeaderCell 
                                    key={column.uid}
                                    style={{ textAlign: column.uid === 'user' ? 'left' : 'center' }}
                                >
                                    <Text size="1" weight="bold">{column.name}</Text>
                                </RadixTable.ColumnHeaderCell>
                            ))}
                        </RadixTable.Row>
                    </RadixTable.Header>
                    <RadixTable.Body>
                        {allUsers.map((user, rowIndex) => (
                            <RadixTable.Row key={user.id}>
                                {columns.map((column) => (
                                    <RadixTable.Cell key={`${user.id}-${column.uid}`}>
                                        {renderCell(user, column.uid, rowIndex)}
                                    </RadixTable.Cell>
                                ))}
                            </RadixTable.Row>
                        ))}
                    </RadixTable.Body>
                </RadixTable.Root>
            </ScrollArea>

            {/* Pagination */}
            {totalUsers > 10 && (
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
                        onClick={() => onPageChange(Math.max(1, pagination.currentPage - 1))} 
                        disabled={pagination.currentPage <= 1}
                    >
                        Prev
                    </Button>
                    <Text size="2" weight="medium" color="gray">
                        Page {pagination.currentPage} of {Math.ceil(totalUsers / pagination.perPage)}
                    </Text>
                    <Button 
                        variant="surface" 
                        color="gray"
                        size="2" 
                        onClick={() => onPageChange(Math.min(Math.ceil(totalUsers / pagination.perPage), pagination.currentPage + 1))} 
                        disabled={pagination.currentPage >= Math.ceil(totalUsers / pagination.perPage)}
                    >
                        Next
                    </Button>
                </Flex>
            )}
        </Box>
    );
};

export default UsersTableRadix;
