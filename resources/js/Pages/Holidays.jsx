import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    CalendarIcon,
    PlusIcon,
    ChartBarIcon,
} from '@heroicons/react/24/outline';
import { Box, Button, Card, Flex, Heading, Separator, Text } from '@radix-ui/themes';
import StatsCards from '@/Components/StatsCards.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import App from '@/Layouts/App.jsx';
import HolidayTable from '@/Tables/HolidayTable.jsx';
import HolidayForm from '@/Forms/HolidayForm.jsx';
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
            { title: 'Total Holidays', value: safe.length, icon: <ChartBarIcon style={{ width: 20, height: 20 }} />, color: 'text-blue-400', iconBg: 'bg-blue-500/20', description: 'Filtered' },
            { title: 'This Year', value: thisYear.length, icon: <CalendarIcon style={{ width: 20, height: 20 }} />, color: 'text-green-400', iconBg: 'bg-green-500/20', description: 'Current year' },
            { title: 'Upcoming', value: upcoming.length, icon: <CalendarIcon style={{ width: 20, height: 20 }} />, color: 'text-purple-400', iconBg: 'bg-purple-500/20', description: 'Future' },
            { title: 'Holiday Days', value: totalDays, icon: <ChartBarIcon style={{ width: 20, height: 20 }} />, color: 'text-orange-400', iconBg: 'bg-orange-500/20', description: 'Total days' },
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
            <Head title={title} />
            {(modalState.type === 'add_holiday' || modalState.type === 'edit_holiday') && (
                <HolidayForm {...modalProps} />
            )}
            {modalState.type === 'delete_holiday' && (
                <DeleteHolidayForm
                    open
                    holidayIdToDelete={modalState.holidayId}
                    setHolidaysData={updateHolidaysData}
                    closeModal={handleModalClose}
                />
            )}

            <Box p="4">
                <div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>
                    <Card size="3" style={{
                        backdropFilter: 'blur(24px)',
                        WebkitBackdropFilter: 'blur(24px)',
                        background: 'var(--color-panel-translucent)',
                        border: '1px solid var(--gray-a4)',
                        boxShadow: '0 24px 64px var(--black-a6), 0 4px 16px var(--black-a3)',
                    }}>
                        <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" p="4">
                            <Flex align="center" gap="3">
                                <CalendarIcon style={{ width: 28, height: 28, color: 'var(--accent-11)' }} />
                                <Box>
                                    <Heading size="5">Company holidays</Heading>
                                    <Text size="2" color="gray">Manage holidays and observances</Text>
                                </Box>
                            </Flex>
                            <Button onClick={() => handleModalOpen('add_holiday')}>
                                <PlusIcon style={{ width: 16, height: 16 }} />
                                {!isMobile && 'Add holiday'}
                            </Button>
                        </Flex>
                        <Separator size="4" />
                        <Box p="4">
                            <ErrorBoundary>
                                <StatsCards stats={statsData} className="mb-4" />
                            </ErrorBoundary>
                            <Heading size="3" mb="3">
                                <Flex align="center" gap="2">
                                    <ChartBarIcon style={{ width: 18, height: 18 }} />
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
                    </Card>
                </div>
            </Box>
        </>
    );
};

Holidays.layout = (page) => <App>{page}</App>;
export default Holidays;
