import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

// Query keys
export const employeesKeys = {
  all: ['employees'],
  lists: () => [...employeesKeys.all, 'list'],
  list: (filters) => [...employeesKeys.lists(), filters],
  details: () => [...employeesKeys.all, 'detail'],
  detail: (id) => [...employeesKeys.details(), id],
  stats: () => [...employeesKeys.all, 'stats'],
};

// Fetch employees list (web session API)
export const useEmployeesList = (params = {}) => {
  return useQuery({
    queryKey: employeesKeys.list(params),
    queryFn: async () => {
      const { data } = await axios.get(route('employees.paginate'), { params });
      const paginator = data.employees;

      return {
        data: paginator?.data ?? [],
        total: paginator?.total ?? 0,
        current_page: paginator?.current_page ?? 1,
        last_page: paginator?.last_page ?? 1,
        per_page: paginator?.per_page ?? params.perPage ?? 10,
        allManagers: data.allManagers ?? [],
        stats: data.stats,
      };
    },
    staleTime: 5 * 60 * 1000,
  });
};

// Fetch employee stats
export const useEmployeeStats = () => {
  return useQuery({
    queryKey: employeesKeys.stats(),
    queryFn: async () => {
      const { data } = await axios.get(route('employees.stats'));
      return data;
    },
    staleTime: 10 * 60 * 1000,
  });
};

// Fetch single employee
export const useEmployee = (id) => {
  return useQuery({
    queryKey: employeesKeys.detail(id),
    queryFn: async () => {
      const { data } = await axios.get(route('employees.show', { id }));
      return data.employee ?? data;
    },
    enabled: !!id,
  });
};

// Create employee mutation
export const useCreateEmployee = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (data) => {
      const { data: body } = await axios.post(route('users.store'), data);
      return body;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
      queryClient.invalidateQueries({ queryKey: employeesKeys.stats() });
    },
  });
};

// Update employee mutation
export const useUpdateEmployee = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, data }) => {
      const { data: body } = await axios.put(route('users.update', { id }), data);
      return body;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
      queryClient.invalidateQueries({ queryKey: employeesKeys.detail(variables.id) });
      queryClient.invalidateQueries({ queryKey: employeesKeys.stats() });
    },
  });
};

// Delete employee mutation
export const useDeleteEmployee = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => axios.delete(route('users.destroy', { id })),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
      queryClient.invalidateQueries({ queryKey: employeesKeys.stats() });
    },
  });
};

// Update employee department mutation
export const useUpdateDepartment = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, department }) => axios.put(route('users.update-department', { id }), { department }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
    },
  });
};

// Update employee designation mutation
export const useUpdateDesignation = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, designation_id }) => axios.post(route('users.updateDesignation', { id }), { designation_id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
    },
  });
};

// Update employee attendance type mutation
export const useUpdateAttendanceType = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, attendance_type_id }) => axios.post(route('users.updateAttendanceType', { userId: id }), { attendance_type_id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
    },
  });
};

// Update employee biometric device mutation
export const useUpdateBiometricDevice = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ id, biometric_device_id }) => {
      const response = await axios.post(route('users.updateBiometricDevice', { id }), { biometric_device_id });
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
    },
  });
};

// Update employee report-to mutation
export const useUpdateReportTo = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, report_to }) => {
      const response = await axios.post(route('users.updateReportTo', { id }), { report_to });
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
    },
  });
};

export const useUpdateWorkLocation = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, work_location_id }) => {
      const response = await axios.put(route('users.updateWorkLocation', { id }), { work_location_id });
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeesKeys.lists() });
    },
  });
};
