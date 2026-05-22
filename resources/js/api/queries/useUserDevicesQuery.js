import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

// Query keys
export const userDevicesKeys = {
  all: ['userDevices'],
  lists: () => [...userDevicesKeys.all, 'list'],
  list: (userId) => [...userDevicesKeys.lists(), userId],
};

// Fetch user devices list
export const useUserDevicesList = (userId) => {
  return useQuery({
    queryKey: userDevicesKeys.list(userId),
    queryFn: async () => {
      const response = await axios.get(route('admin.users.devices', { userId }), {
        headers: {
          Accept: 'application/json',
        },
      });
      return response.data;
    },
    enabled: !!userId,
    staleTime: 2 * 60 * 1000, // 2 minutes
  });
};

// Toggle single device login mutation
export const useToggleSingleDeviceLogin = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async (userId) => {
      const response = await axios.post(route('admin.users.devices.toggle', { userId }));
      return response.data;
    },
    onSuccess: (_, userId) => {
      queryClient.invalidateQueries({ queryKey: userDevicesKeys.list(userId) });
    },
  });
};

// Reset devices mutation
export const useResetDevices = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ userId, reason }) => {
      const response = await axios.post(route('admin.users.devices.reset', { userId }), {
        reason: reason === '' ? null : reason,
      });
      return response.data;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: userDevicesKeys.list(variables.userId) });
    },
  });
};

// Deactivate device mutation
export const useDeactivateDevice = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: async ({ userId, deviceId }) => {
      const response = await axios.delete(route('admin.users.devices.deactivate', {
        userId,
        deviceId,
      }));
      return response.data;
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: userDevicesKeys.list(variables.userId) });
    },
  });
};
