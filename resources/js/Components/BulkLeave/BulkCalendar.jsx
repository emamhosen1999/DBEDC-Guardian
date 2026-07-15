import { Panel } from '@/Components/ui/Panel';
/**
 * BulkCalendar.jsx
 * Interactive multi-date selector for bulk leave requests.
 * * UX Improvements added:
 * - Refined Visuals: Calendar cells now use strict Radix UI color scales for selected, holiday, and leave states.
 * - Standardized Container: Wrapped in a clean Card component to match the application's layout.
 * - Better Interactive States: Improved hover, focus, and disabled states for better accessibility.
 */
import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Badge, Box, Button, Flex, IconButton, Spinner, Text, Grid } from '@radix-ui/themes';
import { CalendarIcon, ChevronLeftIcon, ChevronRightIcon } from '@radix-ui/react-icons';
import axios from 'axios';

const BulkCalendar = ({ 
    selectedDates = [], 
    onDatesChange, 
    existingLeaves = [],
    publicHolidays = [],
    minDate = null,
    maxDate = null,
    userId = null,
    fetchFromAPI = false
}) => {

    const [currentDate, setCurrentDate] = useState(new Date());
    const [apiCalendarData, setApiCalendarData] = useState({
        existingLeaves: existingLeaves,
        publicHolidays: publicHolidays
    });
    const [loading, setLoading] = useState(false);
    const [loadedYear, setLoadedYear] = useState(null);

    // Fetch calendar data from API if enabled
    const fetchCalendarData = useCallback(async (year) => {
        if (!fetchFromAPI || !userId) return;
        if (loadedYear === year) return;
        
        setLoading(true);
        try {
            const response = await axios.get(route('leaves.bulk.calendar-data'), {
                params: { user_id: userId, year: year }
            });

            if (response.data.success) {
                setApiCalendarData({
                    existingLeaves: response.data.data.existingLeaves || [],
                    publicHolidays: response.data.data.publicHolidays || []
                });
                setLoadedYear(year);
            }
        } catch (error) {
            console.error('Failed to fetch calendar data:', error);
        } finally {
            setLoading(false);
        }
    }, [fetchFromAPI, userId, loadedYear]);

    const currentYear = currentDate.getFullYear();
    useEffect(() => {
        fetchCalendarData(currentYear);
    }, [fetchCalendarData, currentYear]);

    useEffect(() => {
        if (fetchFromAPI && userId) setLoadedYear(null);
    }, [userId, fetchFromAPI]);

    const finalExistingLeaves = fetchFromAPI ? apiCalendarData.existingLeaves : existingLeaves;
    const finalPublicHolidays = fetchFromAPI ? apiCalendarData.publicHolidays : publicHolidays;

    // Generate Calendar Days
    const calendarDays = useMemo(() => {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        const daysInMonth = lastDayOfMonth.getDate();
        const firstDayWeekday = firstDayOfMonth.getDay();
        
        const prevMonthLastDay = new Date(year, month, 0).getDate();
        const prevMonthDays = [];
        for (let i = firstDayWeekday - 1; i >= 0; i--) {
            prevMonthDays.push({
                date: prevMonthLastDay - i,
                isCurrentMonth: false,
                fullDate: new Date(year, month - 1, prevMonthLastDay - i)
            });
        }
        
        const currentMonthDays = [];
        for (let day = 1; day <= daysInMonth; day++) {
            currentMonthDays.push({
                date: day,
                isCurrentMonth: true,
                fullDate: new Date(year, month, day)
            });
        }
        
        const totalCells = prevMonthDays.length + currentMonthDays.length;
        const remainingCells = 42 - totalCells;
        const nextMonthDays = [];
        for (let day = 1; day <= remainingCells; day++) {
            nextMonthDays.push({
                date: day,
                isCurrentMonth: false,
                fullDate: new Date(year, month + 1, day)
            });
        }
        
        return [...prevMonthDays, ...currentMonthDays, ...nextMonthDays];
    }, [currentDate]);

    // Navigation
    const goToPreviousMonth = useCallback(() => setCurrentDate(prev => new Date(prev.getFullYear(), prev.getMonth() - 1, 1)), []);
    const goToNextMonth = useCallback(() => setCurrentDate(prev => new Date(prev.getFullYear(), prev.getMonth() + 1, 1)), []);
    const goToToday = useCallback(() => setCurrentDate(new Date()), []);

    // Selection Handler
    const handleDateClick = useCallback((dayData) => {
        if (!dayData.isCurrentMonth || loading) return;
        
        const dateString = dayData.fullDate.getFullYear() + '-' + 
                          String(dayData.fullDate.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(dayData.fullDate.getDate()).padStart(2, '0');
        
        const hasExistingLeave = finalExistingLeaves.some(leave => {
            if (!leave.from_date || !leave.to_date) return false;
            const fromDate = leave.from_date.split('T')[0];
            const toDate = leave.to_date.split('T')[0];
            return dateString >= fromDate && dateString <= toDate;
        });
        
        const isPublicHoliday = finalPublicHolidays.includes(dateString);
        
        if (hasExistingLeave || isPublicHoliday) return;
        if (minDate && dayData.fullDate < new Date(minDate)) return;
        if (maxDate && dayData.fullDate > new Date(maxDate)) return;
        
        const isSelected = selectedDates.includes(dateString);
        let newSelectedDates;
        
        if (isSelected) {
            newSelectedDates = selectedDates.filter(date => date !== dateString);
        } else {
            newSelectedDates = [...selectedDates, dateString];
        }
        
        onDatesChange(newSelectedDates.sort());
    }, [selectedDates, onDatesChange, minDate, maxDate, loading, finalExistingLeaves, finalPublicHolidays]);

    // Date Status Helper
    const getDateStatus = useCallback((dayData) => {
        if (!dayData.isCurrentMonth) return { selectable: false };
        
        const dateString = dayData.fullDate.getFullYear() + '-' + 
                          String(dayData.fullDate.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(dayData.fullDate.getDate()).padStart(2, '0');
        
        const isSelected = selectedDates.includes(dateString);
        const isToday = dayData.fullDate.toDateString() === new Date().toDateString();
        const isPast = dayData.fullDate < new Date().setHours(0, 0, 0, 0);
        const isWeekend = dayData.fullDate.getDay() === 0 || dayData.fullDate.getDay() === 6;
        
        const hasExistingLeave = finalExistingLeaves.some(leave => {
            if (!leave.from_date || !leave.to_date) return false;
            const fromDate = leave.from_date.split('T')[0];
            const toDate = leave.to_date.split('T')[0];
            return dateString >= fromDate && dateString <= toDate;
        });
        
        const isPublicHoliday = finalPublicHolidays.includes(dateString);
        
        const selectable = !loading && !hasExistingLeave && !isPublicHoliday &&
                          (!minDate || dayData.fullDate >= new Date(minDate)) &&
                          (!maxDate || dayData.fullDate <= new Date(maxDate));
        
        return { isSelected, isToday, isPast, isWeekend, hasExistingLeave, isPublicHoliday, selectable };
    }, [selectedDates, finalExistingLeaves, finalPublicHolidays, minDate, maxDate, loading]);

    const monthLabel = currentDate.toLocaleDateString('en-US', { month: 'long' });
    const yearLabel = currentDate.getFullYear();
    const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    return (
        <Panel variant="surface" p="0" style={{ overflow: 'hidden', position: 'relative' }}>
            
            {/* Loading Overlay */}
            {fetchFromAPI && loading && (
                <Box style={{ position: 'absolute', inset: 0, backgroundColor: 'var(--gray-a3)', zIndex: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', backdropFilter: 'blur(1px)' }}>
                    <Flex direction="column" align="center" gap="2">
                        <Spinner size="3" />
                        <Text size="2" color="gray" weight="medium">Loading calendar...</Text>
                    </Flex>
                </Box>
            )}

            {/* Header */}
            <Box px="4" pt="4" pb="3" style={{ borderBottom: '1px solid var(--gray-a4)', backgroundColor: 'var(--gray-a2)' }}>
                <Flex justify="between" align="center">
                    <Flex align="center" gap="2">
                        <CalendarIcon style={{ color: 'var(--accent-9)', width: 20, height: 20 }} />
                        <Flex align="baseline" gap="2">
                            <Text size="4" weight="bold">{monthLabel}</Text>
                            <Text size="3" color="gray">{yearLabel}</Text>
                        </Flex>
                    </Flex>
                    <Flex align="center" gap="2">
                        <IconButton size="1" variant="soft" color="gray" onClick={goToPreviousMonth} disabled={loading} style={{ cursor: 'pointer' }}>
                            <ChevronLeftIcon />
                        </IconButton>
                        <Button size="1" variant="soft" color="gray" onClick={goToToday} disabled={loading} style={{ cursor: 'pointer' }}>
                            Today
                        </Button>
                        <IconButton size="1" variant="soft" color="gray" onClick={goToNextMonth} disabled={loading} style={{ cursor: 'pointer' }}>
                            <ChevronRightIcon />
                        </IconButton>
                    </Flex>
                </Flex>
            </Box>

            <Box p="4">
                {/* Legend */}
                <Flex gap="2" wrap="wrap" mb="4">
                    <Badge color="blue" variant="solid" radius="full">Selected</Badge>
                    <Badge color="red" variant="soft" radius="full">Leave</Badge>
                    <Badge color="amber" variant="soft" radius="full">Holiday</Badge>
                    <Badge color="violet" variant="soft" radius="full">Today</Badge>
                </Flex>

                {/* Week Days */}
                <Grid columns="7" gap="1" mb="2">
                    {weekDays.map(day => (
                        <Flex key={day} align="center" justify="center" style={{ height: 28 }}>
                            <Text size="1" weight="bold" color="gray">{day}</Text>
                        </Flex>
                    ))}
                </Grid>

                {/* Calendar Grid */}
                <Grid columns="7" gap="1">
                    {calendarDays.map((dayData, index) => {
                        const status = getDateStatus(dayData);
                        
                        // Styling Logic based on Radix scales
                        let bgColor = 'transparent';
                        let textColor = 'var(--gray-12)';
                        let borderColor = 'transparent';

                        if (status.isSelected) {
                            bgColor = 'var(--accent-9)';
                            textColor = 'white';
                        } else if (status.hasExistingLeave) {
                            bgColor = 'var(--red-3)';
                            textColor = 'var(--red-11)';
                            borderColor = 'var(--red-a5)';
                        } else if (status.isPublicHoliday) {
                            bgColor = 'var(--amber-3)';
                            textColor = 'var(--amber-11)';
                            borderColor = 'var(--amber-a5)';
                        } else if (status.isToday) {
                            bgColor = 'var(--violet-3)';
                            textColor = 'var(--violet-11)';
                            borderColor = 'var(--violet-7)';
                        } else if (!status.selectable && dayData.isCurrentMonth) {
                            textColor = 'var(--gray-8)';
                        } else if (!dayData.isCurrentMonth) {
                            textColor = 'var(--gray-6)';
                        }

                        return (
                            <Box
                                key={index}
                                onClick={() => handleDateClick(dayData)}
                                role="button"
                                tabIndex={status.selectable ? 0 : -1}
                                style={{
                                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    aspectRatio: '1',
                                    borderRadius: 'var(--radius-2)',
                                    backgroundColor: bgColor,
                                    color: textColor,
                                    border: `1px solid ${borderColor}`,
                                    cursor: status.selectable ? 'pointer' : 'default',
                                    opacity: dayData.isCurrentMonth ? 1 : 0.4,
                                    fontWeight: status.isSelected || status.isToday ? 'bold' : 'normal',
                                    transition: 'all 0.15s ease',
                                }}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        handleDateClick(dayData);
                                    }
                                }}
                            >
                                {dayData.date}
                            </Box>
                        );
                    })}
                </Grid>

                {/* Selection Summary */}
                {selectedDates.length > 0 && (
                    <Box mt="4" p="3" style={{ background: 'var(--accent-a2)', borderRadius: 'var(--radius-3)', border: '1px solid var(--accent-a4)' }}>
                        <Flex justify="between" align="center" mb="2">
                            <Text size="2" weight="bold" color="indigo">
                                {selectedDates.length} date{selectedDates.length !== 1 ? 's' : ''} selected
                            </Text>
                            <Button size="1" variant="ghost" color="gray" onClick={() => onDatesChange([])} style={{ cursor: 'pointer' }}>
                                Clear
                            </Button>
                        </Flex>
                        <Flex gap="1" wrap="wrap">
                            {selectedDates.slice(0, 8).map(date => (
                                <Badge key={date} color="indigo" variant="soft">
                                    {(() => { const [y,m,d] = date.split('-'); return new Date(y, m-1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); })()}
                                </Badge>
                            ))}
                            {selectedDates.length > 8 && (
                                <Badge color="indigo" variant="outline">+{selectedDates.length - 8} more</Badge>
                            )}
                        </Flex>
                    </Box>
                )}
            </Box>
        </Panel>
    );
};

export default BulkCalendar;