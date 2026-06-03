import React, { useState, useMemo, useCallback, useEffect } from 'react';
import {
    Table,
    Badge,
    Button,
    Card,
    Flex,
    Text,
    Box,
    TextField,
    ScrollArea,
    DropdownMenu,
    IconButton,
    Spinner,
} from '@radix-ui/themes';
import {
    CalendarIcon,
    MagnifyingGlassIcon,
    Pencil2Icon,
    TrashIcon,
    EyeOpenIcon,
    ClockIcon,
    CheckCircledIcon,
    MixerHorizontalIcon,
    DotsVerticalIcon,
    Cross2Icon,
} from '@radix-ui/react-icons';
import { format, differenceInDays, isAfter, isBefore } from 'date-fns';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import TablePagination from '@/Components/TablePagination.jsx';

const holidayTypes = {
    public: { label: 'Public', color: 'red', icon: '🏛️' },
    religious: { label: 'Religious', color: 'purple', icon: '🕌' },
    national: { label: 'National', color: 'blue', icon: '🇧🇩' },
    company: { label: 'Company', color: 'amber', icon: '🏢' },
    optional: { label: 'Optional', color: 'gray', icon: '📅' },
};

const statusOptions = [
    { key: 'upcoming', label: 'Upcoming', color: 'blue', icon: ClockIcon },
    { key: 'ongoing', label: 'Ongoing', color: 'green', icon: CheckCircledIcon },
    { key: 'past', label: 'Past', color: 'gray', icon: CheckCircledIcon },
];

const toggleInArray = (setter, value) => {
    setter((prev) =>
        prev.includes(value) ? prev.filter((v) => v !== value) : [...prev, value],
    );
};

