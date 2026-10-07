import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

const REQUEST_LOGS_BASE = '/settings/request-logs';

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
    queryFn: () => requestJson('get', `${REQUEST_LOGS_BASE}/list`, { params }),
    staleTime: 2 * 60 * 1000, // 2 minutes
  });
};

// Delete single log mutation
export const useDeleteLog = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => requestJson('delete', `${REQUEST_LOGS_BASE}/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: requestLogsKeys.lists() });
    },
  });
};

// Bulk delete logs mutation
export const useBulkDeleteLogs = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (ids) => requestJson('post', `${REQUEST_LOGS_BASE}/bulk-delete`, { ids }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: requestLogsKeys.lists() });
    },
  });
};

// Clear all logs mutation
export const useClearAllLogs = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: () => requestJson('post', `${REQUEST_LOGS_BASE}/clear-all`, { confirm: 'DELETE_ALL' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: requestLogsKeys.lists() });
    },
  });
};

// Export logs mutation
export const exportLogs = (filters) => requestJson('get', `${REQUEST_LOGS_BASE}/export`, {
  params: filters,
  responseType: 'blob',
});

export const useExportLogs = () => useMutation({ mutationFn: exportLogs });

// View log details query
export const getLogDetails = (id) => requestJson('get', `${REQUEST_LOGS_BASE}/${id}`);

export const useLogDetails = (id) => {
  return useQuery({
    queryKey: ['requestLogs', id],
    queryFn: () => getLogDetails(id),
    enabled: !!id,
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};
