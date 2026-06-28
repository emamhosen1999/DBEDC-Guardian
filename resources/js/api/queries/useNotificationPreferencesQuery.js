import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

// Query keys
export const notificationPreferencesKeys = {
    all: ['notificationPreferences'],
    list: () => [...notificationPreferencesKeys.all, 'list'],
};

/**
 * Fetch current user's notification preferences + category metadata.
 * Returns { categories: { [category]: { locked_channels } }, preferences: [...] }
 */
export const useNotificationPreferences = () => {
    return useQuery({
        queryKey: notificationPreferencesKeys.list(),
        queryFn: () => requestJson('get', '/settings/notifications/list'),
        staleTime: 2 * 60 * 1000, // 2 minutes
    });
};

/**
 * Persist the user's notification preferences.
 * Payload: { preferences: [{ category, channel, enabled }] }
 * Locked channels are silently ignored on the server.
 */
export const useSaveNotificationPreferences = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (preferences) =>
            requestJson('put', '/settings/notifications', { data: { preferences } }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: notificationPreferencesKeys.list() });
        },
    });
};
