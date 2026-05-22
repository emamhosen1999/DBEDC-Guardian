import React, { useCallback, useEffect, useState, useMemo } from 'react';
import {
    Table,
    ScrollArea,
    Card,
    Flex,
    Text,
    Box,
    Spinner,
} from '@radix-ui/themes';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    CalendarDaysIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import axios from 'axios';
import TablePagination from '@/Components/TablePagination.jsx';

const AttendanceEmployeeTable = ({
    handleDateChange,
    selectedDate,
    updateTimeSheet,
    externalFilterData,
    externalEmployee,
}) => {
    usePage();

    const isMobile = useMediaQuery('(max-width: 640px)');

    const [attendances, setAttendances] = useState([]);
    const [error, setError] = useState('');
    const [totalRows, setTotalRows] = useState(0);
    const [perPage, setPerPage] = useState(10);
    const [currentPage, setCurrentPage] = useState(1);
    const [employee, setEmployee] = useState(externalEmployee || '');
    const [isLoaded, setIsLoaded] = useState(false);

    const filterData = useMemo(
        () => externalFilterData || { currentMonth: dayjs().format('YYYY-MM') },
        [externalFilterData],
    );

    const formatTime = useCallback((timeString, date) => {
        if (!timeString) return null;

        const dateStr = dayjs(date).format('YYYY-MM-DD');
        let dt;

        if (timeString.match(/^\d{2}:\d{2}(:\d{2})?$/)) {
            dt = dayjs(`${dateStr}T${timeString}`);
        } else {
            dt = dayjs(timeString);
        }

        if (dt.isValid()) {
            return dt.format('h:mm A');
        }
        return 'Invalid Time';
    }, []);

    const getAttendances = useCallback(
        async (isRefresh = false) => {
            if (!selectedDate) {
                setIsLoaded(true);
                setError('No date selected');
                return;
            }

            setIsLoaded(false);
            setError('');

            const attendanceRoute = route('getCurrentUserAttendanceForDate');

            try {
                const response = await axios.get(attendanceRoute, {
                    params: {
                        page: currentPage,
                        perPage,
                        employee,
                        date: dayjs(selectedDate).format('YYYY-MM-DD'),
                        currentYear: filterData?.currentMonth
                            ? dayjs(filterData.currentMonth).year()
                            : '',
                        currentMonth: filterData?.currentMonth
                            ? dayjs(filterData.currentMonth).format('MM')
                            : '',
                        _t: isRefresh ? Date.now() : undefined,
                    },
                });

                if (response.status === 200) {
                    setAttendances(response.data.attendances || []);
                    setTotalRows(response.data.total || 0);
                    if (response.data.current_page) {
                        setCurrentPage(response.data.current_page);
                    }
                    setError('');
                } else {
                    setError(`Unexpected response: ${response.status}`);
                }
            } catch (err) {
                console.error('Error fetching attendance data:', err);
                setError(
                    err.response?.data?.message ||
                        'An error occurred while retrieving attendance data.',
                );
                setAttendances([]);
                setTotalRows(0);
            } finally {
                setIsLoaded(true);
            }
        },
        [selectedDate, currentPage, perPage, employee, filterData],
    );

    useEffect(() => {
        getAttendances();
    }, [getAttendances, updateTimeSheet]);

    useEffect(() => {
        setCurrentPage(1);
    }, [selectedDate, filterData.currentMonth]);

    const handlePageChange = (page) => {
        setCurrentPage(page);
    };

    const columns = [
        { name: 'Date', uid: 'date', icon: CalendarDaysIcon },
        { name: 'Clock In', uid: 'clockin_time', icon: ClockIcon },
        { name: 'Clock Out', uid: 'clockout_time', icon: ClockIcon },
        { name: 'Work Hours', uid: 'production_time', icon: ClockIcon },
        { name: 'Punches', uid: 'punch_details', icon: ClockIcon },
    ];

    const renderCell = useCallback(
        (attendance, columnKey) => {
            const isCurrentDate = dayjs(attendance.date).isSame(dayjs(), 'day');

            switch (columnKey) {
                case 'date':
                    return (
                        <Flex align="center" gap="2">
                            <CalendarDaysIcon className="w-4 h-4 shrink-0" style={{ color: 'var(--accent-9)' }} />
                            <Text size="2">{dayjs(attendance.date).format('MMM D, YYYY')}</Text>
                        </Flex>
                    );
                case 'clockin_time':
                    return (
                        <Flex align="center" gap="2">
                            <ClockIcon className="w-4 h-4 shrink-0" style={{ color: 'var(--green-9)' }} />
                            <Flex direction="column" gap="1">
                                {attendance.punches?.filter((p) => p.punch_in).length > 0 ? (
                                    attendance.punches
                                        .filter((p) => p.punch_in)
                                        .map((punch, index) => (
                                            <Text key={index} size="1">
                                                <Text size="1" color="gray" as="span">
                                                    {index + 1}.{' '}
                                                </Text>
                                                {formatTime(punch.punch_in, attendance.date) || 'Invalid time'}
                                            </Text>
                                        ))
                                ) : (
                                    <Text size="2">Not clocked in</Text>
                                )}
                            </Flex>
                        </Flex>
                    );
                case 'clockout_time':
                    return (
                        <Flex align="center" gap="2">
                            <ClockIcon className="w-4 h-4 shrink-0" style={{ color: 'var(--red-9)' }} />
                            <Flex direction="column" gap="1">
                                {attendance.punches?.length > 0 ? (
                                    attendance.punches.map((punch, index) => (
                                        <Text key={index} size="1">
                                            <Text size="1" color="gray" as="span">
                                                {index + 1}.{' '}
                                            </Text>
                                            {punch.punch_out
                                                ? formatTime(punch.punch_out, attendance.date) || 'Invalid time'
                                                : 'No punch out'}
                                        </Text>
                                    ))
                                ) : attendance.punchin_time ? (
                                    <Text size="2">
                                        {isCurrentDate ? 'Currently working' : 'Missing punch-out'}
                                    </Text>
                                ) : (
                                    <Text size="2">Not started</Text>
                                )}
                            </Flex>
                        </Flex>
                    );
                case 'production_time': {
                    const hasWorkTime = attendance.total_work_minutes > 0;
                    const hasIncompletePunch = attendance.has_incomplete_punch;
                    const isCurrentlyWorking =
                        attendance.punchin_time && !attendance.punchout_time && isCurrentDate;

                    if (hasWorkTime) {
                        const hours = Math.floor(attendance.total_work_minutes / 60);
                        const minutes = Math.floor(attendance.total_work_minutes % 60);
                        return (
                            <Flex align="center" gap="2">
                                <ClockIcon
                                    className="w-4 h-4"
                                    style={{
                                        color: hasIncompletePunch ? 'var(--amber-9)' : 'var(--accent-9)',
                                    }}
                                />
                                <Box>
                                    <Text size="2" weight="medium">{`${hours}h ${minutes}m`}</Text>
                                    <Text size="1" color="gray">
                                        {hasIncompletePunch
                                            ? 'Partial data - in progress'
                                            : 'Total worked time'}
                                    </Text>
                                </Box>
                            </Flex>
                        );
                    }
                    if (isCurrentlyWorking) {
                        return (
                            <Flex align="center" gap="2">
                                <ClockIcon className="w-4 h-4" style={{ color: 'var(--amber-9)' }} />
                                <Box>
                                    <Text size="2" color="amber">In Progress</Text>
                                    <Text size="1" color="gray">Currently working</Text>
                                </Box>
                            </Flex>
                        );
                    }
                    if (attendance.punchin_time && !attendance.punchout_time && !isCurrentDate) {
                        return (
                            <Flex align="center" gap="2">
                                <ExclamationTriangleIcon className="w-4 h-4" style={{ color: 'var(--red-9)' }} />
                                <Box>
                                    <Text size="2" color="red">Incomplete punch</Text>
                                    <Text size="1" color="gray">Missing punch out</Text>
                                </Box>
                            </Flex>
                        );
                    }
                    return (
                        <Flex align="center" gap="2">
                            <ExclamationTriangleIcon className="w-4 h-4" style={{ color: 'var(--amber-9)' }} />
                            <Box>
                                <Text size="2" color="amber">No work time</Text>
                                <Text size="1" color="gray">No attendance</Text>
                            </Box>
                        </Flex>
                    );
                }
                case 'punch_details':
                    return (
                        <Flex align="center" gap="2">
                            <ClockIcon className="w-4 h-4" style={{ color: 'var(--gray-9)' }} />
                            <Box>
                                <Text size="1" weight="medium">
                                    {attendance.punch_count || 0} punch
                                    {(attendance.punch_count || 0) !== 1 ? 'es' : ''}
                                </Text>
                                {attendance.complete_punches !== attendance.punch_count && (
                                    <Text size="1" color="amber">
                                        {attendance.complete_punches} complete
                                    </Text>
                                )}
                                {attendance.complete_punches === attendance.punch_count &&
                                    attendance.punch_count > 0 && (
                                        <Text size="1" color="green">
                                            All complete
                                        </Text>
                                    )}
                            </Box>
                        </Flex>
                    );
                default:
                    return <Text size="2">N/A</Text>;
            }
        },
        [formatTime],
    );

    const emptyState = (
        <Flex direction="column" align="center" justify="center" py="8" gap="2">
            <ClockIcon className="w-12 h-12" style={{ color: 'var(--gray-8)' }} />
            <Text size="3" weight="medium">No Attendance Records</Text>
            <Text size="2" color="gray">No attendance records found for the selected date</Text>
        </Flex>
    );

    return (
        <Box role="region" aria-label="Attendance data table" className="w-full">
            {error ? (
                <Card
                    style={{
                        padding: 16,
                        background: 'color-mix(in srgb, var(--red-9) 10%, transparent)',
                        border: '1px solid color-mix(in srgb, var(--red-9) 25%, transparent)',
                    }}
                >
                    <Flex align="center" gap="3">
                        <ExclamationTriangleIcon className="w-5 h-5" style={{ color: 'var(--red-9)' }} />
                        <Text size="2" color="red">{error}</Text>
                    </Flex>
                </Card>
            ) : (
                <>
                    <ScrollArea
                        type="auto"
                        scrollbars={isMobile ? 'horizontal' : 'vertical'}
                        style={{ maxHeight: '70vh' }}
                    >
                        {!isLoaded ? (
                            <Flex justify="center" py="8">
                                <Spinner size="3" />
                            </Flex>
                        ) : (
                            <Table.Root
                                variant="surface"
                                size="1"
                                style={{ minWidth: isMobile ? 720 : '100%' }}
                            >
                                <Table.Header>
                                    <Table.Row>
                                        {columns.map((column) => (
                                            <Table.ColumnHeaderCell key={column.uid}>
                                                <Flex align="center" gap="2">
                                                    {column.icon && <column.icon className="w-4 h-4" />}
                                                    <Text size="2" weight="medium">
                                                        {column.name}
                                                    </Text>
                                                </Flex>
                                            </Table.ColumnHeaderCell>
                                        ))}
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {attendances.length === 0 ? (
                                        <Table.Row>
                                            <Table.Cell colSpan={columns.length}>
                                                {emptyState}
                                            </Table.Cell>
                                        </Table.Row>
                                    ) : (
                                        attendances.map((attendance) => (
                                            <Table.Row key={attendance.id || attendance.user_id}>
                                                {columns.map((col) => (
                                                    <Table.Cell key={col.uid}>
                                                        {renderCell(attendance, col.uid)}
                                                    </Table.Cell>
                                                ))}
                                            </Table.Row>
                                        ))
                                    )}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </ScrollArea>
                    {totalRows > perPage && (
                        <TablePagination
                            pagination={{ currentPage, perPage, total: totalRows }}
                            onPageChange={handlePageChange}
                        />
                    )}
                </>
            )}
        </Box>
    );
};

export default AttendanceEmployeeTable;
