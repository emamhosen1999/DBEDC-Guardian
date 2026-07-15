import { Panel } from '@/Components/ui/Panel';
import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
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

const Holidays = ({ title }) => {
    const { holidays: initialHolidays } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');

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
        const totalDays = safe.reduce((sum, h) => sum + (h.duration || 1), 0);
        return [
            { title: 'Total Holidays', value: safe.length, icon: <BarChartIcon style={{ width: 20, height: 20 }} />, color: 'text-blue-400', iconBg: 'bg-blue-500/20', description: 'Filtered' },
            { title: 'This Year', value: thisYear.length, icon: <CalendarIcon style={{ width: 20, height: 20 }} />, color: 'text-green-400', iconBg: 'bg-green-500/20', description: 'Current year' },
            { title: 'Upcoming', value: upcoming.length, icon: <CalendarIcon style={{ width: 20, height: 20 }} />, color: 'text-purple-400', iconBg: 'bg-purple-500/20', description: 'Future' },
            { title: 'Holiday Days', value: totalDays, icon: <BarChartIcon style={{ width: 20, height: 20 }} />, color: 'text-orange-400', iconBg: 'bg-orange-500/20', description: 'Total days' },
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

            {(modalState.type === 'add_holiday' || modalState.type === 'edit_holiday') && (
                <HolidayForm {...modalProps} />
            )}

            {modalState.type === 'copy_year' && (
                <CopyYearForm
                    open
                    setHolidaysData={updateHolidaysData}
                    closeModal={handleModalClose}
                />
            )}

            {modalState.type === 'delete_holiday' && (
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
                                        background: 'var(--accent-a3)',
                                        borderRadius: 'var(--radius-2)',
                                        border: '1px solid var(--accent-a6)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    }}>
                                        <CalendarIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5">Company holidays</Heading>
                                        <Text size="2" color="gray">
                                            Manage company holidays and observances
                                        </Text>
                                    </Box>
                                </Flex>

                                {/* Header Actions */}
                                <Flex gap="2" align="center" wrap="wrap">
                                    <Button variant="soft" color="gray" onClick={() => handleModalOpen('copy_year')}>
                                        <CopyIcon style={{ width: 16, height: 16 }} />
                                        {!isMobile && 'Copy year'}
                                    </Button>
                                    <Button onClick={() => handleModalOpen('add_holiday')}>
                                        <PlusIcon style={{ width: 16, height: 16 }} />
                                        {!isMobile && 'Add holiday'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" />

                        {/* ── Content Area ── */}
                        <Box>
                            <Box mb="4">
                                <ErrorBoundary>
                                    <StatsCards stats={statsData} />
                                </ErrorBoundary>
                            </Box>

                            <Heading size="3" mb="3">
                                <Flex align="center" gap="2">
                                    <BarChartIcon style={{ width: 18, height: 18 }} />
                                    Holiday management
                                </Flex>
                            </Heading>

                            <Box style={{ overflowX: 'auto' }}>
                                <ErrorBoundary>
                                    <HolidayTable
                                        holidaysData={holidaysData}
                                        onEdit={(holiday) => handleModalOpen('edit_holiday', null, holiday)}
                                        onDelete={(holidayId) => handleModalOpen('delete_holiday', holidayId)}
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