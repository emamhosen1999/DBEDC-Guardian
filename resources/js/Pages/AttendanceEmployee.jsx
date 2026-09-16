import { Panel } from '@/Components/ui/Panel';
import React, { useState, useCallback, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Box, Flex, Text, Separator, TextField, Button, Badge } from '@radix-ui/themes';
import { DashboardIcon, CalendarIcon, LayersIcon } from '@radix-ui/react-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';

import App from "@/Layouts/App.jsx";
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import AttendanceEmployeeTable from "@/Tables/AttendanceEmployeeTable.jsx";
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import AttendanceOverview from './Attendance/Components/AttendanceOverview';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { requestJson } from '@/api/client';
import SwapRequestForm from '@/Forms/SwapRequestForm';
import RegularizationForm from '@/Forms/RegularizationForm';
import OvertimeRequestForm from '@/Forms/OvertimeRequestForm';
import MyRequests from './Attendance/Components/MyRequests';
import SwapResponses from './Attendance/Components/SwapResponses';

const MyRosterCard = ({ month }) => {
  const { auth } = usePage().props;
  const [swapOpen, setSwapOpen] = useState(false);
  const queryClient = useQueryClient();

  // Driven by the page's month dropdown (whole selected month), so it stays in sync
  // with the overview + attendance table instead of a fixed rolling 14-day window.
  const base = month ? dayjs(month + '-01') : dayjs();
  const from = base.startOf('month').format('YYYY-MM-DD');
  const to = base.endOf('month').format('YYYY-MM-DD');

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['my-roster', from, to],
    queryFn: () => requestJson('get', '/attendance/my-roster', { params: { from, to } }),
  });

  const userId = auth?.user?.id;
  const mine = data?.roster?.[userId];
  const days = mine?.days || {};
  const dayKeys = Object.keys(days).sort();

  return (
    <>
      <Panel>
        <Flex justify="between" align="center" mb="3" wrap="wrap" gap="2">
          <Flex align="center" gap="2">
            <LayersIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
            <Text size="3" weight="bold">My Roster — {base.format('MMMM YYYY')}</Text>
          </Flex>
          <Button size="2" variant="soft" onClick={() => setSwapOpen(true)}>
            Request swap
          </Button>
        </Flex>

        {isLoading ? (
          <Text size="2" color="gray">Loading roster…</Text>
        ) : dayKeys.length === 0 ? (
          <Text size="2" color="gray">No upcoming shifts scheduled.</Text>
        ) : (
          <Flex gap="2" wrap="wrap">
            {dayKeys.map(date => {
              const cell = days[date];
              return (
                <Panel key={date} tinted p="2" style={{ minWidth: 110 }}>
                  <Text size="1" color="gray" as="div">{dayjs(date).format('ddd, MMM D')}</Text>
                  {cell.off || !cell.code ? (
                    <Badge color="gray" variant="soft" mt="1">Off</Badge>
                  ) : (
                    <Badge mt="1" style={{ background: cell.color || undefined, color: cell.color ? '#fff' : undefined }}>
                      {cell.code}
                    </Badge>
                  )}
                </Panel>
              );
            })}
          </Flex>
        )}
      </Panel>

      <SwapRequestForm
        open={swapOpen}
        onOpenChange={setSwapOpen}
        onSaved={() => {
          refetch();
          // The swap is tracked in the My Requests "Shift Swaps" list, so refresh it
          // too (invalidation beats the 5-min staleTime that a remount would honour).
          queryClient.invalidateQueries({ queryKey: ['my-swaps'] });
        }}
      />
    </>
  );
};

