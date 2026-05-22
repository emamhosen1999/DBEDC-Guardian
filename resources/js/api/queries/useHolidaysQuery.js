import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

// Query keys
export const holidaysKeys = {
  all: ['holidays'],
  lists: () => [...holidaysKeys.all, 'list'],
  list: (filters) => [...holidaysKeys.lists(), filters],
  details: () => [...holidaysKeys.all, 'detail'],
  detail: (id) => [...holidaysKeys.details(), id],
  stats: () => [...holidaysKeys.all, 'stats'],
};

// Fetch holidays list
export const useHolidaysList = (params = {}) => {
  return useQuery({
    queryKey: holidaysKeys.list(params),
    queryFn: async () => {
      const response = await axios.get(route('api.holidays'), { params });
      return response.data.holidays;
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};

// Fetch holiday stats
export const useHolidayStats = () => {
  return useQuery({
    queryKey: holidaysKeys.stats(),
    queryFn: async () => {
      const response = await axios.get(route('holidays.stats'));
      return response.data.stats;
    },
    staleTime: 10 * 60 * 1000, // 10 minutes
  });
};

// Fetch single holiday
export const useHoliday = (id) => {
  return useQuery({
    queryKey: holidaysKeys.detail(id),
    queryFn: async () => {
      const response = await axios.get(route('api.holidays.show', { id }));
      return response.data.holiday;
    },
    enabled: !!id,
  });
};

// Create holiday mutation
export const useCreateHoliday = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data) => {
      const response = await axios.post(route('api.holidays.store'), data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: holidaysKeys.lists() });
      queryClient.invalidateQueries({ queryKey: holidaysKeys.stats() });
    },
  });
};

// Update holiday mutation
export const useUpdateHoliday = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, data }) => {
      const response = await axios.put(route('api.holidays.update', { id }), data);
      return response.data;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: holidaysKeys.lists() });
      queryClient.invalidateQueries({ queryKey: holidaysKeys.detail(variables.id) });
      queryClient.invalidateQueries({ queryKey: holidaysKeys.stats() });
    },
  });
};

// Delete holiday mutation
export const useDeleteHoliday = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id) => {
      const response = await axios.delete(route('api.holidays.destroy', { id }));
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: holidaysKeys.lists() });
      queryClient.invalidateQueries({ queryKey: holidaysKeys.stats() });
    },
  });
};
