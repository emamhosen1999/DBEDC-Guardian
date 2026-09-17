import { Panel } from '@/Components/ui/Panel';
import React, { useCallback, useEffect, useState, useMemo, useRef } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Table, ScrollArea, Flex, Text, Box, Spinner } from '@radix-ui/themes';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
  CalendarIcon, ClockIcon, ExclamationTriangleIcon,
} from '@radix-ui/react-icons';
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
  /* Page and size live in the URL under an `ae_` prefix; the fetch effect
     follows them. */
  const f = useQueryFilters({ mode: 'client', pageKey: 'ae_page', debounceKeys: [], defaults: { ae_page: 1, ae_per: 10 } });
  const perPage = f.values.ae_per;
  const currentPage = f.values.ae_page;
  const setCurrentPage = f.setPage;
  const setPerPage = (n) => f.set('ae_per', n);
  const [employee, setEmployee] = useState(externalEmployee || '');
  const [isLoaded, setIsLoaded] = useState(false);

  const filterData = useMemo(
    () => externalFilterData || { currentMonth: dayjs().format('YYYY-MM') },
    [externalFilterData]
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

    if (dt.isValid()) return dt.format('h:mm A');
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
            currentYear: filterData?.currentMonth ? dayjs(filterData.currentMonth).year() : '',
            currentMonth: filterData?.currentMonth ? dayjs(filterData.currentMonth).format('MM') : '',
            _t: isRefresh ? Date.now() : undefined,
          },
        });

        if (response.status === 200) {
          setAttendances(response.data.attendances || []);
          setTotalRows(response.data.total || 0);
          setError('');
        } else {
          setError(`Unexpected response: ${response.status}`);
        }
      } catch (err) {
        console.error('Error fetching attendance data:', err);
        setError(
          err.response?.data?.message || 'An error occurred while retrieving attendance data.'
        );
        setAttendances([]);
        setTotalRows(0);
      } finally {
        setIsLoaded(true);
      }
    },
    [selectedDate, currentPage, perPage, employee, filterData]
  );

  useEffect(() => {
    getAttendances();
  }, [getAttendances, updateTimeSheet]);

  // The day/month come from the page above; return to page 1 when they move,
  // but not on mount so a restored page survives.
  const externalKeyRef = useRef(`${selectedDate}|${filterData.currentMonth}`);
  useEffect(() => {
    const key = `${selectedDate}|${filterData.currentMonth}`;
    if (externalKeyRef.current === key) return;
    externalKeyRef.current = key;
    if (f.values.ae_page !== 1) f.setPage(1);
  }, [selectedDate, filterData.currentMonth]); // eslint-disable-line react-hooks/exhaustive-deps

  const handlePageChange = (page) => {
    setCurrentPage(page);
  };

  const columns = [
    { name: 'Date', uid: 'date', icon: CalendarIcon },
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
              <CalendarIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--accent-9)' }} />
              <Text size="2">{dayjs(attendance.date).format('MMM D, YYYY')}</Text>
            </Flex>
          );
        case 'clockin_time':
          return (
            <Flex align="center" gap="2">
              <ClockIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--green-9)' }} />
              <Flex direction="column" gap="1">
                {attendance.punches?.filter((p) => p.punch_in).length > 0 ? (
                  attendance.punches
                    .filter((p) => p.punch_in)
                    .map((punch, index) => (
                      <Text key={index} size="1">
                        <Text size="1" color="gray" as="span">{index + 1}. </Text>
                        {formatTime(punch.punch_in, attendance.date) || 'Invalid time'}
                      </Text>
                    ))
                ) : (
                  <Text size="2" color="gray">Not clocked in</Text>
                )}
              </Flex>
            </Flex>
          );
        case 'clockout_time':
          return (
            <Flex align="center" gap="2">
              <ClockIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--red-9)' }} />
              <Flex direction="column" gap="1">
                {attendance.punches?.length > 0 ? (
                  attendance.punches.map((punch, index) => (
                    <Text key={index} size="1">
                      <Text size="1" color="gray" as="span">{index + 1}. </Text>
                      {punch.punch_out
                        ? formatTime(punch.punch_out, attendance.date) || 'Invalid time'
                        : 'No punch out'}
                    </Text>
                  ))
                ) : attendance.punchin_time ? (
                  <Text size="2" color={isCurrentDate ? "amber" : "red"}>
                    {isCurrentDate ? 'Currently working' : 'Missing punch-out'}
                  </Text>
                ) : (
                  <Text size="2" color="gray">Not started</Text>
                )}
              </Flex>
            </Flex>
          );
        case 'production_time': {
          const hasWorkTime = attendance.total_work_minutes > 0;
          const hasIncompletePunch = attendance.has_incomplete_punch;
          const isCurrentlyWorking = attendance.punchin_time && !attendance.punchout_time && isCurrentDate;

          if (hasWorkTime) {
            const hours = Math.floor(attendance.total_work_minutes / 60);
            const minutes = Math.floor(attendance.total_work_minutes % 60);
            return (
              <Flex align="center" gap="2">
                <ClockIcon
                  style={{
                    width: 16, height: 16, flexShrink: 0,
                    color: hasIncompletePunch ? 'var(--amber-9)' : 'var(--accent-9)',
                  }}
                />
                <Box>
                  <Text size="2" weight="medium">{`${hours}h ${minutes}m`}</Text>
                  <Text size="1" color="gray" as="div">
                    {hasIncompletePunch ? 'Partial data - in progress' : 'Total worked time'}
                  </Text>
                </Box>
              </Flex>
            );
          }
          if (isCurrentlyWorking) {
            return (
              <Flex align="center" gap="2">
                <ClockIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--amber-9)' }} />
                <Box>
                  <Text size="2" color="amber">In Progress</Text>
                  <Text size="1" color="gray" as="div">Currently working</Text>
                </Box>
              </Flex>
            );
          }
          if (attendance.punchin_time && !attendance.punchout_time && !isCurrentDate) {
            return (
              <Flex align="center" gap="2">
                <ExclamationTriangleIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--red-9)' }} />
                <Box>
                  <Text size="2" color="red">Incomplete punch</Text>
                  <Text size="1" color="gray" as="div">Missing punch out</Text>
                </Box>
              </Flex>
            );
          }
          return (
            <Flex align="center" gap="2">
              <ExclamationTriangleIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--amber-9)' }} />
              <Box>
                <Text size="2" color="amber">No work time</Text>
                <Text size="1" color="gray" as="div">No attendance</Text>
              </Box>
            </Flex>
          );
        }
        case 'punch_details':
          return (
            <Flex align="center" gap="2">
              <ClockIcon style={{ width: 16, height: 16, flexShrink: 0, color: 'var(--gray-9)' }} />
              <Box>
                <Text size="1" weight="medium" as="div">
                  {attendance.punch_count || 0} punch{(attendance.punch_count || 0) !== 1 ? 'es' : ''}
                </Text>
                {attendance.complete_punches !== attendance.punch_count && (
                  <Text size="1" color="amber" as="div">
                    {attendance.complete_punches} complete
                  </Text>
                )}
                {attendance.complete_punches === attendance.punch_count && attendance.punch_count > 0 && (
                  <Text size="1" color="green" as="div">All complete</Text>
                )}
              </Box>
            </Flex>
          );
        default:
          return <Text size="2">N/A</Text>;
      }
    },
    [formatTime]
  );

  const emptyState = (
    <Flex direction="column" align="center" justify="center" py="8" gap="2">
      <ClockIcon style={{ width: 48, height: 48, color: 'var(--gray-8)' }} />
      <Text size="3" weight="medium">No Attendance Records</Text>
      <Text size="2" color="gray">No attendance records found for the selected date.</Text>
    </Flex>
  );

  return (
    <Box style={{ width: '100%' }}>
      {error ? (
        <Panel tinted style={{ background: 'var(--red-a2)', borderRadius: 16, border: '1px solid var(--red-a4)' }}>
          <Flex align="center" gap="3">
            <ExclamationTriangleIcon style={{ width: 20, height: 20, color: 'var(--red-9)' }} />
            <Text size="2" color="red">{error}</Text>
          </Flex>
        </Panel>
      ) : (
        <>
          <Box style={{ 
            overflowX: 'auto', 
            WebkitOverflowScrolling: 'touch', 
            borderRadius: 16, 
            border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))',
            position: 'relative'
          }}>
            {!isLoaded && attendances.length === 0 ? (
              <Flex justify="center" py="8" align="center" gap="2">
                <Spinner size="3" />
                <Text size="2" color="gray">Loading attendance records...</Text>
              </Flex>
            ) : (
              <Table.Root
                size="2"
                style={{ 
                  minWidth: 840, 
                  width: '100%',
                  opacity: !isLoaded ? 0.6 : 1,
                  transition: 'opacity 0.2s ease',
                }}
              >
                <Table.Header style={{
                  position: 'sticky',
                  top: 0,
                  zIndex: 2,
                  background: 'var(--aero-surface, var(--color-background))',
                  backdropFilter: 'blur(8px)',
                  boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                }}>
                  <Table.Row>
                    {columns.map((column) => (
                      <Table.ColumnHeaderCell 
                        key={column.uid}
                        style={{
                          minWidth: column.uid === 'employee' ? 220 : column.uid === 'date' ? 140 : column.uid === 'in_time' || column.uid === 'out_time' ? 120 : column.uid === 'duration' ? 110 : column.uid === 'status' ? 120 : 130,
                          whiteSpace: 'nowrap',
                          background: 'inherit'
                        }}
                      >
                        <Flex align="center" gap="2">
                          {column.icon && <column.icon style={{ width: 14, height: 14, flexShrink: 0 }} />}
                          <Text size="1" weight="bold" style={{ whiteSpace: 'nowrap' }}>{column.name}</Text>
                        </Flex>
                      </Table.ColumnHeaderCell>
                    ))}
                  </Table.Row>
                </Table.Header>
                
                <Table.Body>
                  {attendances.length === 0 ? (
                    <Table.Row>
                      <Table.Cell colSpan={columns.length} style={{ textAlign: 'center', padding: '32px' }}>
                        {emptyState}
                      </Table.Cell>
                    </Table.Row>
                  ) : (
                    attendances.map((attendance) => (
                      <Table.Row key={attendance.id || attendance.user_id} align="center">
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
          </Box>
          
          <TablePagination
            pagination={{ currentPage, perPage, total: totalRows }}
            onPageChange={(p) => { setCurrentPage(p); }}
            onRowsPerPageChange={setPerPage}
            loading={!isLoaded}
          />
        </>
      )}
    </Box>
  );
};

export default AttendanceEmployeeTable;