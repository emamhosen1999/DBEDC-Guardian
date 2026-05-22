import React from 'react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    CheckCircleIcon,
    ClockIcon,
    DocumentTextIcon,
    EllipsisVerticalIcon,
    EyeIcon,
    PencilIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';
import {
    Badge,
    Box,
    DropdownMenu,
    Flex,
    IconButton,
    ScrollArea,
    Spinner,
    Table,
    Text,
} from '@radix-ui/themes';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar.jsx';
import dayjs from 'dayjs';

const PerformanceReviewsTable = ({ data, loading, permissions, onView, onEdit, onDelete, onApprove }) => {
    const isMobile = useMediaQuery('(max-width: 768px)');
    const getStatusBadge = (status) => {
        const configs = {
            draft: { color: 'gray', label: 'Draft', icon: DocumentTextIcon },
            pending: { color: 'amber', label: 'Pending', icon: ClockIcon },
            in_progress: { color: 'blue', label: 'In Progress', icon: ClockIcon },
            completed: { color: 'green', label: 'Completed', icon: CheckCircleIcon },
            approved: { color: 'purple', label: 'Approved', icon: CheckCircleIcon },
            rejected: { color: 'red', label: 'Rejected', icon: null },
        };
        const config = configs[status] || { color: 'gray', label: status, icon: null };
        const Icon = config.icon;

        return (
            <Badge color={config.color} variant="soft" size="1">
                {Icon && <Icon className="w-3 h-3" />}
                {config.label}
            </Badge>
        );
    };

    const columns = [
        { name: 'Employee', uid: 'employee' },
        { name: 'Review Type', uid: 'review_type' },
        { name: 'Period', uid: 'period' },
        { name: 'Status', uid: 'status' },
        { name: 'Reviewer', uid: 'reviewer' },
        { name: 'Score', uid: 'score' },
        { name: 'Actions', uid: 'actions' },
    ];

    const renderCell = (item, columnKey) => {
        switch (columnKey) {
            case 'employee':
                return (
                    <Flex align="center" gap="2">
                        <ProfileAvatar
                            src={item.employee?.avatar}
                            name={item.employee?.name}
                            size="1"
                        />
                        <Box>
                            <Text size="2" weight="medium">{item.employee?.name}</Text>
                            {item.employee?.designation?.name && (
                                <Text size="1" color="gray">{item.employee.designation.name}</Text>
                            )}
                        </Box>
                    </Flex>
                );
            case 'review_type':
                return <Text size="2">{item.template?.name || item.review_type}</Text>;
            case 'period':
                return (
                    <Text size="2">
                        {item.review_period ? dayjs(item.review_period).format('MMM YYYY') : 'N/A'}
                    </Text>
                );
            case 'status':
                return getStatusBadge(item.status);
            case 'reviewer':
                return <Text size="2">{item.reviewer?.name || 'N/A'}</Text>;
            case 'score':
                return <Text size="2">{item.score || 'N/A'}</Text>;
            case 'actions':
                return (
                    <Flex justify="end">
                        <DropdownMenu.Root>
                            <DropdownMenu.Trigger>
                                <IconButton size="1" variant="ghost" color="gray" aria-label="More actions">
                                    <EllipsisVerticalIcon className="w-5 h-5" />
                                </IconButton>
                            </DropdownMenu.Trigger>
                            <DropdownMenu.Content align="end">
                                {permissions.includes('performance-reviews.view') && (
                                    <DropdownMenu.Item onClick={() => onView(item)}>
                                        <EyeIcon className="w-4 h-4" /> View Details
                                    </DropdownMenu.Item>
                                )}
                                {permissions.includes('performance-reviews.update') && item.status !== 'completed' && (
                                    <DropdownMenu.Item onClick={() => onEdit(item)}>
                                        <PencilIcon className="w-4 h-4" /> Edit Review
                                    </DropdownMenu.Item>
                                )}
                                {permissions.includes('performance-reviews.approve') && item.status === 'pending' && (
                                    <DropdownMenu.Item onClick={() => onApprove(item)}>
                                        <CheckCircleIcon className="w-4 h-4" /> Approve/Finalize
                                    </DropdownMenu.Item>
                                )}
                                {permissions.includes('performance-reviews.delete') && item.status !== 'completed' && (
                                    <DropdownMenu.Item color="red" onClick={() => onDelete(item)}>
                                        <TrashIcon className="w-4 h-4" /> Delete
                                    </DropdownMenu.Item>
                                )}
                            </DropdownMenu.Content>
                        </DropdownMenu.Root>
                    </Flex>
                );
            default:
                return <Text size="2">{item[columnKey]}</Text>;
        }
    };

    if (loading && (!data || data.length === 0)) {
        return (
            <Flex justify="center" align="center" py="8" gap="3">
                <Spinner size="3" />
                <Text color="gray">Loading performance reviews...</Text>
            </Flex>
        );
    }

    return (
        <Box className="w-full">
            <ScrollArea type="auto" scrollbars="horizontal">
                <Table.Root variant="surface" style={{ minWidth: isMobile ? 720 : undefined }}>
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
                        {!data || data.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={columns.length}>
                                    <Text size="2" color="gray">No performance reviews found</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            data.map((item) => (
                                <Table.Row key={item.id}>
                                    {columns.map((col) => (
                                        <Table.Cell key={col.uid}>{renderCell(item, col.uid)}</Table.Cell>
                                    ))}
                                </Table.Row>
                            ))
                        )}
                    </Table.Body>
                </Table.Root>
            </ScrollArea>
        </Box>
    );
};

export default PerformanceReviewsTable;
