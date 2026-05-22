import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

// Query keys
export const workLocationsKeys = {
  all: ['workLocations'],
  lists: () => [...workLocationsKeys.all, 'list'],
  list: (filters) => [...workLocationsKeys.lists(), filters],
  details: () => [...workLocationsKeys.all, 'detail'],
  detail: (id) => [...workLocationsKeys.details(), id],
};

// Fetch work locations list
export const useWorkLocationsList = (params = {}) => {
  return useQuery({
    queryKey: workLocationsKeys.list(params),
    queryFn: async () => {
      const response = await axios.get(route('allWorkLocations'), { params });
      return response.data.work_locations;
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};

// Fetch single work location
export const useWorkLocation = (id) => {
  return useQuery({
    queryKey: workLocationsKeys.detail(id),
    queryFn: async () => {
      const response = await axios.get(route('workLocations.show', { id }));
      return response.data.work_location;
    },
    enabled: !!id,
  });
};

// Create work location mutation
export const useCreateWorkLocation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data) => {
      const response = await axios.post(route('workLocations.store'), data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workLocationsKeys.lists() });
    },
  });
};

// Update work location mutation
export const useUpdateWorkLocation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, data }) => {
      const response = await axios.put(route('workLocations.update', { id }), data);
      return response.data;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: workLocationsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: workLocationsKeys.detail(variables.id) });
    },
  });
};

// Delete work location mutation
export const useDeleteWorkLocation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id) => {
      const response = await axios.delete(route('workLocations.destroy', { id }));
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workLocationsKeys.lists() });
    },
  });
};
