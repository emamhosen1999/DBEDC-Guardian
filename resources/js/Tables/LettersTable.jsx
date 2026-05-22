import React, { useState } from 'react';
import {
    Table,
    Select,
    TextArea,
    IconButton,
    Tooltip,
    Flex,
    Text,
    Box,
    ScrollArea,
    Spinner,
} from '@radix-ui/themes';
import {
    PencilIcon,
    TrashIcon,
    EyeIcon,
} from '@heroicons/react/24/outline';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import Loader from '@/Components/Loader.jsx';
import { usePage } from '@inertiajs/react';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar.jsx';
import TablePagination from '@/Components/TablePagination.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

const LettersTable = ({
    allData,
    setData,
    users,
    loading,
    handleClickOpen,
    openModal,
    setCurrentRow,
    search,
}) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 768px)');
    const [currentPage, setCurrentPage] = useState(1);
    const [sortDescriptor, setSortDescriptor] = useState({
        column: 'received_date',
        direction: 'descending',
    });

    const userIsAdmin = auth.roles.includes('Administrator');
    const userIsSe = auth.designation === 'Supervision Engineer';
    const itemsPerPage = 10;

    const getStatusSelect = (status, letterId) => (
        <Select.Root
            size="1"
            value={status}
            onValueChange={(value) => handleChange(letterId, 'status', value)}
        >
            <Select.Trigger style={{ minWidth: 120 }} />
            <Select.Content>
                {['Open', 'Processing', 'Signed', 'Sent', 'Closed'].map((s) => (
                    <Select.Item key={s} value={s}>
                        {s}
                    </Select.Item>
                ))}
            </Select.Content>
        </Select.Root>
    );

    const highlightText = (text) => {
        if (!search || !text) return text;
        const searchTerms = search.split(' ').filter(Boolean);
        const regex = new RegExp(`(${searchTerms.join('|')})`, 'gi');
        const parts = text.split(regex);

        return parts.map((part, index) =>
            searchTerms.some((term) => part.toLowerCase() === term.toLowerCase()) ? (
                <mark key={index} className="bg-yellow-200 dark:bg-yellow-600">
                    {part}
                </mark>
            ) : (
                part
            ),
        );
    };

    const columns = [
        { name: 'From', uid: 'from', sortable: true },
        { name: 'Status', uid: 'status', sortable: true },
        { name: 'Subject', uid: 'subject', sortable: true },
        { name: 'Action Taken', uid: 'action_taken', sortable: false },
        { name: 'Assigned To', uid: 'assigned_to', sortable: true },
        { name: 'Date', uid: 'received_date', sortable: true },
        { name: 'Actions', uid: 'actions' },
    ];

    const renderCell = (item, columnKey) => {
        switch (columnKey) {
            case 'from':
                return <Text size="2">{highlightText(item.from)}</Text>;
            case 'status':
                return getStatusSelect(item.status, item.id);
            case 'subject':
                return (
                    <Tooltip content={item.subject}>
                        <Text size="2" className="max-w-[200px] truncate block">
                            {highlightText(item.subject)}
                        </Text>
                    </Tooltip>
                );
            case 'action_taken':
                return (
                    <TextArea
                        size="1"
                        value={item.action_taken || ''}
                        onChange={(e) => handleChange(item.id, 'action_taken', e.target.value)}
                        rows={2}
                        placeholder="Enter action taken..."
                        style={{ minWidth: 200 }}
                    />
                );
            case 'assigned_to':
                return (
                    <Select.Root
                        size="1"
                        value={item.assigned_to ? String(item.assigned_to) : ''}
                        onValueChange={(value) => handleChange(item.id, 'assigned_to', value)}
                    >
                        <Select.Trigger placeholder="Select user" style={{ minWidth: 140 }} />
                        <Select.Content>
                            {users.map((user) => (
                                <Select.Item key={user.id} value={String(user.id)}>
                                    <Flex align="center" gap="2">
                                        <ProfileAvatar
                                            src={
                                                user.profile_image_url ||
                                                user.profile_image ||
                                                user.profile_photo_url
                                            }
                                            name={user.name}
                                            size="1"
                                        />
                                        {user.name}
                                    </Flex>
                                </Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                );
            case 'received_date':
                return (
                    <Text size="2">
                        {item.received_date
                            ? new Date(item.received_date).toLocaleDateString()
                            : 'N/A'}
                    </Text>
                );
            case 'actions':
                return (
                    <Flex align="center" gap="2">
                        <Tooltip content="View Details">
                            <IconButton
                                size="1"
                                variant="ghost"
                                color="gray"
                                onClick={() => {
                                    setCurrentRow(item);
                                    openModal('view_letter');
                                }}
                            >
                                <EyeIcon className="w-4 h-4" />
                            </IconButton>
                        </Tooltip>
                        {(userIsAdmin || userIsSe) && (
                            <>
                                <Tooltip content="Edit Letter">
                                    <IconButton
                                        size="1"
                                        variant="ghost"
                                        color="gray"
                                        onClick={() => {
                                            setCurrentRow(item);
                                            openModal('edit_letter');
                                        }}
                                    >
                                        <PencilIcon className="w-4 h-4" />
                                    </IconButton>
                                </Tooltip>
                                <Tooltip content="Delete Letter">
                                    <IconButton
                                        size="1"
                                        variant="ghost"
                                        color="red"
                                        onClick={() => handleClickOpen(item.id, 'delete_letter')}
                                    >
                                        <TrashIcon className="w-4 h-4" />
                                    </IconButton>
                                </Tooltip>
                            </>
                        )}
                    </Flex>
                );
            default:
                return <Text size="2">{item[columnKey]}</Text>;
        }
    };

    const sortedItems = React.useMemo(() => {
        return [...allData].sort((a, b) => {
            const first = a[sortDescriptor.column];
            const second = b[sortDescriptor.column];
            const cmp = first < second ? -1 : first > second ? 1 : 0;
            return sortDescriptor.direction === 'descending' ? -cmp : cmp;
        });
    }, [allData, sortDescriptor]);

    const items = React.useMemo(() => {
        const start = (currentPage - 1) * itemsPerPage;
        return sortedItems.slice(start, start + itemsPerPage);
    }, [currentPage, sortedItems]);

    const pages = Math.ceil(sortedItems.length / itemsPerPage);

    const handleChange = async (letterId, key, value) => {
        try {
            const response = await axios.put(route('letters.update'), {
                id: letterId,
                [key]: value,
            });

            if (response.status === 200) {
                setData((prevLetters) =>
                    prevLetters.map((letter) =>
                        letter.id === letterId ? { ...letter, [key]: value } : letter,
                    ),
                );
                showToast.success(response.data.messages || 'Letter updated successfully');
            } else {
                showToast.error(response.data.error || `Failed to update letter ${key}.`);
            }
        } catch (err) {
            showToast.error(err.response?.data?.message || 'An unexpected error occurred.');
        }
    };

    const toggleSort = (uid) => {
        setSortDescriptor((prev) => ({
            column: uid,
            direction:
                prev.column === uid && prev.direction === 'ascending'
                    ? 'descending'
                    : 'ascending',
        }));
    };

    return (
        <Box className="w-full">
            {loading && <Loader />}
            <ScrollArea type="auto" scrollbars={isMobile ? 'horizontal' : 'vertical'}>
                <Table.Root variant="surface" style={{ minWidth: isMobile ? 900 : undefined }}>
                    <Table.Header>
                        <Table.Row>
                            {columns.map((column) => (
                                <Table.ColumnHeaderCell
                                    key={column.uid}
                                    justify={column.uid === 'actions' ? 'center' : 'start'}
                                    style={column.sortable ? { cursor: 'pointer' } : undefined}
                                    onClick={
                                        column.sortable ? () => toggleSort(column.uid) : undefined
                                    }
                                >
                                    {column.name}
                                    {sortDescriptor.column === column.uid &&
                                        (sortDescriptor.direction === 'ascending' ? ' ↑' : ' ↓')}
                                </Table.ColumnHeaderCell>
                            ))}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {loading ? (
                            <Table.Row>
                                <Table.Cell colSpan={columns.length}>
                                    <Flex justify="center" py="6" gap="2">
                                        <Spinner size="2" />
                                        <Text size="2">Loading...</Text>
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                        ) : items.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={columns.length}>
                                    <Text size="2" color="gray">No letters found</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : (
                            items.map((item) => (
                                <Table.Row key={item.id}>
                                    {columns.map((col) => (
                                        <Table.Cell key={col.uid}>
                                            {renderCell(item, col.uid)}
                                        </Table.Cell>
                                    ))}
                                </Table.Row>
                            ))
                        )}
                    </Table.Body>
                </Table.Root>
            </ScrollArea>
            {pages > 1 && (
                <TablePagination
                    pagination={{
                        currentPage,
                        perPage: itemsPerPage,
                        total: sortedItems.length,
                    }}
                    onPageChange={setCurrentPage}
                    loading={loading}
                />
            )}
        </Box>
    );
};

export default LettersTable;
