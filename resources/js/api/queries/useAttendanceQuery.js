import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

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
  const { currentMonth, currentYear, scope = 'self' } = params;

  return useQuery({
    queryKey: ['attendance', 'monthly-summary', { currentMonth, currentYear, scope }],
    queryFn: () => requestJson('get', '/attendance/monthly-stats', {
      params: { currentMonth, currentYear, scope }
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
  const { date, page = 1, perPage = 25 } = params;

  return useQuery({
    queryKey: ['attendance', 'daily-timesheet', { date, page, perPage }],
    queryFn: () => requestJson('get', '/admin/daily-timesheet', {
      params: { date, page, perPage }
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
      // Invalidate attendance queries to refetch
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
    },
  });
};

/**
 * Update time correction mutation
 */
export const useUpdateTimeCorrection = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ attendanceId, data }) => requestJson('post', `/attendance/${attendanceId}/correct`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
    },
  });
};

/**
 * Update attendance type config mutation
 */
export const useUpdateAttendanceType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, config }) => requestJson('put', `/settings/attendance-type/${id}`, { config }),
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
    mutationFn: (data) => requestJson('post', '/attendance-settings/update', data),
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
    mutationFn: (attendanceId) => requestJson('delete', `/attendance/correct/${attendanceId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance'] });
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
