import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

// Query keys
export const designationsKeys = {
  all: ['designations'],
  lists: () => [...designationsKeys.all, 'list'],
  list: (filters) => [...designationsKeys.lists(), filters],
  details: () => [...designationsKeys.all, 'detail'],
  detail: (id) => [...designationsKeys.details(), id],
  stats: () => [...designationsKeys.all, 'stats'],
};

// Fetch designations list
export const useDesignationsList = (params = {}) => {
  return useQuery({
    queryKey: designationsKeys.list(params),
    queryFn: async () => {
      const response = await axios.get(route('designations.json'), { params });
      return response.data.designations;
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};

// Fetch designation stats
export const useDesignationStats = () => {
  return useQuery({
    queryKey: designationsKeys.stats(),
    queryFn: async () => {
      const response = await axios.get(route('designations.stats'));
      return response.data.stats;
    },
    staleTime: 10 * 60 * 1000, // 10 minutes
  });
};

// Fetch single designation
export const useDesignation = (id) => {
  return useQuery({
    queryKey: designationsKeys.detail(id),
    queryFn: async () => {
      const response = await axios.get(route('designations.show', { id }));
      return response.data.designation;
    },
    enabled: !!id,
  });
};

// Create designation mutation
export const useCreateDesignation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data) => {
      const response = await axios.post(route('designations.store'), data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: designationsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: designationsKeys.stats() });
    },
  });
};

// Update designation mutation
export const useUpdateDesignation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, data }) => {
      const response = await axios.put(route('designations.update', { id }), data);
      return response.data;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: designationsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: designationsKeys.detail(variables.id) });
      queryClient.invalidateQueries({ queryKey: designationsKeys.stats() });
    },
  });
};

// Delete designation mutation
export const useDeleteDesignation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id) => {
      const response = await axios.delete(route('designations.destroy', { id }));
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: designationsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: designationsKeys.stats() });
    },
  });
};
