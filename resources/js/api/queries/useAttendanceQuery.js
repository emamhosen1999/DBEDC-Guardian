import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { requestJson } from '../client';
import { useOptimisticMutation } from '../useOptimisticMutation';
import { patchTimesheetPunch } from '@/Pages/Attendance/timesheetPatch';
import dayjs from 'dayjs';

const patchMonthlySummaryPunch = (oldData, userId, dateKey, punchin, punchout) => {
  if (!oldData || !Array.isArray(oldData.data)) return oldData;
  let changed = false;
  const data = oldData.data.map((row) => {
    if (String(row.user_id) !== String(userId)) return row;
    changed = true;
    const cell = row[dateKey] || { status: '√' };
    const nextIn = punchin !== undefined ? punchin : cell.punch_in;
    const nextOut = punchout !== undefined ? punchout : cell.punch_out;
    
    // calculate work hours
    let hours = cell.total_work_hours;
    if (nextIn && nextOut) {
      try {
        const t1 = dayjs(`2000-01-01T${nextIn.substring(nextIn.length - 8)}`);
        const t2 = dayjs(`2000-01-01T${nextOut.substring(nextOut.length - 8)}`);
        if (t2.isAfter(t1)) {
          hours = (t2.diff(t1, 'minute') / 60).toFixed(2);
        }
      } catch (e) {}
    }

    return {
      ...row,
      [dateKey]: {
        ...cell,
        status: '√',
        punch_in: nextIn ? nextIn.substring(nextIn.length - 8) : null,
        punch_out: nextOut ? nextOut.substring(nextOut.length - 8) : null,
        total_work_hours: hours,
      }
    };
  });
  return changed ? { ...oldData, data } : oldData;
};

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
  const { currentMonth, currentYear, scope = 'self', employee, departmentId, page = 1, perPage = 25 } = params;

  return useQuery({
    queryKey: ['attendance', 'monthly-summary', { currentMonth, currentYear, scope, employee, departmentId, page, perPage }],
    queryFn: () => requestJson('get', '/attendances-admin-paginate', {
      params: { currentMonth, currentYear, page, per_page: perPage, employee, department_id: departmentId }
    }),
    placeholderData: keepPreviousData, // keep prior page's data during page changes so pagination UI never flickers/disappears
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
 * Fetch the single-day attendance partition (present / absent / upcoming / off-leave)
 * for the team-attendance style tabbed view.
 *
 * Contract: GET /attendance/day-partition?date=YYYY-MM-DD&department_id=<id|omitted>
 *   → { date, counts:{present,absent,upcoming,off_leave,total},
 *       present:[…], absent:[…], upcoming:[…], off_leave:[…] }
 */
export const useAttendanceDayPartition = (date, departmentId, designationId) => {
  return useQuery({
    queryKey: ['attendance', 'day-partition', { date, departmentId: departmentId || null, designationId: designationId || null }],
    queryFn: () => requestJson('get', '/attendance/day-partition', {
      params: { date, department_id: departmentId || undefined, designation_id: designationId || undefined },
    }),
    enabled: !!date,
    placeholderData: keepPreviousData, // keep prior partition during date/dept changes so tabs never flash empty
    staleTime: 3 * 60 * 1000, // 3 minutes - partition shifts through the day
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
  const { date, page = 1, perPage = 25, employee, departmentId, designationId } = params;

  return useQuery({
    queryKey: ['attendance', 'daily-timesheet', { date, page, perPage, employee, departmentId: departmentId || null, designationId: designationId || null }],
    queryFn: () => requestJson('get', '/admin/daily-timesheet', {
      params: { date, page, perPage, employee, department_id: departmentId || undefined, designation_id: designationId || undefined }
    }),
    enabled: !!date,
    placeholderData: keepPreviousData, // keep prior page during pagination so the pager stays put
    staleTime: 3 * 60 * 1000, // 3 minutes - timesheet changes during the day
  });
};

/**
 * Fetch the ranged, filterable attendance log
 */
export const useAttendanceLog = (params = {}) => {
  const { from, to, page = 1, perPage = 25, employee, departmentId, designationId, status } = params;

  return useQuery({
    queryKey: ['attendance', 'log', { from, to, page, perPage, employee, departmentId, designationId, status }],
    queryFn: () => requestJson('get', '/attendance/log', {
      params: {
        from, to, page, perPage, employee,
        department_id: departmentId, designation_id: designationId, status,
      },
    }),
    enabled: !!from && !!to,
    placeholderData: keepPreviousData,
    staleTime: 3 * 60 * 1000,
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

  return useMutation({
    mutationFn: ({ attendanceId, data }) => requestJson('post', `/attendance/${attendanceId}/correct`, data),
    onMutate: async ({ attendanceId, data }) => {
      await queryClient.cancelQueries({ queryKey: ['attendance'] });

      const snapshots = {
        dailyTimesheets: queryClient.getQueriesData({ queryKey: ['attendance', 'daily-timesheet'] }),
        monthlySummaries: queryClient.getQueriesData({ queryKey: ['attendance', 'monthly-summary'] }),
        presentUsers: queryClient.getQueriesData({ queryKey: ['attendance', 'present-users'] }),
      };

      // Resolve user_id and date from cache
      let userId = null;
      let dateKey = null;
      for (const [, cache] of snapshots.dailyTimesheets) {
        if (cache && Array.isArray(cache.attendances)) {
          const row = cache.attendances.find(
            (att) => Array.isArray(att.punches) && att.punches.some((p) => p.id === attendanceId)
          );
          if (row) {
            userId = row.user_id;
            dateKey = row.date;
            break;
          }
        }
      }

      const nextIn = data?.punchin;
      const nextOut = data?.punchout;

      // 1. Patch Daily Timesheet cache
      queryClient.setQueriesData({ queryKey: ['attendance', 'daily-timesheet'] }, (old) => {
        if (!old || !Array.isArray(old.attendances)) return old;
        return {
          ...old,
          attendances: old.attendances.map((att) => {
            if (!Array.isArray(att.punches) || !att.punches.some((p) => p.id === attendanceId)) return att;
            return {
              ...att,
              punches: att.punches.map((p) => {
                if (p.id !== attendanceId) return p;
                return {
                  ...p,
                  ...(nextIn !== undefined ? { punch_in: nextIn } : {}),
                  ...(nextOut !== undefined ? { punch_out: nextOut } : {}),
                };
              }),
              ...(nextIn !== undefined ? { clockin_time: nextIn } : {}),
              ...(nextOut !== undefined ? { clockout_time: nextOut } : {}),
            };
          }),
        };
      });

      // 2. Patch Monthly Summary cache
      if (userId && dateKey) {
        queryClient.setQueriesData({ queryKey: ['attendance', 'monthly-summary'] }, (old) => {
          return patchMonthlySummaryPunch(old, userId, dateKey, nextIn, nextOut);
        });
      }

      return { snapshots };
    },
    onError: (err, variables, context) => {
      if (context?.snapshots) {
        context.snapshots.dailyTimesheets.forEach(([key, val]) => queryClient.setQueryData(key, val));
        context.snapshots.monthlySummaries.forEach(([key, val]) => queryClient.setQueryData(key, val));
        context.snapshots.presentUsers.forEach(([key, val]) => queryClient.setQueryData(key, val));
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance', 'daily-timesheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'monthly-summary'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'present-users'] });
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
    onMutate: async ({ userId, date, data }) => {
      const dateKey = date;
      await queryClient.cancelQueries({ queryKey: ['attendance'] });

      const snapshots = {
        dailyTimesheets: queryClient.getQueriesData({ queryKey: ['attendance', 'daily-timesheet'] }),
        monthlySummaries: queryClient.getQueriesData({ queryKey: ['attendance', 'monthly-summary'] }),
        absentUsers: queryClient.getQueriesData({ queryKey: ['attendance', 'absent-users', dateKey] }),
        presentUsers: queryClient.getQueriesData({ queryKey: ['attendance', 'present-users', dateKey] }),
      };

      // Retrieve user details from absent-users cache
      let userName = 'Employee';
      let profileImage = null;
      for (const [, cache] of snapshots.absentUsers) {
        if (cache) {
          const u = cache.absent_users?.find(x => String(x.id) === String(userId)) ||
                    cache.off_users?.find(x => String(x.id) === String(userId)) ||
                    cache.upcoming_users?.find(x => String(x.id) === String(userId));
          if (u) {
            userName = u.name;
            profileImage = u.profile_image || u.profile_image_url || null;
            break;
          }
        }
      }

      const defaultTime = '09:00:00';
      const newAttendanceRow = {
        id: `temp_${Date.now()}`,
        user_id: userId,
        date: dateKey,
        status: 'present',
        clockin_time: defaultTime,
        clockout_time: null,
        user: {
          id: userId,
          name: userName,
          profile_image: profileImage,
          profile_image_url: profileImage,
        },
        punches: [
          {
            id: `temp_p_${Date.now()}`,
            punch_in: defaultTime,
            punch_out: null,
          }
        ]
      };

      // 1. Patch Daily Timesheet cache
      queryClient.setQueriesData({ queryKey: ['attendance', 'daily-timesheet'] }, (old) => {
        if (!old) return { attendances: [newAttendanceRow], total: 1 };
        const list = Array.isArray(old.attendances) ? [...old.attendances] : [];
        if (!list.some(x => String(x.user_id) === String(userId) && x.date === dateKey)) {
          list.unshift(newAttendanceRow);
        }
        return {
          ...old,
          attendances: list,
          total: (old.total || 0) + 1,
        };
      });

      // 2. Patch Absent Users cache
      queryClient.setQueriesData({ queryKey: ['attendance', 'absent-users', dateKey] }, (old) => {
        if (!old) return old;
        return {
          ...old,
          absent_users: (old.absent_users || []).filter(x => String(x.id) !== String(userId)),
          off_users: (old.off_users || []).filter(x => String(x.id) !== String(userId)),
          upcoming_users: (old.upcoming_users || []).filter(x => String(x.id) !== String(userId)),
        };
      });

      // 3. Patch Present Users cache
      queryClient.setQueriesData({ queryKey: ['attendance', 'present-users', dateKey] }, (old) => {
        if (!old) return { attendances: [newAttendanceRow] };
        const list = Array.isArray(old.attendances) ? [...old.attendances] : [];
        if (!list.some(x => String(x.user_id) === String(userId))) {
          list.unshift(newAttendanceRow);
        }
        return {
          ...old,
          attendances: list,
        };
      });

      // 4. Patch Monthly Summary cache
      queryClient.setQueriesData({ queryKey: ['attendance', 'monthly-summary'] }, (old) => {
        if (!old || !Array.isArray(old.data)) return old;
        return {
          ...old,
          data: old.data.map((row) => {
            if (String(row.user_id) !== String(userId)) return row;
            return {
              ...row,
              [dateKey]: {
                status: '√',
                punch_in: defaultTime,
                punch_out: null,
                total_work_hours: null,
              }
            };
          }),
        };
      });

      return { snapshots };
    },
    onError: (err, variables, context) => {
      if (context?.snapshots) {
        context.snapshots.dailyTimesheets.forEach(([key, val]) => queryClient.setQueryData(key, val));
        context.snapshots.monthlySummaries.forEach(([key, val]) => queryClient.setQueryData(key, val));
        if (context.snapshots.absentUsers) {
          context.snapshots.absentUsers.forEach(([key, val]) => queryClient.setQueryData(key, val));
        }
        if (context.snapshots.presentUsers) {
          context.snapshots.presentUsers.forEach(([key, val]) => queryClient.setQueryData(key, val));
        }
      }
    },
    onSettled: (data, error, variables) => {
      const dateStr = variables.date;
      queryClient.invalidateQueries({ queryKey: ['attendance', 'daily-timesheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'monthly-summary'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'absent-users', dateStr] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'present-users', dateStr] });
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
    onMutate: async (attendanceId) => {
      await queryClient.cancelQueries({ queryKey: ['attendance'] });

      const snapshots = {
        dailyTimesheets: queryClient.getQueriesData({ queryKey: ['attendance', 'daily-timesheet'] }),
        monthlySummaries: queryClient.getQueriesData({ queryKey: ['attendance', 'monthly-summary'] }),
        absentUsers: queryClient.getQueriesData({ queryKey: ['attendance', 'absent-users'] }),
        presentUsers: queryClient.getQueriesData({ queryKey: ['attendance', 'present-users'] }),
      };

      // Resolve user_id and date from cache
      let userId = null;
      let dateKey = null;
      let userName = 'Employee';
      let profileImage = null;

      for (const [, cache] of snapshots.dailyTimesheets) {
        if (cache && Array.isArray(cache.attendances)) {
          const row = cache.attendances.find((a) => a.id === attendanceId);
          if (row) {
            userId = row.user_id;
            dateKey = row.date;
            userName = row.user?.name || 'Employee';
            profileImage = row.user?.profile_image || row.user?.profile_image_url || null;
            break;
          }
        }
      }

      // 1. Patch Daily Timesheet cache
      queryClient.setQueriesData({ queryKey: ['attendance', 'daily-timesheet'] }, (old) => {
        if (!old || !Array.isArray(old.attendances)) return old;
        return {
          ...old,
          attendances: old.attendances.filter(x => x.id !== attendanceId),
          total: Math.max(0, (old.total || 0) - 1),
        };
      });

      if (userId && dateKey) {
        const deletedUser = {
          id: userId,
          name: userName,
          profile_image: profileImage,
          profile_image_url: profileImage,
        };

        // 2. Patch Absent Users cache
        queryClient.setQueriesData({ queryKey: ['attendance', 'absent-users', dateKey] }, (old) => {
          if (!old) return old;
          const list = Array.isArray(old.absent_users) ? [...old.absent_users] : [];
          if (!list.some(x => String(x.id) === String(userId))) {
            list.push(deletedUser);
          }
          return {
            ...old,
            absent_users: list,
          };
        });

        // 3. Patch Present Users cache
        queryClient.setQueriesData({ queryKey: ['attendance', 'present-users', dateKey] }, (old) => {
          if (!old || !Array.isArray(old.attendances)) return old;
          return {
            ...old,
            attendances: old.attendances.filter(x => String(x.user_id) !== String(userId)),
          };
        });

        // 4. Patch Monthly Summary cache
        queryClient.setQueriesData({ queryKey: ['attendance', 'monthly-summary'] }, (old) => {
          return patchMonthlySummaryPunch(old, userId, dateKey, null, null, '▼');
        });
      }

      return { snapshots };
    },
    onError: (err, variables, context) => {
      if (context?.snapshots) {
        context.snapshots.dailyTimesheets.forEach(([key, val]) => queryClient.setQueryData(key, val));
        context.snapshots.monthlySummaries.forEach(([key, val]) => queryClient.setQueryData(key, val));
        context.snapshots.absentUsers.forEach(([key, val]) => queryClient.setQueryData(key, val));
        context.snapshots.presentUsers.forEach(([key, val]) => queryClient.setQueryData(key, val));
      }
    },
    onSettled: (data, error, variables, context) => {
      queryClient.invalidateQueries({ queryKey: ['attendance', 'daily-timesheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'monthly-summary'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'absent-users'] });
      queryClient.invalidateQueries({ queryKey: ['attendance', 'present-users'] });
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
 * Export the ranged attendance log (queued)
 */
export const useExportAttendanceLog = () => {
  return useMutation({
    mutationFn: ({ from, to, type, employee, departmentId, designationId, status }) =>
      requestJson('get', '/attendance/log/export', {
        params: {
          from, to, type, employee,
          department_id: departmentId, designation_id: designationId, status,
        },
        responseType: 'blob',
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
