import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';
import { useOptimisticMutation } from '../useOptimisticMutation';
import { patchTimesheetPunch } from '@/Pages/Attendance/timesheetPatch';

// Secondary attendance aggregates that a punch correction can shift. They are
// usually inactive on the timesheet screen, so invalidating them just marks
// them stale (lazy refetch on next mount) — correctness without thrashing the
// visible, already-optimistically-patched list.
const ATTENDANCE_AGGREGATE_KEYS = [
  ['attendance', 'today'],
  ['attendance', 'present-users'],
  ['attendance', 'absent-users'],
  ['attendance', 'locations-today'],
  ['attendance', 'my-monthly-stats'],
  ['attendance', 'monthly-summary'],
  ['attendance', 'history'],
];

/**
 * Fetch today's attendance for the current user
 */
export const useAttendanceToday = () => {
  return useQuery({
    queryKey: ['attendance', 'today'],
    queryFn: () => requestJson('get', '/attendance/attendance-today'),
    staleTime: 2 * 60 * 1000, // 2 minutes - attendance changes frequently
  });
};

/**
 * Fetch attendance history
 */
export const useAttendanceHistory = (params = {}) => {
  const { page = 1, perPage = 10, currentMonth, currentYear, scope = 'self', employee } = params;

  return useQuery({
    queryKey: ['attendance', 'history', { page, perPage, currentMonth, currentYear, scope, employee }],
    queryFn: () => requestJson('get', '/attendances-admin-paginate', {
      params: { page, per_page: perPage, currentMonth, currentYear, scope, employee }
    }),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};

/**
 * Fetch monthly attendance summary
 */
export const useMonthlySummary = (params = {}) => {
  const { currentMonth, currentYear, scope = 'self', employee, departmentId } = params;

  return useQuery({
    queryKey: ['attendance', 'monthly-summary', { currentMonth, currentYear, scope, employee, departmentId }],
    queryFn: () => requestJson('get', '/attendances-admin-paginate', {
      params: { currentMonth, currentYear, page: 1, perPage: 1000, employee, department_id: departmentId }
    }),
    staleTime: 10 * 60 * 1000, // 10 minutes - summary doesn't change often
  });
};

/** @deprecated Use useMonthlySummary */
export const useAttendanceMonthlySummary = useMonthlySummary;

/**
 * Fetch present users for a specific date
 */
export const usePresentUsers = (date) => {
  return useQuery({
    queryKey: ['attendance', 'present-users', date],
    queryFn: () => requestJson('get', '/admin/get-present-users-for-date', {
      params: { date }
    }),
    enabled: !!date,
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Fetch absent users for a specific date
 */
export const useAbsentUsers = (date) => {
  return useQuery({
    queryKey: ['attendance', 'absent-users', date],
    queryFn: () => requestJson('get', '/admin/get-absent-users-for-date', {
      params: { date }
    }),
    enabled: !!date,
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Fetch user locations for a specific date
 */
export const useUserLocations = (date) => {
  return useQuery({
    queryKey: ['attendance', 'locations-today', date],
    queryFn: () => requestJson('get', '/attendance/locations-today', {
      params: { date }
    }),
    enabled: !!date,
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Fetch daily timesheet data
 */
export const useDailyTimesheet = (params = {}) => {
  const { date, page = 1, perPage = 25, employee } = params;

  return useQuery({
    queryKey: ['attendance', 'daily-timesheet', { date, page, perPage, employee }],
    queryFn: () => requestJson('get', '/admin/daily-timesheet', {
      params: { date, page, perPage, employee }
    }),
    enabled: !!date,
    staleTime: 3 * 60 * 1000, // 3 minutes - timesheet changes during the day
  });
};

/**
 * Punch attendance mutation
 */
export const usePunchAttendance = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data) => requestJson('post', '/attendance/punch', data),
    onSuccess: () => {
      const todayStr = new Date().toLocaleDateString('en-CA');
      // Invalidate attendance queries granularly to avoid UI-wide thrash
      queryClient.invalidateQueries({ queryKey: ['attendance', 'today'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'present-users', todayStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'absent-users', todayStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'locations-today', todayStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'daily-timesheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'my-monthly-stats'] });
    },
  });
};

/**
 * Update time correction mutation
 */
export const useUpdateTimeCorrection = () => {
  const queryClient = useQueryClient();

  // Phase C: optimistic row-patch. The edited punch flips instantly in every
  // cached daily-timesheet page (partial-key match), rolls back to the exact
  // snapshot on error, and reconciles with the server on settle — instead of
  // blocking the visible list on a full refetch.
  return useOptimisticMutation({
    mutationFn: ({ attendanceId, data }) => requestJson('post', `/attendance/${attendanceId}/correct`, data),
    queryKey: ['attendance', 'daily-timesheet'],
    partialMatch: true,
    updateFn: patchTimesheetPunch,
    onSettled: () => {
      ATTENDANCE_AGGREGATE_KEYS.forEach((key) => queryClient.invalidateQueries({ queryKey: key }));
    },
  });
};

/**
 * Mark user as present mutation
 */
export const useMarkAsPresent = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ userId, date, data }) => requestJson('post', `/attendance/mark-as-present`, {
      user_id: userId,
      date,
      ...data
    }),
    onSuccess: (data, variables) => {
      const dateStr = variables.date || new Date().toLocaleDateString('en-CA');

      queryClient.invalidateQueries({ queryKey: ['attendance', 'today'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'present-users', dateStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'absent-users', dateStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'locations-today', dateStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'daily-timesheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'my-monthly-stats'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'monthly-summary'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'history'] });
    },
  });
};

/**
 * Update attendance type config mutation
 */
export const useUpdateAttendanceType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, ...data }) => requestJson('put', `/settings/attendance-type/${id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance-types'] });
    },
  });
};

/**
 * Create attendance type mutation
 */
export const useCreateAttendanceType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => requestJson('post', '/settings/attendance-type', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance-types'] });
    },
  });
};

/**
 * Delete attendance type mutation
 */
export const useDeleteAttendanceType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => requestJson('delete', `/settings/attendance-type/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance-types'] });
    },
  });
};

/**
 * Update attendance settings mutation
 */
export const useUpdateAttendanceSettings = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => requestJson('post', route('attendance-settings.update'), data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance-settings'] });
    },
  });
};

