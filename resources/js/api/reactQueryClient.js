import { QueryClient } from '@tanstack/react-query';

// Create React Query client with optimized defaults
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Refetch on window focus (optional, can be disabled)
      refetchOnWindowFocus: false,
      
      // Refetch on reconnect
      refetchOnReconnect: true,
      
      // Retry failed requests
      retry: 1,
      
      // Retry delay (exponential backoff)
      retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
      
      // Stale time - data considered fresh for 5 minutes
      staleTime: 5 * 60 * 1000,
      
      // Cache time - keep data in cache for 10 minutes
      gcTime: 10 * 60 * 1000,
      
      // Error boundary handling
      throwOnError: false,
    },
    mutations: {
      // Retry failed mutations
      retry: 0,
      
      // Error boundary handling
      throwOnError: false,
    },
  },
});

export default queryClient;
