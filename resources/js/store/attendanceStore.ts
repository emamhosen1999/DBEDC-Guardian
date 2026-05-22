import { create } from 'zustand';

interface AttendanceRecord {
  id: number;
  user_id: number;
  date: string;
  punchin_time: string | null;
  punchout_time: string | null;
  punchin_location: any;
  punchout_location: any;
  is_late: boolean;
  duration?: string;
}

interface AttendanceState {
  // Today's attendance
  todayAttendance: AttendanceRecord[];
  setTodayAttendance: (attendance: AttendanceRecord[]) => void;
  
  // Selected date for viewing
  selectedDate: string;
  setSelectedDate: (date: string) => void;
  
  // Employee search query
  employeeQuery: string;
  setEmployeeQuery: (query: string) => void;
  
  // Present users for selected date
  presentUsers: any[];
  setPresentUsers: (users: any[]) => void;
  
  // Absent users for selected date
  absentUsers: any[];
  setAbsentUsers: (users: any[]) => void;
  
  // User locations for selected date
  userLocations: any[];
  setUserLocations: (locations: any[]) => void;
  
  // Loading states
  isLoadingAttendance: boolean;
  setIsLoadingAttendance: (loading: boolean) => void;
  
  // Error state
  attendanceError: string | null;
  setAttendanceError: (error: string | null) => void;
  
  // Clear all attendance data
  clearAttendanceData: () => void;
}

export const useAttendanceStore = create<AttendanceState>((set) => ({
  todayAttendance: [],
  setTodayAttendance: (attendance) => set({ todayAttendance: attendance }),
  
  selectedDate: new Date().toISOString().split('T')[0],
  setSelectedDate: (date) => set({ selectedDate: date }),
  
  employeeQuery: '',
  setEmployeeQuery: (query) => set({ employeeQuery: query }),
  
  presentUsers: [],
  setPresentUsers: (users) => set({ presentUsers: users }),
  
  absentUsers: [],
  setAbsentUsers: (users) => set({ absentUsers: users }),
  
  userLocations: [],
  setUserLocations: (locations) => set({ userLocations: locations }),
  
  isLoadingAttendance: false,
  setIsLoadingAttendance: (loading) => set({ isLoadingAttendance: loading }),
  
  attendanceError: null,
  setAttendanceError: (error) => set({ attendanceError: error }),
  
  clearAttendanceData: () => set({
    todayAttendance: [],
    presentUsers: [],
    absentUsers: [],
    userLocations: [],
    attendanceError: null,
  }),
}));

export default useAttendanceStore;
