import { Panel } from '@/Components/ui/Panel';
import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Box, Button, Flex, Heading, Separator, Text } from '@radix-ui/themes';
import {
    CalendarIcon,
    PlusIcon,
    BarChartIcon,
    CopyIcon,
} from '@radix-ui/react-icons';
import StatsCards from '@/Components/StatsCards.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import App from '@/Layouts/App.jsx';
import HolidayTable from '@/Tables/HolidayTable.jsx';
import HolidayForm from '@/Forms/HolidayForm.jsx';
import CopyYearForm from '@/Forms/CopyYearForm.jsx';
import DeleteHolidayForm from '@/Forms/DeleteHolidayForm.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';

const Holidays = ({ title }) => {
    const { auth, holidays: initialHolidays } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');
    const permissions = auth?.permissions ?? [];
    const isSuperAdministrator = auth?.roles?.includes('Super Administrator');
    const canCreate = isSuperAdministrator || permissions.includes('holidays.create');
    const canUpdate = isSuperAdministrator || permissions.includes('holidays.update');
    const canDelete = isSuperAdministrator || permissions.includes('holidays.delete');
    const actorId = auth?.user?.id;

    useRealtimeSignals({
        path: 'holiday/all',
        selfActorId: actorId,
        onSignal: () => router.reload({ only: ['holidays', 'stats'], preserveScroll: true, preserveState: true }),
    });

    const safeInitialHolidays = Array.isArray(initialHolidays) ? initialHolidays : [];
    const currentYearHolidays = useMemo(() => {
        const y = new Date().getFullYear();
        return safeInitialHolidays.filter(
            (h) => h?.from_date && new Date(h.from_date).getFullYear() === y
        );
    }, [safeInitialHolidays]);

    const [modalState, setModalState] = useState({ type: null, holidayId: null, currentHoliday: null });
    const [holidaysData, setHolidaysData] = useState(safeInitialHolidays);
    const [filteredHolidaysData, setFilteredHolidaysData] = useState(currentYearHolidays);

    useEffect(() => {
        setHolidaysData(Array.isArray(initialHolidays) ? initialHolidays : []);
    }, [initialHolidays]);

    const handleModalOpen = useCallback((type, holidayId = null, holiday = null) => {
        setModalState({ type, holidayId, currentHoliday: holiday });
    }, []);

    const handleModalClose = useCallback(() => {
        setModalState({ type: null, holidayId: null, currentHoliday: null });
    }, []);

    const updateHolidaysData = useCallback((newData) => setHolidaysData(newData), []);

    useEffect(() => {
        const y = new Date().getFullYear();
        const safe = Array.isArray(holidaysData) ? holidaysData : [];
        setFilteredHolidaysData(
            safe.filter((h) => h?.from_date && new Date(h.from_date).getFullYear() === y)
        );
    }, [holidaysData]);

    const statsData = useMemo(() => {
        const safe = Array.isArray(filteredHolidaysData) ? filteredHolidaysData : [];
        const now = new Date();
        const y = now.getFullYear();
        const thisYear = safe.filter((h) => h?.from_date && new Date(h.from_date).getFullYear() === y);
        const upcoming = safe.filter((h) => h?.from_date && new Date(h.from_date) > now);
        const totalDays = safe.reduce((acc, h) => {
            if (h?.num_of_days != null) return acc + Number(h.num_of_days);
            if (h?.from_date && h?.to_date) {
                const start = new Date(h.from_date);
                const end = new Date(h.to_date);
                const diff = Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1);
                return acc + diff;
            }
            return acc + 1;
        }, 0);

        return [
            { key: 'total', title: 'Total Holidays', value: safe.length, icon: <BarChartIcon style={{ width: 20, height: 20 }} />, color: 'blue', description: 'All records' },
            { key: 'this_year', title: 'This Year', value: thisYear.length, icon: <CalendarIcon style={{ width: 20, height: 20 }} />, color: 'green', description: 'Current calendar year' },
            { key: 'upcoming', title: 'Upcoming', value: upcoming.length, icon: <CalendarIcon style={{ width: 20, height: 20 }} />, color: 'violet', description: 'Future scheduled holidays' },
            { key: 'days', title: 'Holiday Days', value: totalDays, icon: <BarChartIcon style={{ width: 20, height: 20 }} />, color: 'amber', description: 'Cumulative day count' },
        ];
    }, [filteredHolidaysData]);

    const modalProps = {
        open: Boolean(modalState.type),
        closeModal: handleModalClose,
        setHolidaysData: updateHolidaysData,
        currentHoliday: modalState.currentHoliday,
    };

    return (
        <>
            <Head title={title || 'Company Holidays'} />

            {((modalState.type === 'add_holiday' && canCreate) || (modalState.type === 'edit_holiday' && canUpdate)) && (
                <HolidayForm {...modalProps} />
            )}

            {modalState.type === 'copy_year' && canCreate && (
                <CopyYearForm
                    open
                    setHolidaysData={updateHolidaysData}
                    closeModal={handleModalClose}
                />
            )}

            {modalState.type === 'delete_holiday' && canDelete && (
                <DeleteHolidayForm
                    open
                    holidayIdToDelete={modalState.holidayId}
                    setHolidaysData={updateHolidaysData}
                    closeModal={handleModalClose}
                />
            )}

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
                                    <Box p="3" style={{
                                        background: 'var(--blue-a3)',
                                        borderRadius: 12,
                                        border: '1px solid var(--blue-a5)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        <CalendarIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Company Holidays</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Manage company holidays and observances
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Header Actions */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    {canCreate && (
                                        <>
                                            <Button variant="soft" color="gray" onClick={() => handleModalOpen('copy_year')} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                                <CopyIcon style={{ width: 16, height: 16 }} />
                                                {!isMobile && 'Copy Year'}
                                            </Button>
                                            <Button color="blue" onClick={() => handleModalOpen('add_holiday')} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                                <PlusIcon style={{ width: 16, height: 16 }} />
                                                {!isMobile && 'Add Holiday'}
                                            </Button>
                                        </>
                                    )}
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* ── Content Area ── */}
                        <Box>
                            <Box mb="4">
                                <ErrorBoundary>
                                    <StatsCards stats={statsData} />
                                </ErrorBoundary>
                            </Box>

                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                <Flex align="center" gap="2">
                                    <BarChartIcon style={{ width: 18, height: 18, color: 'var(--blue-9)' }} />
                                    Holiday Management
                                </Flex>
                            </Heading>

                            <Box style={{ overflowX: 'auto' }}>
                                <ErrorBoundary>
                                    <HolidayTable
                                        holidaysData={holidaysData}
                                        onEdit={(holiday) => handleModalOpen('edit_holiday', null, holiday)}
                                        onDelete={(holidayId) => handleModalOpen('delete_holiday', holidayId)}
                                        canEdit={canUpdate}
                                        canDelete={canDelete}
                                        onFilteredDataChange={setFilteredHolidaysData}
                                    />
                                </ErrorBoundary>
                            </Box>
                        </Box>
                    </Panel>
                </Box>
            </Flex>
        </>
    );
};

Holidays.layout = (page) => <App>{page}</App>;
export default Holidays;