/**
 * Delete attendance correction mutation
 */
export const useDeleteAttendanceCorrection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (attendanceId) => requestJson('delete', route('attendance.correct.delete', { id: attendanceId })),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance', 'today'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'present-users'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'absent-users'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'locations-today'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'daily-timesheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'my-monthly-stats'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'monthly-summary'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'history'] });
    },
  });
};

/**
 * Export daily timesheet mutation
 */
export const useExportDailyTimesheet = () => {
  return useMutation({
    mutationFn: ({ date, type }) => requestJson('get', '/attendance/daily-timesheet/export', {
      params: { date, type },
      responseType: 'blob'
    }),
  });
};

/**
 * Export monthly calendar mutation
 */
export const useExportMonthlyCalendar = () => {
  return useMutation({
    mutationFn: ({ month, type }) => requestJson('get', '/attendance/monthly-calendar/export', {
      params: { month, type },
      responseType: 'blob'
    }),
  });
};

/**
 * Fetch monthly attendance stats for the current user
 */
export const useMyMonthlyStats = (params = {}) => {
  const { currentMonth, currentYear } = params;

  return useQuery({
    queryKey: ['attendance', 'my-monthly-stats', { currentMonth, currentYear }],
    queryFn: () => requestJson('get', '/attendance/my-monthly-stats', {
      params: { currentMonth, currentYear }
    }),
    staleTime: 5 * 60 * 1000,
  });
};
