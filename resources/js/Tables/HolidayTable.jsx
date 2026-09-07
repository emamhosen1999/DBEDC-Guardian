import { Panel } from '@/Components/ui/Panel';
import React, { useState, useMemo, useCallback, useEffect } from 'react';
import { Table, Badge, Button, Flex, Text, Box, TextField, ScrollArea, DropdownMenu, IconButton, Spinner } from '@radix-ui/themes';
import {
    CalendarIcon,
    MagnifyingGlassIcon,
    Pencil2Icon,
    TrashIcon,
    ClockIcon,
    CheckCircledIcon,
    MixerHorizontalIcon,
    DotsVerticalIcon,
    Cross2Icon,
} from '@radix-ui/react-icons';
import { format, differenceInDays, isAfter, isBefore } from 'date-fns';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import TablePagination from '@/Components/TablePagination.jsx';
import { TableLoadingSkeleton } from '@/Components/LoadingSkeleton';
import SearchFilterBar from '@/Components/SearchFilterBar';
import PageToolbar from '@/Components/PageToolbar';

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
    canEdit = false,
    canDelete = false,
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
        ...(canEdit || canDelete ? [{ name: 'Actions', uid: 'actions' }] : []),
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
                {canEdit && (
                    <DropdownMenu.Item onClick={() => onEdit?.(holiday)}>
                        <Pencil2Icon style={{ width: 16, height: 16, marginRight: 8 }} /> Edit Holiday
                    </DropdownMenu.Item>
                )}
                {canDelete && (
                    <DropdownMenu.Item color="red" onClick={() => onDelete?.(holiday.id)}>
                        <TrashIcon style={{ width: 16, height: 16, marginRight: 8 }} /> Delete Holiday
                    </DropdownMenu.Item>
                )}
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
                            <Flex align="center" gap="2" wrap="wrap">
                                <Text size="2" weight="bold" style={{ textTransform: 'capitalize' }}>
                                    {holiday.title}
                                </Text>
                                {holiday.is_recurring && (
                                    <Badge color="indigo" variant="soft" size="1">🔁 Annual</Badge>
                                )}
                                {holiday.is_active === false && (
                                    <Badge color="gray" variant="soft" size="1">Inactive</Badge>
                                )}
                            </Flex>
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
        [getHolidayStatus, onEdit, onDelete, canEdit, canDelete],
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

    const activeFilterChips = useMemo(() => {
        const chips = [];
        if (filterValue) {
            chips.push({ label: 'Search', value: filterValue, onRemove: () => setFilterValue('') });
        }
        typeFilter.forEach((type) => {
            chips.push({
                key: `type-${type}`,
                label: 'Type',
                value: `${holidayTypes[type]?.icon} ${holidayTypes[type]?.label}`,
                onRemove: () => setTypeFilter((prev) => prev.filter((t) => t !== type)),
            });
        });
        statusFilter.forEach((status) => {
            chips.push({
                key: `status-${status}`,
                label: 'Status',
                value: status.charAt(0).toUpperCase() + status.slice(1),
                onRemove: () => setStatusFilter((prev) => prev.filter((s) => s !== status)),
            });
        });
        yearFilter.forEach((year) => {
            chips.push({
                key: `year-${year}`,
                label: 'Year',
                value: year,
                onRemove: () => setYearFilter((prev) => prev.filter((y) => y !== year)),
            });
        });
        return chips;
    }, [filterValue, typeFilter, statusFilter, yearFilter]);

    const clearAllFilters = () => {
        setFilterValue('');
        setTypeFilter([]);
        setStatusFilter([]);
        setYearFilter([new Date().getFullYear().toString()]);
        setPage(1);
    };

    const topContent = (
        <Box mb="3">
            <PageToolbar
                perPage={rowsPerPage}
                onPerPageChange={(n) => { setRowsPerPage(n); setPage(1); }}
                perPageOptions={[5, 10, 15, 25]}
                leftSlot={
                    <SearchFilterBar
                        searchValue={filterValue}
                        onSearchChange={setFilterValue}
                        searchPlaceholder="Search holiday title, description..."
                        showFilterToggle
                        showFilters={showFilters}
                        onToggleFilters={() => setShowFilters((v) => !v)}
                        activeFiltersCount={activeFilterChips.length}
                        activeFilterChips={activeFilterChips}
                        onClearFilters={activeFilterChips.length > 0 ? clearAllFilters : null}
                        mb="0"
                    >
                        <Flex direction="column" gap="3">
                            <Box>
                                <Text size="2" weight="medium" mb="2" as="div">
                                    Holiday Type
                                </Text>
                                <Flex gap="2" wrap="wrap">
                                    {Object.entries(holidayTypes).map(([key, config]) => (
                                        <Badge
                                            key={key}
                                            color={config.color}
                                            variant={typeFilter.includes(key) ? 'solid' : 'soft'}
                                            size="1"
                                            style={{ cursor: 'pointer', borderRadius: 8 }}
                                            onClick={() => toggleInArray(setTypeFilter, key)}
                                        >
                                            {config.icon} {config.label}
                                        </Badge>
                                    ))}
                                </Flex>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" mb="2" as="div">
                                    Status
                                </Text>
                                <Flex gap="2" wrap="wrap">
                                    {statusOptions.map((opt) => (
                                        <Badge
                                            key={opt.key}
                                            color={opt.color}
                                            variant={statusFilter.includes(opt.key) ? 'solid' : 'soft'}
                                            size="1"
                                            style={{ cursor: 'pointer', borderRadius: 8 }}
                                            onClick={() => toggleInArray(setStatusFilter, opt.key)}
                                        >
                                            <opt.icon style={{ width: 12, height: 12, marginRight: 4 }} /> {opt.label}
                                        </Badge>
                                    ))}
                                </Flex>
                            </Box>

                            <Box>
                                <Text size="2" weight="medium" mb="2" as="div">
                                    Year
                                </Text>
                                <Flex gap="2" wrap="wrap">
                                    {yearOptions.map((year) => (
                                        <Badge
                                            key={year}
                                            variant={yearFilter.includes(year) ? 'solid' : 'soft'}
                                            size="1"
                                            style={{ cursor: 'pointer', borderRadius: 8 }}
                                            onClick={() => toggleInArray(setYearFilter, year)}
                                        >
                                            📅 {year}
                                        </Badge>
                                    ))}
                                </Flex>
                            </Box>
                        </Flex>
                    </SearchFilterBar>
                }
            />

            <Text size="1" color="gray">
                Total {filteredHolidays.length} holidays
                {(typeFilter.length > 0 || statusFilter.length > 0 || filterValue) &&
                    ` (filtered from ${holidaysData.length})`}
            </Text>
        </Box>
    );

    const MobileHolidayCard = ({ holiday }) => {
        const typeConfig = holidayTypes[holiday.type] || holidayTypes.company;
        const statusConfig = getHolidayStatus(holiday);
        const StatusIcon = statusConfig.icon;
        const days = differenceInDays(new Date(holiday.to_date), new Date(holiday.from_date)) + 1;

        return (
            <Panel tinted mb="2" style={{ borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '16px' }}>
                <Flex justify="between" align="start" gap="2" mb="3">
                    <Box style={{ flex: 1 }}>
                        <Text size="3" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, color: 'var(--gray-12)' }}>
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
                        <Badge color={statusConfig.color} variant="soft" size="1" style={{ borderRadius: 999 }}>
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
                        <Badge color={typeConfig.color} variant="soft" size="1" style={{ borderRadius: 999 }}>
                            {typeConfig.icon} {typeConfig.label}
                        </Badge>
                    </Box>
                    <Box>
                        <Text size="1" color="gray" mb="1" as="div">
                            Duration
                        </Text>
                        <Text size="2" weight="bold" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums' }}>
                            {days} {days === 1 ? 'day' : 'days'}
                        </Text>
                    </Box>
                    <Box>
                        <Text size="1" color="gray" mb="1" as="div">
                            Start Date
                        </Text>
                        <Text size="2" weight="medium" style={{ fontVariantNumeric: 'tabular-nums' }}>
                            {format(new Date(holiday.from_date), 'MMM dd, yyyy')}
                        </Text>
                    </Box>
                    {holiday.from_date !== holiday.to_date && (
                        <Box>
                            <Text size="1" color="gray" mb="1" as="div">
                                End Date
                            </Text>
                            <Text size="2" weight="medium" style={{ fontVariantNumeric: 'tabular-nums' }}>
                                {format(new Date(holiday.to_date), 'MMM dd, yyyy')}
                            </Text>
                        </Box>
                    )}
                </Flex>
            </Panel>
        );
    };

    if (holidaysData.length === 0) {
        return (
            <Panel tinted style={{ padding: 48, textAlign: 'center', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                <CalendarIcon style={{ width: 64, height: 64, color: 'var(--gray-8)', margin: '0 auto 16px auto' }} />
                <Text size="4" weight="bold" mb="2" as="div" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>
                    No Holidays Found
                </Text>
                <Text size="2" color="gray">
                    No company holidays have been configured yet.
                </Text>
            </Panel>
        );
    }

    if (isMobile) {
        return (
            <Box style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                {topContent}
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
            {isLoading ? (
                <TableLoadingSkeleton rows={rowsPerPage || 5} cols={columns.length} />
            ) : (
                <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                    <Table.Root size="2" style={{ minWidth: 840, width: '100%' }}>
                        <Table.Header style={{
                            position: 'sticky',
                            top: 0,
                            zIndex: 2,
                            background: 'var(--aero-surface, var(--color-background))',
                            backdropFilter: 'blur(8px)',
                            boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                        }}>
                            <Table.Row>
                                {columns.map((col) => (
                                    <Table.ColumnHeaderCell
                                        key={col.uid}
                                        justify={col.uid === 'actions' ? 'end' : 'start'}
                                        style={{
                                            minWidth: col.uid === 'title' ? 260 : col.uid === 'duration' ? 120 : col.uid === 'type' ? 130 : col.uid === 'status' ? 130 : 80,
                                            whiteSpace: 'nowrap',
                                            background: 'inherit'
                                        }}
                                    >
                                        <Text size="1" weight="bold" style={{ whiteSpace: 'nowrap' }}>{col.name}</Text>
                                    </Table.ColumnHeaderCell>
                                ))}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {items.length === 0 ? (
                                <Table.Row>
                                    <Table.Cell colSpan={columns.length} style={{ textAlign: 'center', padding: '32px' }}>
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
                                    <Table.Row key={item.id} align="center">
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
                </Box>
            )}
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
