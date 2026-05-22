import React, { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import {
    Table,
    Badge,
    Tooltip,
    IconButton,
    DropdownMenu,
    Flex,
    Text,
    Box,
    ScrollArea,
    Spinner,
} from '@radix-ui/themes';
import {
    Pencil1Icon,
    TrashIcon,
    EyeOpenIcon,
    PersonIcon,
    CalendarIcon,
    DrawingPinIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    DotsVerticalIcon,
} from '@radix-ui/react-icons';
import {
    BuildingOffice2Icon,
} from '@heroicons/react/24/outline';
import dayjs from 'dayjs';
import NoDataMessage from '@/Components/NoDataMessage';
import TablePagination from '@/Components/TablePagination.jsx';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

const DepartmentTable = ({
    departments,
    onEdit,
    onDelete,
    onView,
    loading,
    isMobile: isMobileProp,
    isTablet,
    pagination,
    onPageChange,
    onRowsPerPageChange,
    canEditDepartment = false,
    canDeleteDepartment = false,
}) => {
    const { auth } = usePage().props;
    const isMobileQuery = useMediaQuery('(max-width: 768px)');
    const isMobile = isMobileProp ?? isMobileQuery;

    const hasEditPermission = canEditDepartment || auth.permissions?.includes('departments.update') || false;
    const hasDeletePermission = canDeleteDepartment || auth.permissions?.includes('departments.delete') || false;

    const columns = useMemo(() => {
        const baseColumns = [
            { name: 'DEPARTMENT', uid: 'name' },
            { name: 'CODE', uid: 'code', hide: isMobile },
            { name: 'MANAGER', uid: 'manager' },
            { name: 'EMPLOYEES', uid: 'employees' },
            { name: 'LOCATION', uid: 'location', hide: isMobile },
            { name: 'STATUS', uid: 'status' },
            { name: 'ESTABLISHED', uid: 'established', hide: isMobile || isTablet },
            { name: 'ACTIONS', uid: 'actions' },
        ];
        return baseColumns.filter((col) => !col.hide);
    }, [isMobile, isTablet]);

    const renderCell = (department, columnKey) => {
        switch (columnKey) {
            case 'name':
                return (
                    <Flex align="center" gap="2">
                        <Box
                            p="2"
                            style={{
                                borderRadius: 'var(--radius-2)',
                                background: 'var(--accent-a3)',
                            }}
                        >
                            <BuildingOffice2Icon className="w-5 h-5" style={{ color: 'var(--accent-9)' }} />
                        </Box>
                        <Box>
                            <Text size="2" weight="bold">{department.name}</Text>
                            <Text size="1" color="gray">
                                {department.parent ? department.parent.name : 'Top-level department'}
                            </Text>
                        </Box>
                    </Flex>
                );

            case 'code':
                return department.code ? (
                    <Badge color="blue" variant="soft" size="1">{department.code}</Badge>
                ) : (
                    <Text size="1" color="gray">—</Text>
                );

            case 'manager':
                return department.manager ? (
                    <Flex align="center" gap="2">
                        <ProfileAvatar
                            src={department.manager?.profile_image_url || department.manager?.profile_image}
                            name={department.manager.name}
                            size="1"
                        />
                        <Box>
                            <Text size="2" weight="medium">{department.manager.name}</Text>
                            {!isMobile && department.manager.email && (
                                <Text size="1" color="gray">{department.manager.email}</Text>
                            )}
                        </Box>
                    </Flex>
                ) : (
                    <Text size="1" color="gray">Not assigned</Text>
                );

            case 'employees':
                return (
                    <Flex align="center" gap="2">
                        <PersonIcon color="gray" />
                        <Text size="2">{department.employee_count || 0}</Text>
                    </Flex>
                );

            case 'location':
                return department.location ? (
                    <Flex align="center" gap="2">
                        <DrawingPinIcon color="gray" />
                        <Text size="2">{department.location}</Text>
                    </Flex>
                ) : (
                    <Text size="1" color="gray">—</Text>
                );

            case 'status':
                return (
                    <Badge
                        color={department.is_active ? 'green' : 'red'}
                        variant={department.is_active ? 'solid' : 'soft'}
                        size="1"
                    >
                        {department.is_active ? <CheckCircledIcon /> : <CrossCircledIcon />}
                        {department.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                );

            case 'established':
                return (
                    <Flex align="center" gap="2">
                        <CalendarIcon color="gray" />
                        <Text size="2">
                            {department.established_date
                                ? dayjs(department.established_date).format('MMM D, YYYY')
                                : '—'}
                        </Text>
                    </Flex>
                );

            case 'actions':
                return (
                    <Flex justify="end" align="center" gap="1">
                        {!isMobile ? (
                            <>
                                <Tooltip content="View Department">
                                    <IconButton
                                        size="1"
                                        variant="ghost"
                                        color="gray"
                                        onClick={() => onView(department)}
                                    >
                                        <EyeOpenIcon />
                                    </IconButton>
                                </Tooltip>
                                {hasEditPermission && (
                                    <Tooltip content="Edit Department">
                                        <IconButton
                                            size="1"
                                            variant="ghost"
                                            color="gray"
                                            onClick={() => onEdit(department)}
                                        >
                                            <Pencil1Icon />
                                        </IconButton>
                                    </Tooltip>
                                )}
                                {hasDeletePermission && (
                                    <Tooltip
                                        content={
                                            department.employee_count > 0
                                                ? 'Cannot delete department with employees'
                                                : 'Delete Department'
                                        }
                                    >
                                        <IconButton
                                            size="1"
                                            variant="ghost"
                                            color="red"
                                            disabled={department.employee_count > 0}
                                            onClick={() => onDelete(department)}
                                        >
                                            <TrashIcon />
                                        </IconButton>
                                    </Tooltip>
                                )}
                            </>
                        ) : (
                            <DropdownMenu.Root>
                                <DropdownMenu.Trigger>
                                    <IconButton size="1" variant="ghost" color="gray">
                                        <DotsVerticalIcon />
                                    </IconButton>
                                </DropdownMenu.Trigger>
                                <DropdownMenu.Content align="end">
                                    <DropdownMenu.Item onClick={() => onView(department)}>
                                        <EyeOpenIcon /> View Details
                                    </DropdownMenu.Item>
                                    {hasEditPermission && (
                                        <DropdownMenu.Item onClick={() => onEdit(department)}>
                                            <Pencil1Icon /> Edit Department
                                        </DropdownMenu.Item>
                                    )}
                                    {hasDeletePermission && (
                                        <DropdownMenu.Item
                                            color="red"
                                            disabled={department.employee_count > 0}
                                            onClick={() => onDelete(department)}
                                        >
                                            <TrashIcon /> Delete Department
                                        </DropdownMenu.Item>
                                    )}
                                </DropdownMenu.Content>
                            </DropdownMenu.Root>
                        )}
                    </Flex>
                );

            default:
                return <Text size="2">{department[columnKey]}</Text>;
        }
    };

    if (!loading && (!departments || !departments.data || departments.data.length === 0)) {
        return (
            <Box className="w-full">
                <NoDataMessage
                    message="No departments found"
                    icon={<BuildingOffice2Icon className="w-12 h-12" style={{ color: 'var(--gray-8)' }} />}
                    description="Try adjusting your search or filters"
                />
            </Box>
        );
    }

    return (
        <Box className="w-full">
            <ScrollArea type="auto" scrollbars={isMobile ? 'horizontal' : 'vertical'} style={{ maxHeight: isMobile ? undefined : '70vh' }}>
                <Table.Root variant="surface" style={{ minWidth: isMobile ? 640 : undefined }}>
                    <Table.Header>
                        <Table.Row>
                            {columns.map((col) => (
                                <Table.ColumnHeaderCell
                                    key={col.uid}
                                    justify={col.uid === 'actions' ? 'end' : 'start'}
                                >
                                    {col.name}
                                </Table.ColumnHeaderCell>
                            ))}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {loading ? (
                            <Table.Row>
                                <Table.Cell colSpan={columns.length}>
                                    <Flex justify="center" py="6">
                                        <Spinner size="3" />
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            (departments.data || []).map((department) => (
                                <Table.Row key={department.id} align="center">
                                    {columns.map((col) => (
                                        <Table.Cell key={col.uid}>{renderCell(department, col.uid)}</Table.Cell>
                                    ))}
                                </Table.Row>
                            ))
                        )}
                    </Table.Body>
                </Table.Root>
            </ScrollArea>

            <TablePagination
                pagination={{
                    currentPage: pagination?.currentPage ?? 1,
                    perPage: pagination?.perPage ?? 10,
                    total: departments?.total ?? 0,
                }}
                onPageChange={onPageChange}
                onRowsPerPageChange={onRowsPerPageChange}
                loading={loading}
            />
        </Box>
    );
};

export default DepartmentTable;
