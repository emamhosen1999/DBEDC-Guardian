import { useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * A reusable hook for React Query optimistic mutations.
 * 
 * @param {Object} options
 * @param {Function} options.mutationFn - The actual API call function.
 * @param {Array|Function} options.queryKey - The query key (or a function returning a key based on variables) to optimistically update.
 * @param {Function} options.updateFn - A function to apply the optimistic patch: (oldData, variables) => newData.
 * @param {Function} [options.onSuccess] - Optional success callback.
 * @param {Function} [options.onError] - Optional error callback.
 * @param {Function} [options.onSettled] - Optional settled callback.
 */
export function useOptimisticMutation({
  mutationFn,
  queryKey,
  updateFn,
  onSuccess,
  onError,
  onSettled,
}) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onMutate: async (variables) => {
      // Resolve the query key if it's a function
      const targetQueryKey = typeof queryKey === 'function' ? queryKey(variables) : queryKey;

      // Cancel outgoing refetches so they don't overwrite our optimistic update
      await queryClient.cancelQueries({ queryKey: targetQueryKey });

      // Snapshot the current value
      const previousData = queryClient.getQueryData(targetQueryKey);

      // Optimistically update to the new value
      if (updateFn) {
        queryClient.setQueryData(targetQueryKey, (old) => updateFn(old, variables));
      }

      // Return context with snapshotted value and resolved key
      return { previousData, targetQueryKey };
    },
    onError: (err, variables, context) => {
      // Revert cache to snapshot on failure
      if (context?.targetQueryKey && context?.previousData !== undefined) {
        queryClient.setQueryData(context.targetQueryKey, context.previousData);
      }
      if (onError) onError(err, variables, context);
    },
    onSuccess: (data, variables, context) => {
      if (onSuccess) onSuccess(data, variables, context);
    },
    onSettled: (data, error, variables, context) => {
      // Re-query to guarantee eventual consistency
      if (context?.targetQueryKey) {
        queryClient.invalidateQueries({ queryKey: context.targetQueryKey });
      }
      if (onSettled) onSettled(data, error, variables, context);
    },
  });
}
