import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

// Query keys
export const departmentsKeys = {
  all: ['departments'],
  lists: () => [...departmentsKeys.all, 'list'],
  list: (filters) => [...departmentsKeys.lists(), filters],
  details: () => [...departmentsKeys.all, 'detail'],
  detail: (id) => [...departmentsKeys.details(), id],
  stats: () => [...departmentsKeys.all, 'stats'],
};

// Fetch departments list
export const useDepartmentsList = (params = {}) => {
  return useQuery({
    queryKey: departmentsKeys.list(params),
    queryFn: async () => {
      const response = await axios.get(route('api.departments'), { params });
      return response.data.departments;
    },
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
};

// Fetch department stats
export const useDepartmentStats = () => {
  return useQuery({
    queryKey: departmentsKeys.stats(),
    queryFn: async () => {
      const response = await axios.get(route('departments.stats'));
      return response.data.stats;
    },
    staleTime: 10 * 60 * 1000, // 10 minutes
  });
};

// Fetch single department
export const useDepartment = (id) => {
  return useQuery({
    queryKey: departmentsKeys.detail(id),
    queryFn: async () => {
      const response = await axios.get(route('departments.show', { id }));
      return response.data.department;
    },
    enabled: !!id,
  });
};

// Create department mutation
export const useCreateDepartment = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data) => {
      const response = await axios.post(route('departments.store'), data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: departmentsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: departmentsKeys.stats() });
    },
  });
};

// Update department mutation
export const useUpdateDepartment = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, data }) => {
      const response = await axios.put(route('departments.update', { id }), data);
      return response.data;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: departmentsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: departmentsKeys.detail(variables.id) });
      queryClient.invalidateQueries({ queryKey: departmentsKeys.stats() });
    },
  });
};

// Delete department mutation
export const useDeleteDepartment = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (id) => {
      const response = await axios.delete(route('departments.delete', { id }));
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: departmentsKeys.lists() });
      queryClient.invalidateQueries({ queryKey: departmentsKeys.stats() });
    },
  });
};