const RequestsCard = () => {
  const [regOpen, setRegOpen]   = useState(false);
  const [otOpen, setOtOpen]     = useState(false);
  const queryClient = useQueryClient();

  // Invalidate (not remount) so the lists refetch immediately — a key-based remount
  // returns cached data because of the 5-minute global staleTime.
  const handleSaved = () => {
    ['my-regularizations', 'my-overtime', 'my-comp-off', 'my-swaps']
      .forEach(key => queryClient.invalidateQueries({ queryKey: [key] }));
  };

  return (
    <>
      <Panel>
        <Flex justify="between" align="center" mb="3" wrap="wrap" gap="2">
          <Flex align="center" gap="2">
            <CalendarIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
            <Text size="3" weight="bold">My Requests</Text>
          </Flex>
          <Flex gap="2" wrap="wrap">
            <Button size="2" variant="soft" onClick={() => setRegOpen(true)}>
              Regularize a day
            </Button>
            <Button size="2" variant="soft" onClick={() => setOtOpen(true)}>
              Request overtime
            </Button>
          </Flex>
        </Flex>

        <ErrorBoundary>
          <MyRequests />
        </ErrorBoundary>
      </Panel>

      <RegularizationForm open={regOpen} onOpenChange={setRegOpen} onSaved={handleSaved} />
      <OvertimeRequestForm open={otOpen} onOpenChange={setOtOpen} onSaved={handleSaved} />
    </>
  );
};

const AttendanceEmployee = React.memo(({ title }) => {
  usePage();
  const isDesktop = useMediaQuery('(min-width: 1025px)');

  /* Which day is being looked at decides what the panels below fetch, so it
     lives in the URL — a refresh or a copied link opens the same day. The month
     shown is derived from it rather than stored separately, so the two can no
     longer drift apart. The default is left empty so an explicitly chosen date
     always stays visible in the link. */
  const f = useQueryFilters({
    defaults: { date: '' },
    mode: 'client',
    debounceKeys: [],
  });

  const selectedDate = f.values.date || dayjs().format('YYYY-MM-DD');
  const filterData = useMemo(
    () => ({ currentMonth: dayjs(selectedDate).format('YYYY-MM') }),
    [selectedDate],
  );

  const [updateTimeSheet, setUpdateTimeSheet] = useState(false);

  const setDate = f.set;

  const handleDateChange = useCallback((event) => {
    const newDate = event.target.value;
    if (newDate) setDate('date', newDate);
  }, [setDate]);

  const handleFilterChange = useCallback((key, value) => {
    // Picking a month moves to its first day, which drives the panels below.
    if (key === 'currentMonth' && value) {
      setDate('date', dayjs(value).startOf('month').format('YYYY-MM-DD'));
    }
  }, [setDate]);

  return (
    <>
      <Head title={title || "My Attendance"} />
      
      <Flex justify="center" p="4">
        <Box style={{ width: '100%', maxWidth: 2000 }}>
          <Panel>
            
            {/* ── Page Header ── */}
            <Box mb="4">
              <Flex
                direction={{ initial: 'column', sm: 'row' }}
                align={{ initial: 'start', sm: 'center' }}
                justify="between"
                gap="4"
              >
                <Flex align="center" gap="3">
                  <Box
                    p={{ initial: '2', md: '3' }}
                    style={{
                      background: 'var(--blue-a3)',
                      borderRadius: 12,
                      border: '1px solid var(--blue-a5)',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    <DashboardIcon
                      width={isDesktop ? 26 : 20}
                      height={isDesktop ? 26 : 20}
                      color="var(--blue-9)"
                    />
                  </Box>
                  <Box>
                    <Text
                      size={{ initial: '4', sm: '5' }}
                      weight="bold"
                      as="div"
                      style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, letterSpacing: '-0.02em', color: 'var(--gray-12)' }}
                    >
                      My Attendance
                    </Text>
                    <Text
                      size={{ initial: '1', md: '2' }}
                      style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}
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
                    style={{ width: 160, borderRadius: 10 }}
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

            {/* ── My Roster ── */}
            <ErrorBoundary>
              <MyRosterCard month={filterData.currentMonth} />
            </ErrorBoundary>

            {/* ── Swap requests awaiting my response (counterparty consent) ── */}
            <ErrorBoundary>
              <Box mt="4">
                <SwapResponses />
              </Box>
            </ErrorBoundary>

            <Separator size="4" mb="4" mt="4" />

            {/* ── Requests + My Requests ── */}
            <ErrorBoundary>
              <RequestsCard />
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

          </Panel>
        </Box>
      </Flex>
    </>
  );
});

AttendanceEmployee.layout = (page) => <App>{page}</App>;
export default AttendanceEmployee;