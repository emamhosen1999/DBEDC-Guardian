import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

/**
 * Fetch daily works
 */
export const useDailyWorks = (params = {}) => {
  const { page = 1, perPage = 25, status, type, date, userId, inchargeId, assignedId } = params;
  
  return useQuery({
    queryKey: ['daily-works', { page, perPage, status, type, date, userId, inchargeId, assignedId }],
    queryFn: () => requestJson('get', '/api/v1/daily-works', {
      params: { page, perPage, status, type, date, user_id: userId, incharge_id: inchargeId, assigned_id: assignedId }
    }),
    staleTime: 3 * 60 * 1000, // 3 minutes
  });
};

/**
 * Fetch daily work details
 */
export const useDailyWorkDetails = (dailyWorkId) => {
  return useQuery({
    queryKey: ['daily-works', dailyWorkId],
    queryFn: () => requestJson('get', `/api/v1/daily-works/${dailyWorkId}`),
    enabled: !!dailyWorkId,
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Fetch selectable dates for daily works
 */
export const useDailyWorkSelectableDates = (params = {}) => {
  return useQuery({
    queryKey: ['daily-works', 'selectable-dates', params],
    queryFn: () => requestJson('get', '/api/v1/daily-works/selectable-dates', { params }),
    staleTime: 10 * 60 * 1000, // 10 minutes - dates don't change often
  });
};

/**
 * Update daily work status mutation
 */
export const useUpdateDailyWorkStatus = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, status }) => 
      requestJson('patch', `/api/v1/daily-works/${dailyWorkId}/status`, { status }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works'] });
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId] });
    },
  });
};

/**
 * Update daily work incharge mutation
 */
export const useUpdateDailyWorkIncharge = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, inchargeId }) => 
      requestJson('patch', `/api/v1/daily-works/${dailyWorkId}/incharge`, { incharge_id: inchargeId }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works'] });
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId] });
    },
  });
};

/**
 * Update daily work assigned user mutation
 */
export const useUpdateDailyWorkAssigned = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, assignedId }) => 
      requestJson('patch', `/api/v1/daily-works/${dailyWorkId}/assigned`, { assigned_id: assignedId }),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works'] });
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId] });
    },
  });
};

/**
 * Fetch objections for a daily work
 */
export const useDailyWorkObjections = (dailyWorkId) => {
  return useQuery({
    queryKey: ['daily-works', dailyWorkId, 'objections'],
    queryFn: () => requestJson('get', `/api/v1/daily-works/${dailyWorkId}/objections`),
    enabled: !!dailyWorkId,
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Create objection mutation
 */
export const useCreateObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, data }) => 
      requestJson('post', `/api/v1/daily-works/${dailyWorkId}/objections`, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId, 'objections'] });
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId] });
    },
  });
};

/**
 * Submit objection mutation
 */
export const useSubmitObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, objectionId }) => 
      requestJson('post', `/api/v1/daily-works/${dailyWorkId}/objections/${objectionId}/submit`),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId, 'objections'] });
    },
  });
};

/**
 * Resolve objection mutation
 */
export const useResolveObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, objectionId, data }) => 
      requestJson('post', `/api/v1/daily-works/${dailyWorkId}/objections/${objectionId}/resolve`, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId, 'objections'] });
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId] });
    },
  });
};

/**
 * Reject objection mutation
 */
export const useRejectObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ dailyWorkId, objectionId, data }) => 
      requestJson('post', `/api/v1/daily-works/${dailyWorkId}/objections/${objectionId}/reject`, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId, 'objections'] });
      queryClient.invalidateQueries({ queryKey: ['daily-works', variables.dailyWorkId] });
    },
  });
};

/**
 * Fetch daily works summary filter
 */
export const useDailyWorksSummaryFilter = (params = {}) => {
  return useQuery({
    queryKey: ['daily-works', 'summary-filter', params],
    queryFn: () => requestJson('post', '/daily-works-summary/filter', params),
    staleTime: 5 * 60 * 1000,
  });
};

/**
 * Fetch all daily works
 */
export const useDailyWorksAll = (params = {}) => {
  return useQuery({
    queryKey: ['daily-works', 'all', params],
    queryFn: () => requestJson('get', '/daily-works-all', { params }),
    staleTime: 3 * 60 * 1000,
  });
};

/**
 * Fetch paginated daily works
 */
export const useDailyWorksPaginate = (params = {}) => {
  return useQuery({
    queryKey: ['daily-works', 'paginate', params],
    queryFn: () => requestJson('get', '/daily-works-paginate', { params }),
    staleTime: 3 * 60 * 1000,
  });
};

/**
 * Delete daily work mutation
 */
export const useDeleteDailyWork = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, page }) => requestJson('delete', '/delete-daily-work', { data: { id, page } }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['daily-works'] });
    },
  });
};

/**
 * Import daily works mutation
 */
export const useImportDailyWorks = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (formData) => requestJson('post', route('dailyWorks.import'), formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['daily-works'] });
    },
  });
};
