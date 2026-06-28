import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

export const notificationSettingsKeys = { all: ['notificationSettings'] };

export const useNotificationTypes = () =>
  useQuery({
    queryKey: notificationSettingsKeys.all,
    queryFn: () => requestJson('get', '/admin/settings/notifications/list'),
    staleTime: 60 * 1000,
  });

export const useUpdateNotificationType = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...payload }) =>
      requestJson('put', `/admin/settings/notifications/${id}`, { data: payload }),
    onSuccess: () => qc.invalidateQueries({ queryKey: notificationSettingsKeys.all }),
  });
};