const HolidayTable = ({
    holidaysData,
    onEdit,
    onDelete,
    onFilteredDataChange,
    isLoading = false,
}) => {
    const isMobile = useMediaQuery('(max-width: 768px)');

    const [filterValue, setFilterValue] = useState('');
    const [typeFilter, setTypeFilter] = useState([]);
    const [statusFilter, setStatusFilter] = useState([]);
    const [yearFilter, setYearFilter] = useState([new Date().getFullYear().toString()]);
    const [page, setPage] = useState(1);
    const [rowsPerPage, setRowsPerPage] = useState(10);
    const [showFilters, setShowFilters] = useState(false);

    const getHolidayStatus = useCallback((holiday) => {
        const today = new Date();
        const fromDate = new Date(holiday.from_date);
        const toDate = new Date(holiday.to_date);

        if (isBefore(today, fromDate)) {
            return { status: 'upcoming', label: 'Upcoming', color: 'blue', icon: ClockIcon };
        }
        if (isAfter(today, toDate)) {
            return { status: 'past', label: 'Past', color: 'gray', icon: CheckCircledIcon };
        }
        return { status: 'ongoing', label: 'Ongoing', color: 'green', icon: CheckCircledIcon };
    }, []);

    const filteredHolidays = useMemo(() => {
        let filtered = holidaysData;

        if (filterValue) {
            filtered = filtered.filter(
                (holiday) =>
                    holiday.title?.toLowerCase().includes(filterValue.toLowerCase()) ||
                    holiday.description?.toLowerCase().includes(filterValue.toLowerCase()),
            );
        }
        if (typeFilter.length > 0) {
            filtered = filtered.filter((holiday) => typeFilter.includes(holiday.type));
        }
        if (statusFilter.length > 0) {
            filtered = filtered.filter((holiday) => {
                const status = getHolidayStatus(holiday);
                return statusFilter.includes(status.status);
            });
        }
        if (yearFilter.length > 0) {
            filtered = filtered.filter((holiday) => {
                const holidayYear = new Date(holiday.from_date).getFullYear().toString();
                return yearFilter.includes(holidayYear);
            });
        }

        return filtered;
    }, [holidaysData, filterValue, typeFilter, statusFilter, yearFilter, getHolidayStatus]);

    useEffect(() => {
        onFilteredDataChange?.(filteredHolidays);
    }, [filteredHolidays, onFilteredDataChange]);

    const items = useMemo(() => {
        const start = (page - 1) * rowsPerPage;
        return filteredHolidays.slice(start, start + rowsPerPage);
    }, [page, filteredHolidays, rowsPerPage]);

    const columns = [
        { name: 'Holiday', uid: 'title' },
        { name: 'Duration', uid: 'duration' },
        { name: 'Type', uid: 'type' },
        { name: 'Status', uid: 'status' },
        { name: 'Actions', uid: 'actions' },
    ];

    const yearOptions = Array.from(
        { length: new Date().getFullYear() - 2019 + 3 },
        (_, i) => (2020 + i).toString(),
    );

    const renderActionsMenu = (holiday) => (
        <DropdownMenu.Root>
            <DropdownMenu.Trigger>
                <IconButton size="1" variant="ghost" color="gray" aria-label="Holiday actions">
                    <DotsVerticalIcon />
                </IconButton>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content align="end">
                <DropdownMenu.Item>
                    <EyeOpenIcon style={{ width: 16, height: 16, marginRight: 8 }} /> View Details
                </DropdownMenu.Item>
                <DropdownMenu.Item onClick={() => onEdit?.(holiday)}>
                    <Pencil2Icon style={{ width: 16, height: 16, marginRight: 8 }} /> Edit Holiday
                </DropdownMenu.Item>
                <DropdownMenu.Item color="red" onClick={() => onDelete?.(holiday.id)}>
                    <TrashIcon style={{ width: 16, height: 16, marginRight: 8 }} /> Delete Holiday
                </DropdownMenu.Item>
            </DropdownMenu.Content>
        </DropdownMenu.Root>
    );

    const renderCell = useCallback(
        (holiday, columnKey) => {
            switch (columnKey) {
                case 'title': {
                    const duration =
                        differenceInDays(new Date(holiday.to_date), new Date(holiday.from_date)) + 1;
                    return (
                        <Box>
                            <Text size="2" weight="bold" style={{ textTransform: 'capitalize' }}>
                                {holiday.title}
                            </Text>
                            <Flex align="center" gap="1" mt="1">
                                <CalendarIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                <Text size="1" color="gray">
                                    {format(new Date(holiday.from_date), 'MMM dd, yyyy')}
                                    {duration > 1 &&
                                        ` - ${format(new Date(holiday.to_date), 'MMM dd, yyyy')}`}
                                </Text>
                            </Flex>
                            {holiday.description && (
                                <Text size="1" color="gray" mt="1" style={{
                                    display: '-webkit-box',
                                    WebkitLineClamp: 2,
                                    WebkitBoxOrient: 'vertical',
                                    overflow: 'hidden'
                                }}>
                                    {holiday.description}
                                </Text>
                            )}
                        </Box>
                    );
                }
                case 'duration': {
                    const days =
                        differenceInDays(new Date(holiday.to_date), new Date(holiday.from_date)) + 1;
                    return (
                        <Text size="2" weight="bold">
                            {days} {days === 1 ? 'day' : 'days'}
                        </Text>
                    );
                }
                case 'type': {
                    const typeConfig = holidayTypes[holiday.type] || holidayTypes.company;
                    return (
                        <Badge color={typeConfig.color} variant="soft" size="1">
                            {typeConfig.icon} {typeConfig.label}
                        </Badge>
                    );
                }
                case 'status': {
                    const statusConfig = getHolidayStatus(holiday);
                    const StatusIcon = statusConfig.icon;
                    return (
                        <Badge color={statusConfig.color} variant="outline" size="1">
                            <StatusIcon style={{ width: 12, height: 12, marginRight: 4 }} />
                            {statusConfig.label}
                        </Badge>
                    );
                }
                case 'actions':
                    return (
                        <Flex justify="end">{renderActionsMenu(holiday)}</Flex>
                    );
                default:
                    return <Text size="2">{holiday[columnKey]}</Text>;
            }
        },
        [getHolidayStatus, onEdit, onDelete],
    );

    const FilterChip = ({ label, onRemove }) => (
        <Badge variant="soft" size="1">
            {label}
            <button
                type="button"
                onClick={onRemove}
                aria-label="Remove filter"
                style={{
                    marginLeft: 4,
                    background: 'none',
                    border: 'none',
                    cursor: 'pointer',
                    padding: 0,
                    lineHeight: 0,
                }}
            >
                <Cross2Icon style={{ width: 12, height: 12 }} />
            </button>
        </Badge>
    );

    const topContent = (
        <Flex direction="column" gap="4">
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3">
                <Box style={{ flex: 1 }}>
                    <TextField.Root
                        size={isMobile ? '2' : '3'}
                        placeholder="Search by title or description..."
                        value={filterValue}
                        onChange={(e) => setFilterValue(e.target.value)}
                    >
                        <TextField.Slot>
                            <MagnifyingGlassIcon style={{ width: 16, height: 16, color: 'var(--gray-9)' }} />
                        </TextField.Slot>
                    </TextField.Root>
                </Box>
                <Button
                    variant={showFilters ? 'solid' : 'outline'}
                    color={showFilters ? 'blue' : 'gray'}
                    onClick={() => setShowFilters(!showFilters)}
                >
                    <MixerHorizontalIcon style={{ width: 16, height: 16 }} />
                    {!isMobile && <span style={{ marginLeft: 4 }}>Filters</span>}
                </Button>
            </Flex>

            {showFilters && (
                <Box>
                    <Card style={{ padding: 16 }}>
                        <Text size="2" weight="medium" mb="2" as="div">
                            Holiday Type
                        </Text>
                        <Flex gap="2" wrap="wrap" mb="4">
                            {Object.entries(holidayTypes).map(([key, config]) => (
                                <Badge
                                    key={key}
                                    color={config.color}
                                    variant={typeFilter.includes(key) ? 'solid' : 'soft'}
                                    size="1"
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => toggleInArray(setTypeFilter, key)}
                                >
                                    {config.icon} {config.label}
                                </Badge>
                            ))}
                        </Flex>

                        <Text size="2" weight="medium" mb="2" as="div">
                            Status
                        </Text>
                        <Flex gap="2" wrap="wrap" mb="4">
                            {statusOptions.map((opt) => (
                                <Badge
                                    key={opt.key}
                                    color={opt.color}
                                    variant={statusFilter.includes(opt.key) ? 'solid' : 'soft'}
                                    size="1"
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => toggleInArray(setStatusFilter, opt.key)}
                                >
                                    <opt.icon style={{ width: 12, height: 12, marginRight: 4 }} /> {opt.label}
                                </Badge>
                            ))}
                        </Flex>

                        <Text size="2" weight="medium" mb="2" as="div">
                            Year
                        </Text>
                        <Flex gap="2" wrap="wrap" mb="4">
                            {yearOptions.map((year) => (
                                <Badge
                                    key={year}
                                    variant={yearFilter.includes(year) ? 'solid' : 'soft'}
                                    size="1"
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => toggleInArray(setYearFilter, year)}
                                >
                                    📅 {year}
                                </Badge>
                            ))}
                        </Flex>

                        <Text size="2" weight="medium" mb="2" as="div">
                            Rows per page
                        </Text>
                        <Flex gap="2" wrap="wrap">
                            {[5, 10, 15, 25].map((n) => (
                                <Badge
                                    key={n}
                                    variant={rowsPerPage === n ? 'solid' : 'soft'}
                                    size="1"
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => setRowsPerPage(n)}
                                >
                                    {n}
                                </Badge>
                            ))}
                        </Flex>

                        {(filterValue ||
                            typeFilter.length > 0 ||
                            statusFilter.length > 0 ||
                            yearFilter.length > 0) && (
                                <Flex gap="2" wrap="wrap" mt="4" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                                    {filterValue && (
                                        <FilterChip
                                            label={`Search: ${filterValue}`}
                                            onRemove={() => setFilterValue('')}
                                        />
                                    )}
                                    {typeFilter.map((type) => (
                                        <FilterChip
                                            key={type}
                                            label={`${holidayTypes[type]?.icon} ${holidayTypes[type]?.label}`}
                                            onRemove={() =>
                                                setTypeFilter((prev) => prev.filter((t) => t !== type))
                                            }
                                        />
                                    ))}
                                    {statusFilter.map((status) => (
                                        <FilterChip
                                            key={status}
                                            label={status.charAt(0).toUpperCase() + status.slice(1)}
                                            onRemove={() =>
                                                setStatusFilter((prev) => prev.filter((s) => s !== status))
                                            }
                                        />
                                    ))}
                                    {yearFilter.map((year) => (
                                        <FilterChip
                                            key={year}
                                            label={`📅 ${year}`}
                                            onRemove={() =>
                                                setYearFilter((prev) => prev.filter((y) => y !== year))
                                            }
                                        />
                                    ))}
                                </Flex>
                            )}
                    </Card>
                </Box>
            )}

            <Text size="1" color="gray">
                Total {filteredHolidays.length} holidays
                {(typeFilter.length > 0 || statusFilter.length > 0 || filterValue) &&
                    ` (filtered from ${holidaysData.length})`}
            </Text>
        </Flex>
    );

    const MobileHolidayCard = ({ holiday }) => {
        const typeConfig = holidayTypes[holiday.type] || holidayTypes.company;
        const statusConfig = getHolidayStatus(holiday);
        const StatusIcon = statusConfig.icon;
        const days = differenceInDays(new Date(holiday.to_date), new Date(holiday.from_date)) + 1;

        return (
            <Card mb="2">
                <Flex justify="between" align="start" gap="2" mb="3">
                    <Box style={{ flex: 1 }}>
                        <Text size="3" weight="bold">
                            {holiday.title}
                        </Text>
                        {holiday.description && (
                            <Text size="2" color="gray" mt="1" style={{
                                display: '-webkit-box',
                                WebkitLineClamp: 2,
                                WebkitBoxOrient: 'vertical',
                                overflow: 'hidden'
                            }}>
                                {holiday.description}
                            </Text>
                        )}
                    </Box>
                    <Flex align="center" gap="2">
                        <Badge color={statusConfig.color} variant="outline" size="1">
                            <StatusIcon style={{ width: 12, height: 12, marginRight: 4 }} />
                            {statusConfig.label}
                        </Badge>
                        {renderActionsMenu(holiday)}
                    </Flex>
                </Flex>
                <Flex gap="4" wrap="wrap">
                    <Box>
                        <Text size="1" color="gray" mb="1" as="div">
                            Type
                        </Text>
                        <Badge color={typeConfig.color} variant="soft" size="1">
                            {typeConfig.icon} {typeConfig.label}
                        </Badge>
                    </Box>
                    <Box>
                        <Text size="1" color="gray" mb="1" as="div">
                            Duration
                        </Text>
                        <Text size="2" weight="bold">
                            {days} {days === 1 ? 'day' : 'days'}
                        </Text>
                    </Box>
                    <Box>
                        <Text size="1" color="gray" mb="1" as="div">
                            Start Date
                        </Text>
                        <Text size="2" weight="medium">
                            {format(new Date(holiday.from_date), 'MMM dd, yyyy')}
                        </Text>
                    </Box>
                    {holiday.from_date !== holiday.to_date && (
                        <Box>
                            <Text size="1" color="gray" mb="1" as="div">
                                End Date
                            </Text>
                            <Text size="2" weight="medium">
                                {format(new Date(holiday.to_date), 'MMM dd, yyyy')}
                            </Text>
                        </Box>
                    )}
                </Flex>
            </Card>
        );
    };

    if (holidaysData.length === 0) {
        return (
            <Card style={{ padding: 48, textAlign: 'center' }}>
                <CalendarIcon style={{ width: 64, height: 64, color: 'var(--gray-8)', margin: '0 auto 16px auto' }} />
                <Text size="4" weight="bold" mb="2" as="div">
                    No Holidays Found
                </Text>
                <Text size="2" color="gray">
                    No company holidays have been configured yet.
                </Text>
            </Card>
        );
    }

    if (isMobile) {
        return (
            <Box style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                {topContent}
                <ScrollArea type="auto" scrollbars="horizontal">
                    <Box style={{ minWidth: 320 }}>
                        {isLoading ? (
                            <Flex justify="center" py="8">
                                <Spinner size="3" />
                            </Flex>
                        ) : (
                            items.map((holiday) => (
                                <MobileHolidayCard key={holiday.id} holiday={holiday} />
                            ))
                        )}
                    </Box>
                </ScrollArea>
                {filteredHolidays.length > rowsPerPage && (
                    <TablePagination
                        pagination={{
                            currentPage: page,
                            perPage: rowsPerPage,
                            total: filteredHolidays.length,
                        }}
                        onPageChange={setPage}
                        onRowsPerPageChange={setRowsPerPage}
                    />
                )}
            </Box>
        );
    }

    return (
        <Box style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            {topContent}
            <ScrollArea type="auto" scrollbars="horizontal" style={{ maxHeight: '70vh' }}>
                {isLoading ? (
                    <Flex justify="center" py="8" align="center">
                        <Spinner size="3" />
                        <Text ml="2">Loading holidays...</Text>
                    </Flex>
                ) : (
                    <Table.Root variant="surface" style={{ minWidth: 720 }}>
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
                            {items.length === 0 ? (
                                <Table.Row>
                                    <Table.Cell colSpan={columns.length}>
                                        <Flex direction="column" align="center" py="6" gap="2">
                                            <CalendarIcon
                                                style={{ width: 48, height: 48, color: 'var(--gray-8)' }}
                                            />
                                            <Text size="2" weight="medium">
                                                No holidays found
                                            </Text>
                                            <Text size="1" color="gray">
                                                Try adjusting your filters or add a new holiday
                                            </Text>
                                        </Flex>
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
                )}
            </ScrollArea>
            {filteredHolidays.length > rowsPerPage && (
                <TablePagination
                    pagination={{
                        currentPage: page,
                        perPage: rowsPerPage,
                        total: filteredHolidays.length,
                    }}
                    onPageChange={setPage}
                    onRowsPerPageChange={setRowsPerPage}
                />
            )}
        </Box>
    );
};

export default HolidayTable;