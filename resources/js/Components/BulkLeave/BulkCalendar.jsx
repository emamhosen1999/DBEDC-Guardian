import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Badge, Box, Button, Flex, IconButton, Spinner, Text } from '@radix-ui/themes';
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
    fetchFromAPI = false // Flag to determine if we should fetch data from API
}) => {

    const [currentDate, setCurrentDate] = useState(new Date());
    const [apiCalendarData, setApiCalendarData] = useState({
        existingLeaves: existingLeaves,
        publicHolidays: publicHolidays
    });
    const [loading, setLoading] = useState(false);
    const [loadedYear, setLoadedYear] = useState(null); // Track which year's data is loaded

    // Fetch calendar data from API if enabled - optimized to load once per year
    const fetchCalendarData = useCallback(async (year) => {
        if (!fetchFromAPI || !userId) return;
        
        // Don't fetch if we already have data for this year
        if (loadedYear === year) return;
        
        setLoading(true);
        try {
            const response = await axios.get(route('leaves.bulk.calendar-data'), {
                params: {
                    user_id: userId,
                    year: year
                    // Removed month parameter to get full year data
                }
            });

            if (response.data.success) {
                setApiCalendarData({
                    existingLeaves: response.data.data.existingLeaves || [],
                    publicHolidays: response.data.data.publicHolidays || []
                });
                setLoadedYear(year); // Mark this year as loaded
            }
        } catch (error) {
            console.error('Failed to fetch calendar data:', error);
            // Keep existing data on error
        } finally {
            setLoading(false);
        }
    }, [fetchFromAPI, userId, loadedYear]);

    // Fetch data when component mounts or year changes
    useEffect(() => {
        const currentYear = currentDate.getFullYear();
        fetchCalendarData(currentYear);
    }, [fetchCalendarData, currentDate.getFullYear()]); // Only depend on year, not full date

    // Reset loaded year when user changes
    useEffect(() => {
        if (fetchFromAPI && userId) {
            setLoadedYear(null); // Reset to force reload for new user
        }
    }, [userId, fetchFromAPI]);

    // Use either API data or props data
    const finalExistingLeaves = fetchFromAPI ? apiCalendarData.existingLeaves : existingLeaves;
    const finalPublicHolidays = fetchFromAPI ? apiCalendarData.publicHolidays : publicHolidays;

    // Get calendar days data for the current month
    const calendarDays = useMemo(() => {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        
        const firstDayOfMonth = new Date(year, month, 1);
        const lastDayOfMonth = new Date(year, month + 1, 0);
        const daysInMonth = lastDayOfMonth.getDate();
        const firstDayWeekday = firstDayOfMonth.getDay();
        
        // Previous month padding
        const prevMonthLastDay = new Date(year, month, 0).getDate();
        const prevMonthDays = [];
        for (let i = firstDayWeekday - 1; i >= 0; i--) {
            prevMonthDays.push({
                date: prevMonthLastDay - i,
                isCurrentMonth: false,
                fullDate: new Date(year, month - 1, prevMonthLastDay - i)
            });
        }
        
        // Current month days
        const currentMonthDays = [];
        for (let day = 1; day <= daysInMonth; day++) {
            currentMonthDays.push({
                date: day,
                isCurrentMonth: true,
                fullDate: new Date(year, month, day)
            });
        }
        
        // Next month padding
        const totalCells = prevMonthDays.length + currentMonthDays.length;
        const remainingCells = 42 - totalCells; // 6 weeks * 7 days
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

    // Navigation handlers
    const goToPreviousMonth = useCallback(() => {
        setCurrentDate(prev => new Date(prev.getFullYear(), prev.getMonth() - 1, 1));
    }, []);

    const goToNextMonth = useCallback(() => {
        setCurrentDate(prev => new Date(prev.getFullYear(), prev.getMonth() + 1, 1));
    }, []);

    const goToToday = useCallback(() => {
        setCurrentDate(new Date());
    }, []);

    // Date selection handler - disabled during loading, holidays, and existing leaves
    const handleDateClick = useCallback((dayData) => {
        if (!dayData.isCurrentMonth || loading) return; // Block interaction during loading
        
        // Use consistent date formatting to avoid timezone issues
        const dateString = dayData.fullDate.getFullYear() + '-' + 
                          String(dayData.fullDate.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(dayData.fullDate.getDate()).padStart(2, '0');
        
        // Check for holidays and existing leaves first
        const hasExistingLeave = finalExistingLeaves.some(leave => {
            if (!leave.from_date || !leave.to_date) return false;
            const fromDate = leave.from_date.split('T')[0];
            const toDate = leave.to_date.split('T')[0];
            return dateString >= fromDate && dateString <= toDate;
        });
        
        const isPublicHoliday = finalPublicHolidays.includes(dateString);
        
        // Prevent selection of holidays and existing leaves
        if (hasExistingLeave || isPublicHoliday) return;
        
        // Check if date is selectable
        if (minDate && dayData.fullDate < new Date(minDate)) return;
        if (maxDate && dayData.fullDate > new Date(maxDate)) return;
        // Allow past dates for bulk leave requests (removed restriction)
        
        // Toggle selection
        const isSelected = selectedDates.includes(dateString);
        let newSelectedDates;
        
        if (isSelected) {
            newSelectedDates = selectedDates.filter(date => date !== dateString);
        } else {
            newSelectedDates = [...selectedDates, dateString];
        }
        
        onDatesChange(newSelectedDates.sort());
    }, [selectedDates, onDatesChange, minDate, maxDate, loading, finalExistingLeaves, finalPublicHolidays]);

    // Get date status
    const getDateStatus = useCallback((dayData) => {
        if (!dayData.isCurrentMonth) return { selectable: false };
        
        // Use consistent date formatting (YYYY-MM-DD) and avoid timezone issues
        const dateString = dayData.fullDate.getFullYear() + '-' + 
                          String(dayData.fullDate.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(dayData.fullDate.getDate()).padStart(2, '0');
        
        const isSelected = selectedDates.includes(dateString);
        const isToday = dayData.fullDate.toDateString() === new Date().toDateString();
        const isPast = dayData.fullDate < new Date().setHours(0, 0, 0, 0);
        const isWeekend = dayData.fullDate.getDay() === 0 || dayData.fullDate.getDay() === 6;
        
        // Check for existing leave - improved detection with better date comparison
        const hasExistingLeave = finalExistingLeaves.some(leave => {
            if (!leave.from_date || !leave.to_date) return false;
            
            // Normalize dates to YYYY-MM-DD format and handle timezone properly
            const fromDate = leave.from_date.split('T')[0]; // Get just the date part
            const toDate = leave.to_date.split('T')[0]; // Get just the date part
            
            return dateString >= fromDate && dateString <= toDate;
        });
        
        // Check for public holiday - direct string comparison
        const isPublicHoliday = finalPublicHolidays.includes(dateString);
        
        // Allow selection of past dates for bulk leave (removed isPast restriction)
        // Disable selectability during loading, for holidays, and existing leaves
        const selectable = !loading && 
                          !hasExistingLeave && 
                          !isPublicHoliday &&
                          (!minDate || dayData.fullDate >= new Date(minDate)) &&
                          (!maxDate || dayData.fullDate <= new Date(maxDate));
        
        return {
            isSelected,
            isToday,
            isPast,
            isWeekend,
            hasExistingLeave,
            isPublicHoliday,
            selectable
        };
    }, [selectedDates, finalExistingLeaves, finalPublicHolidays, minDate, maxDate, loading]);

    const monthLabel = currentDate.toLocaleDateString('en-US', { month: 'long' });
    const yearLabel = currentDate.getFullYear();

    const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    return (
        <Box
            style={{
                width: '100%',
                background: 'var(--gray-a2)',
                borderRadius: 'var(--radius-3)',
                border: '1px solid var(--gray-a4)',
            }}
        >
            {/* Card Header */}
            <Box
                px="4"
                pt="4"
                pb="3"
                style={{ borderBottom: '1px solid var(--gray-a3)' }}
            >
                <Flex justify="between" align="center">
                    <Flex align="center" gap="2">
                        <CalendarIcon
                            style={{ color: 'var(--accent-9)', width: 18, height: 18, flexShrink: 0 }}
                        />
                        <Flex align="baseline" gap="1">
                            <Text size="3" weight="semibold">{monthLabel}</Text>
                            <Text size="2" color="gray" weight="regular">{yearLabel}</Text>
                        </Flex>
                        {fetchFromAPI && loading && (
                            <Spinner size="1" />
                        )}
                    </Flex>
                    <Flex align="center" gap="1">
                        <IconButton
                            size="1"
                            variant="ghost"
                            onClick={goToPreviousMonth}
                            disabled={loading}
                            aria-label="Previous month"
                        >
                            <ChevronLeftIcon />
                        </IconButton>
                        <Button
                            size="1"
                            variant="ghost"
                            onClick={goToToday}
                            disabled={loading}
                        >
                            Today
                        </Button>
                        <IconButton
                            size="1"
                            variant="ghost"
                            onClick={goToNextMonth}
                            disabled={loading}
                            aria-label="Next month"
                        >
                            <ChevronRightIcon />
                        </IconButton>
                    </Flex>
                </Flex>
            </Box>

            {/* Card Body */}
            <Box px="4" pb="4" pt="3">
                {/* Legend */}
                <Flex gap="2" wrap="wrap" mb="4">
                    <Badge color="blue" variant="solid">Selected</Badge>
                    <Badge color="red" variant="solid">Leave</Badge>
                    <Badge color="amber" variant="solid">Holiday</Badge>
                    <Badge color="violet" variant="solid">Today</Badge>
                    <Badge color="gray" variant="outline">Weekend</Badge>
                </Flex>

                {/* Week days header */}
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 2, marginBottom: 6 }}>
                    {weekDays.map(day => (
                        <div
                            key={day}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                height: 28,
                                fontSize: 11,
                                fontWeight: 500,
                                color: 'var(--gray-9)',
                            }}
                        >
                            {day}
                        </div>
                    ))}
                </div>

                {/* Calendar grid */}
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: 2 }}>
                    {calendarDays.map((dayData, index) => {
                        const status = getDateStatus(dayData);
                        const hasBorder = status.isSelected || status.hasExistingLeave || status.isPublicHoliday || status.isToday;

                        return (
                            <div
                                key={index}
                                onClick={() => handleDateClick(dayData)}
                                style={{
                                    position: 'relative',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    aspectRatio: '1',
                                    minHeight: 36,
                                    fontSize: 12,
                                    fontWeight: status.isSelected ? 700 : status.isToday ? 600 : status.hasExistingLeave || status.isPublicHoliday ? 500 : 400,
                                    userSelect: 'none',
                                    cursor: status.selectable ? 'pointer' : 'not-allowed',
                                    opacity: !dayData.isCurrentMonth ? 0.3 : (loading ? 0.5 : (status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday ? 0.7 : 1)),
                                    pointerEvents: loading ? 'none' : 'auto',
                                    border: hasBorder ? '2px solid' : status.isWeekend ? '1px solid' : '1px solid transparent',
                                    boxShadow: status.isSelected ? '0 2px 6px rgba(0,0,0,0.15)' : 'none',
                                    transform: status.isSelected ? 'scale(1.05)' : 'none',
                                    transition: 'background-color 0.15s, transform 0.15s, box-shadow 0.15s',
                                    zIndex: status.isSelected ? 10 : 'auto',
                                    backgroundColor: status.isSelected
                                        ? 'var(--accent-9)'
                                        : status.hasExistingLeave && !status.isSelected
                                        ? 'var(--red-9)'
                                        : status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave
                                        ? 'var(--amber-9)'
                                        : status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday
                                        ? 'var(--violet-a4)'
                                        : status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday
                                        ? 'var(--gray-a3)'
                                        : loading
                                        ? 'var(--gray-a3)'
                                        : 'transparent',
                                    color: status.isSelected
                                        ? 'white'
                                        : status.hasExistingLeave && !status.isSelected
                                        ? 'white'
                                        : status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave
                                        ? 'var(--amber-12)'
                                        : status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday
                                        ? 'var(--violet-11)'
                                        : status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday
                                        ? 'var(--gray-9)'
                                        : !status.selectable && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday && !status.isWeekend
                                        ? 'var(--gray-7)'
                                        : 'var(--gray-12)',
                                    borderColor: status.isSelected
                                        ? 'var(--accent-10)'
                                        : status.hasExistingLeave && !status.isSelected
                                        ? 'var(--red-10)'
                                        : status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave
                                        ? 'var(--amber-10)'
                                        : status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday
                                        ? 'var(--violet-8)'
                                        : status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday
                                        ? 'var(--gray-a5)'
                                        : 'transparent',
                                    borderRadius: 'var(--radius-2)',
                                }}
                                role="button"
                                tabIndex={status.selectable ? 0 : -1}
                                title={
                                    status.hasExistingLeave ? 'Existing leave - cannot select' : 
                                    status.isPublicHoliday ? 'Public holiday - cannot select' : 
                                    !status.selectable ? 'Not selectable' : ''
                                }
                                aria-label={`${dayData.fullDate.toDateString()}${status.isSelected ? ' (selected)' : ''}${status.hasExistingLeave ? ' (existing leave)' : ''}${status.isPublicHoliday ? ' (public holiday)' : ''}`}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        handleDateClick(dayData);
                                    }
                                }}
                            >
                                {dayData.date}

                                {/* Weekend indicator dot */}
                                {status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && (
                                    <div
                                        style={{
                                            position: 'absolute',
                                            top: 2,
                                            right: 2,
                                            width: 4,
                                            height: 4,
                                            borderRadius: '50%',
                                            opacity: 0.5,
                                            backgroundColor: 'var(--gray-9)',
                                        }}
                                    />
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Selection summary */}
                {selectedDates.length > 0 && (
                    <Box
                        mt="3"
                        p="3"
                        style={{
                            background: 'var(--accent-a3)',
                            borderRadius: 'var(--radius-2)',
                            border: '1px solid var(--accent-a5)',
                        }}
                    >
                        <Flex justify="between" align="center" mb="2">
                            <Text size="2" weight="semibold" color="blue">
                                {selectedDates.length} date{selectedDates.length !== 1 ? 's' : ''} selected
                            </Text>
                            <Button
                                size="1"
                                variant="ghost"
                                color="gray"
                                onClick={() => onDatesChange([])}
                                style={{ cursor: 'pointer', fontSize: 11 }}
                            >
                                Clear all
                            </Button>
                        </Flex>
                        <Flex gap="1" wrap="wrap">
                            {selectedDates.slice(0, 8).map(date => (
                                <Badge key={date} color="blue" variant="solid">
                                    {new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                </Badge>
                            ))}
                            {selectedDates.length > 8 && (
                                <Badge color="blue" variant="outline">
                                    +{selectedDates.length - 8} more
                                </Badge>
                            )}
                        </Flex>
                    </Box>
                )}
            </Box>
        </Box>
    );
};

export default BulkCalendar;
