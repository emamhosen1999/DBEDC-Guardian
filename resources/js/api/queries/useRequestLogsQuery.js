import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

// Query keys
export const requestLogsKeys = {
  all: ['requestLogs'],
  lists: () => [...requestLogsKeys.all, 'list'],
  list: (filters) => [...requestLogsKeys.lists(), filters],
};

// Fetch request logs list
export const useRequestLogsList = (params = {}) => {
  return useQuery({
    queryKey: requestLogsKeys.list(params),
    queryFn: () => requestJson('get', '/request-logs/list', { params }),
    staleTime: 2 * 60 * 1000, // 2 minutes
  });
};

// Delete single log mutation
export const useDeleteLog = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => requestJson('delete', `/request-logs/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: requestLogsKeys.lists() });
    },
  });
};

// Bulk delete logs mutation
export const useBulkDeleteLogs = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (ids) => requestJson('post', '/request-logs/bulk-delete', { ids }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: requestLogsKeys.lists() });
    },
  });
};

// Clear all logs mutation
export const useClearAllLogs = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: () => requestJson('post', '/request-logs/clear-all', { confirm: 'DELETE_ALL' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: requestLogsKeys.lists() });
    },
  });
};

// Export logs mutation
export const useExportLogs = () => {
  return useMutation({
    mutationFn: (filters) => requestJson('get', '/request-logs/export', { 
      params: filters,
      responseType: 'blob'
    }),
  });
};

// View log details query
export const useLogDetails = (id) => {
  return useQuery({
    queryKey: ['requestLogs', id],
    queryFn: () => requestJson('get', `/request-logs/${id}`),
    enabled: !!id,
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};
