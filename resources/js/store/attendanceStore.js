import { create } from 'zustand';

export const useAttendanceStore = create((set) => ({
  // Today's attendance
  todayAttendance: [],
  setTodayAttendance: (attendance) => set({ todayAttendance: attendance }),
  
  // Selected date for viewing
  selectedDate: new Date().toISOString().split('T')[0],
  setSelectedDate: (date) => set({ selectedDate: date }),
  
  // Employee search query
  employeeQuery: '',
  setEmployeeQuery: (query) => set({ employeeQuery: query }),
  
  // Present users for selected date
  presentUsers: [],
  setPresentUsers: (users) => set({ presentUsers: users }),
  
  // Absent users for selected date
  absentUsers: [],
  setAbsentUsers: (users) => set({ absentUsers: users }),
  
  // User locations for selected date
  userLocations: [],
  setUserLocations: (locations) => set({ userLocations: locations }),
  
  // Loading states
  isLoadingAttendance: false,
  setIsLoadingAttendance: (loading) => set({ isLoadingAttendance: loading }),
  
  // Error state
  attendanceError: null,
  setAttendanceError: (error) => set({ attendanceError: error }),
  
  // Clear all attendance data
  clearAttendanceData: () => set({
    todayAttendance: [],
    presentUsers: [],
    absentUsers: [],
    userLocations: [],
    attendanceError: null,
  }),
}));

export default useAttendanceStore;
