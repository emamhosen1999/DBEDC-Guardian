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

    const monthYear = currentDate.toLocaleDateString('en-US', { 
        month: 'long', 
        year: 'numeric' 
    });

    const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    return (
        <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)', width: '100%' }}>
            {/* Header */}
            <Flex justify="between" align="center" mb="3">
                <Flex align="center" gap="2">
                    <CalendarIcon style={{ color: 'var(--accent-9)' }} />
                    <Text size="3" weight="medium">{monthYear}</Text>
                    {fetchFromAPI && loadedYear && <Badge color="blue" variant="outline">{loadedYear}</Badge>}
                </Flex>
                <Flex align="center" gap="1">
                    <IconButton size="1" variant="ghost" onClick={goToPreviousMonth} disabled={loading}><ChevronLeftIcon /></IconButton>
                    <Button size="1" variant="ghost" onClick={goToToday} disabled={loading}>Today</Button>
                    <IconButton size="1" variant="ghost" onClick={goToNextMonth} disabled={loading}><ChevronRightIcon /></IconButton>
                </Flex>
            </Flex>

            {/* Loading */}
            {loading && (
                <Flex align="center" gap="2" justify="center" py="2" mb="3" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-1)' }}>
                    <Spinner size="1" />
                    <Text size="1" color="gray">Loading calendar data for {currentDate.getFullYear()}...</Text>
                </Flex>
            )}

            {/* Legend */}
            <Flex gap="2" wrap="wrap" mb="3">
                <Badge color="blue" variant="solid">Selected</Badge>
                <Badge color="red" variant="solid">Leave</Badge>
                <Badge color="amber" variant="solid">Holiday</Badge>
                <Badge color="violet" variant="solid">Today</Badge>
                <Badge color="gray" variant="outline">Weekend</Badge>
            </Flex>
                
                {/* Week days header - compact */}
                <div className="grid grid-cols-7 gap-0.5 sm:gap-1 mb-2">
                    {weekDays.map(day => (
                        <div 
                            key={day} 
                            className="flex items-center justify-center w-8 h-6 sm:w-10 sm:h-8 text-xs sm:text-sm font-medium"
                            style={{
                                color: `var(--theme-foreground-600, #71717A)`,
                                fontFamily: `var(--fontFamily, "Inter")`,
                            }}
                        >
                            {day}
                        </div>
                    ))}
                </div>
                
                {/* Calendar grid - responsive */}
                <div className="grid grid-cols-7 gap-0.5 sm:gap-1">
                    {calendarDays.map((dayData, index) => {
                        const status = getDateStatus(dayData);
                        
                        return (
                            <div
                                key={index}
                                onClick={() => handleDateClick(dayData)}
                                className={`
                                    relative flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 min-h-8 sm:min-h-10
                                    rounded-md transition-all duration-200 text-xs sm:text-sm select-none
                                    ${status.selectable ? 'cursor-pointer' : 'cursor-not-allowed'}
                                    ${!dayData.isCurrentMonth ? 'opacity-30' : ''}
                                    ${loading ? 'opacity-50 pointer-events-none' : ''}
                                    ${status.isSelected ? 'font-bold border-2 shadow-md scale-105 z-10' : ''}
                                    ${status.hasExistingLeave && !status.isSelected ? 'border-2 font-medium cursor-not-allowed' : ''}
                                    ${status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave ? 'border-2 font-medium cursor-not-allowed' : ''}
                                    ${status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday ? 'font-semibold border-2 shadow-sm' : ''}
                                    ${status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday ? 'border opacity-70' : ''}
                                    ${!status.selectable && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday && !status.isWeekend ? 'cursor-not-allowed opacity-60' : ''}
                                    ${status.selectable && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday && !status.isWeekend ? 'border border-transparent hover:scale-105' : ''}
                                `}
                                style={{
                                    backgroundColor: status.isSelected 
                                        ? 'var(--theme-primary)' 
                                        : status.hasExistingLeave && !status.isSelected 
                                        ? 'var(--theme-danger)' 
                                        : status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave
                                        ? 'var(--theme-warning)'
                                        : status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday
                                        ? 'var(--theme-secondary-200)'
                                        : status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday
                                        ? 'var(--theme-content2)'
                                        : loading
                                        ? 'var(--theme-content2)'
                                        : 'transparent',
                                    color: status.isSelected 
                                        ? 'var(--theme-primary-foreground)' 
                                        : status.hasExistingLeave && !status.isSelected 
                                        ? 'var(--theme-danger-foreground)' 
                                        : status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave
                                        ? 'var(--theme-warning-foreground)'
                                        : status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday
                                        ? 'var(--theme-secondary-800)'
                                        : status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday
                                        ? 'var(--theme-foreground-500)'
                                        : !status.selectable && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday && !status.isWeekend
                                        ? 'var(--theme-foreground-400)'
                                        : 'var(--theme-foreground-900)',
                                    borderColor: status.isSelected 
                                        ? 'var(--theme-primary-600)' 
                                        : status.hasExistingLeave && !status.isSelected 
                                        ? 'var(--theme-danger-600)' 
                                        : status.isPublicHoliday && !status.isSelected && !status.hasExistingLeave
                                        ? 'var(--theme-warning-600)'
                                        : status.isToday && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday
                                        ? 'var(--theme-secondary-500)'
                                        : status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && !status.isToday
                                        ? 'var(--theme-divider)'
                                        : 'transparent',
                                    borderRadius: `var(--borderRadius, 8px)`,
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                                role="button"
                                tabIndex={status.selectable ? 0 : -1}
                                title={status.hasExistingLeave ? 'Existing leave - cannot select' : 
                                       status.isPublicHoliday ? 'Public holiday - cannot select' : 
                                       !status.selectable ? 'Not selectable' : ''}
                                aria-label={`${dayData.fullDate.toDateString()}${status.isSelected ? ' (selected)' : ''}${status.hasExistingLeave ? ' (existing leave)' : ''}${status.isPublicHoliday ? ' (public holiday)' : ''}`}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        handleDateClick(dayData);
                                    }
                                }}
                            >
                                {dayData.date}
                                
                                {/* Weekend indicator - hidden on mobile */}
                                {status.isWeekend && !status.isSelected && !status.hasExistingLeave && !status.isPublicHoliday && (
                                    <div 
                                        className="absolute top-0.5 right-0.5 w-1 h-1 rounded-full opacity-50 hidden sm:block"
                                        style={{ backgroundColor: 'var(--theme-foreground-500)' }}
                                    />
                                )}
                            </div>
                        );
                    })}
                </div>
                
                {/* Selection summary */}
                {selectedDates.length > 0 && (
                    <Box mt="3" p="3" style={{ background: 'var(--accent-a3)', borderRadius: 'var(--radius-1)', border: '1px solid var(--accent-a5)' }}>
                        <Text size="2" weight="medium" color="blue" style={{ display: 'block', marginBottom: 8 }}>
                            {selectedDates.length} date{selectedDates.length !== 1 ? 's' : ''} selected
                        </Text>
                        <Flex gap="1" wrap="wrap">
                            {selectedDates.slice(0, 8).map(date => (
                                <Badge key={date} color="blue" variant="solid">
                                    {new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                </Badge>
                            ))}
                            {selectedDates.length > 8 && (
                                <Badge color="blue" variant="outline">+{selectedDates.length - 8} more</Badge>
                            )}
                        </Flex>
                    </Box>
                )}
        </Box>
    );
};

export default BulkCalendar;
