import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { requestJson } from '../client';

/**
 * Fetch leaves
 */
export const useLeaves = (params = {}) => {
  const { page = 1, perPage = 25, status, leaveType, fromYear, fromMonth, userId, year } = params;

  return useQuery({
    queryKey: ['leaves', { page, perPage, status, leaveType, fromYear, fromMonth, userId, year }],
    queryFn: async () => {
      const { data } = await axios.get(route('leaves.paginate'), {
        params: {
          page,
          perPage,
          status,
          leave_type: leaveType,
          from_year: fromYear,
          from_month: fromMonth,
          user_id: userId,
          year,
        },
      });

      const leaves = data.leaves;
      const rows = Array.isArray(leaves) ? leaves : (leaves?.data ?? []);

      return {
        data: rows,
        leavesData: data.leavesData,
        total: leaves?.total ?? rows.length,
        current_page: leaves?.current_page ?? page,
        last_page: leaves?.last_page ?? 1,
        per_page: leaves?.per_page ?? perPage,
      };
    },
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Fetch leave details
 */
export const useLeaveDetails = (leaveId) => {
  return useQuery({
    queryKey: ['leaves', leaveId],
    queryFn: () => requestJson('get', `/api/v1/leaves/${leaveId}`),
    enabled: !!leaveId,
    staleTime: 10 * 60 * 1000, // 10 minutes - leave details don't change often
  });
};

/**
 * Fetch leave summary
 */
export const useLeaveSummary = (params = {}) => {
  const { year, userId } = params;
  
  return useQuery({
    queryKey: ['leaves', 'summary', { year, userId }],
    queryFn: () => requestJson('get', '/api/v1/leaves/summary', {
      params: { year, user_id: userId }
    }),
    staleTime: 10 * 60 * 1000, // 10 minutes
  });
};

/**
 * Fetch leave analytics
 */
export const useLeaveAnalytics = (params = {}) => {
  const { year, departmentId } = params;
  
  return useQuery({
    queryKey: ['leaves', 'analytics', { year, departmentId }],
    queryFn: () => requestJson('get', '/api/v1/leaves/analytics', {
      params: { year, department_id: departmentId }
    }),
    staleTime: 15 * 60 * 1000, // 15 minutes - analytics change rarely
  });
};

/**
 * Fetch leave calendar
 */
export const useLeaveCalendar = (params = {}) => {
  const { month, year, departmentId } = params;
  
  return useQuery({
    queryKey: ['leaves', 'calendar', { month, year, departmentId }],
    queryFn: () => requestJson('get', '/api/v1/leaves/calendar', {
      params: { month, year, department_id: departmentId }
    }),
    staleTime: 15 * 60 * 1000, // 15 minutes
  });
};

/**
 * Fetch leave types
 */
export const useLeaveTypes = () => {
  return useQuery({
    queryKey: ['leave-types'],
    queryFn: async () => {
      const { data } = await axios.get(route('leave-settings'), {
        headers: { Accept: 'application/json' },
      });
      return data.data ?? data.leaveTypes ?? data;
    },
    staleTime: 60 * 60 * 1000,
  });
};

/**
 * Fetch pending approvals
 */
export const usePendingApprovals = () => {
  return useQuery({
    queryKey: ['leaves', 'pending-approvals'],
    queryFn: () => requestJson('get', '/api/v1/leaves/pending-approvals'),
    staleTime: 2 * 60 * 1000, // 2 minutes - pending approvals change frequently
    refetchInterval: 2 * 60 * 1000, // Auto-refetch every 2 minutes
  });
};

/**
 * Create leave mutation
 */
export const useCreateLeave = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data) => requestJson('post', '/api/v1/leaves', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'summary'] });
    },
  });
};

/**
 * Update leave mutation
 */
export const useUpdateLeave = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ leaveId, data }) => requestJson('put', `/api/v1/leaves/${leaveId}`, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', variables.leaveId] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'summary'] });
    },
  });
};

/**
 * Approve leave mutation
 */
export const useApproveLeave = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ leaveId }) => requestJson('post', `/api/v1/leaves/${leaveId}/approve`),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', variables.leaveId] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'pending-approvals'] });
    },
  });
};

/**
 * Reject leave mutation
 */
export const useRejectLeave = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ leaveId, rejectionReason }) => 
      requestJson('post', `/api/v1/leaves/${leaveId}/reject`, { rejection_reason: rejectionReason }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', variables.leaveId] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'pending-approvals'] });
    },
  });
};

/**
 * Bulk approve leaves mutation
 */
export const useBulkApproveLeaves = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (leaveIds) => requestJson('post', '/api/v1/leaves/bulk-approve', { leave_ids: leaveIds }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'pending-approvals'] });
    },
  });
};

/**
 * Bulk reject leaves mutation
 */
export const useBulkRejectLeaves = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ leaveIds, rejectionReason }) => 
      requestJson('post', '/api/v1/leaves/bulk-reject', { leave_ids: leaveIds, rejection_reason: rejectionReason }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'pending-approvals'] });
    },
  });
};

/**
 * Delete leave mutation
 */
export const useDeleteLeave = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (leaveId) => requestJson('delete', `/api/v1/leaves/${leaveId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leaves'] });
      queryClient.invalidateQueries({ queryKey: ['leaves', 'summary'] });
    },
  });
};

/**
 * Fetch leave statistics
 */
export const useLeavesStats = (params = {}) => {
  const { year } = params;

  return useQuery({
    queryKey: ['leaves', 'stats', { year }],
    queryFn: async () => {
      const { data } = await axios.get(route('leaves.stats'), { params: { year } });
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Create leave type mutation
 */
export const useCreateLeaveType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data) => {
      const { data: body } = await axios.post(route('add-leave-type'), data);
      return body;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave-types'] });
    },
  });
};

/**
 * Update leave type mutation
 */
export const useUpdateLeaveType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, data }) => {
      const { data: body } = await axios.put(route('update-leave-type', { id }), data);
      return body;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave-types'] });
    },
  });
};

/**
 * Delete leave type mutation
 */
export const useDeleteLeaveType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => axios.delete(route('delete-leave-type', { id })),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave-types'] });
    },
  });
};
