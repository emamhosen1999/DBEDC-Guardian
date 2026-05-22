import { useQuery, useMutation } from '@tanstack/react-query';
import { requestJson } from '../client';

/**
 * Fetch system monitoring metrics
 */
export const useSystemMonitoringMetrics = (params = {}) => {
  const { type = 'overview', period = '24h' } = params;
  
  return useQuery({
    queryKey: ['system-monitoring', 'metrics', { type, period }],
    queryFn: () => requestJson('get', '/api/system-monitoring/metrics', { params: { type, period }, unwrap: false }),
    staleTime: 30 * 1000, // 30 seconds
    refetchInterval: 30 * 1000, // Auto-refresh every 30 seconds
  });
};

/**
 * Export system report mutation
 */
export const useExportSystemReport = () => {
  return useMutation({
    mutationFn: () => requestJson('get', '/admin/system-report', { responseType: 'blob' }),
  });
};
