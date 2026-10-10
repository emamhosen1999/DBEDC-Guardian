import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

// Query keys
export const notificationKeys = {
  all: ['notifications'],
  // With params the key is per page: paging refetches, and the header bell's unparameterised list keeps its own cache entry.
  // invalidateQueries({ queryKey: list() }) still matches every page by prefix.
  list: (params) => (params && Object.keys(params).length ? ['notifications', 'list', params] : ['notifications', 'list']),
  unread: () => ['notifications', 'unread'],
};

// Unread count for the header bell badge
export const useUnreadCount = () =>
  useQuery({
    queryKey: notificationKeys.unread(),
    queryFn: () => requestJson('get', '/notifications/unread-count'),
    staleTime: 30 * 1000,
    select: (d) => d?.count ?? 0,
  });

// Paginated notifications list (dropdown preview + View-all page)
export const useNotificationsList = (params = {}) =>
  useQuery({
    queryKey: notificationKeys.list(params),
    queryFn: () => requestJson('get', '/notifications/list', { params }),
  });

// Mark a single notification read
export const useMarkRead = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id) => requestJson('post', `/notifications/${id}/read`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.unread() });
      qc.invalidateQueries({ queryKey: notificationKeys.list() });
    },
  });
};

// Mark all notifications read
export const useMarkAllRead = () => {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => requestJson('post', '/notifications/read-all'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.unread() });
      qc.invalidateQueries({ queryKey: notificationKeys.list() });
    },
  });
};
