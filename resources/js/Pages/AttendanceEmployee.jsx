import React, { useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
  Box, Flex, Text, Card, Separator, TextField
} from '@radix-ui/themes';
import { DashboardIcon, CalendarIcon } from '@radix-ui/react-icons';
import dayjs from 'dayjs';

import App from "@/Layouts/App.jsx";
import AttendanceEmployeeTable from "@/Tables/AttendanceEmployeeTable.jsx";
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import AttendanceOverview from './Attendance/Components/AttendanceOverview';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

const AttendanceEmployee = React.memo(({ title }) => {
  usePage();
  const isDesktop = useMediaQuery('(min-width: 1025px)');
  
  const [selectedDate, setSelectedDate] = useState(dayjs().format('YYYY-MM-DD'));
  const [updateTimeSheet, setUpdateTimeSheet] = useState(false);
  
  const [filterData, setFilterData] = useState({
    currentMonth: dayjs().format('YYYY-MM'),
  });

  const handleDateChange = useCallback((event) => {
    const newDate = event.target.value;
    if (newDate) {
      setSelectedDate(newDate);
      setFilterData(prev => ({ ...prev, currentMonth: dayjs(newDate).format('YYYY-MM') }));
    }
  }, []);

  const handleFilterChange = useCallback((key, value) => {
    setFilterData(prevState => ({
      ...prevState,
      [key]: value,
    }));
    
    // Sync the exact selected date to the 1st of the new month to drive the overview/table updates
    if (key === 'currentMonth' && value) {
      setSelectedDate(dayjs(value).startOf('month').format('YYYY-MM-DD'));
    }
  }, []);

  return (
    <>
      <Head title={title || "My Attendance"} />
      
      <Flex justify="center" p={{ initial: '3', md: '4' }}>
        <Box style={{ width: '100%', maxWidth: 2000 }}>
          <Card>
            
            {/* ── Page Header ── */}
            <Box mb="4">
              <Flex
                direction={{ initial: 'column', md: 'row' }}
                align={{ initial: 'start', md: 'center' }}
                justify="between"
                gap="4"
              >
                <Flex align="center" gap="3">
                  <Box
                    p={{ initial: '2', md: '3' }}
                    style={{
                      background: 'var(--accent-a3)',
                      borderRadius: 'var(--radius-2)',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    <DashboardIcon
                      width={isDesktop ? 28 : 20}
                      height={isDesktop ? 28 : 20}
                      color="var(--accent-9)"
                    />
                  </Box>
                  <Box>
                    <Text
                      size={{ initial: '4', sm: '5', md: '6' }}
                      weight="bold"
                      as="div"
                    >
                      My Attendance
                    </Text>
                    <Text
                      size={{ initial: '1', md: '2' }}
                      color="gray"
                      as="div"
                    >
                      View your attendance records and timesheet details
                    </Text>
                  </Box>
                </Flex>

                <Flex align="center" gap="2" wrap="wrap">
                  <TextField.Root
                    type="month"
                    size="2"
                    value={filterData.currentMonth}
                    onChange={(e) => handleFilterChange('currentMonth', e.target.value)}
                    style={{ width: 160 }}
                  >
                    <TextField.Slot><CalendarIcon /></TextField.Slot>
                  </TextField.Root>
                </Flex>
              </Flex>
            </Box>

            {/* ── Attendance Overview ── */}
            <ErrorBoundary>
              <AttendanceOverview mode="monthly" scope="self" month={filterData.currentMonth} />
            </ErrorBoundary>

            <Separator size="4" mb="4" mt="4" />

            {/* ── Attendance Table ── */}
            <Box>
              <ErrorBoundary>
                <AttendanceEmployeeTable
                  selectedDate={selectedDate}
                  handleDateChange={handleDateChange}
                  updateTimeSheet={updateTimeSheet}
                  externalFilterData={filterData}
                />
              </ErrorBoundary>
            </Box>

          </Card>
        </Box>
      </Flex>
    </>
  );
});

AttendanceEmployee.layout = (page) => <App>{page}</App>;
export default AttendanceEmployee;