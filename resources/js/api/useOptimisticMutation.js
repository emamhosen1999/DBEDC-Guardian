import { useMutation, useQueryClient } from '@tanstack/react-query';

/**
 * Apply an optimistic patch to the cache and return a rollback context.
 *
 * Two modes:
 *  - exact (default): snapshot + patch the single query at `queryKey`.
 *  - partialMatch: snapshot + patch EVERY cached query whose key starts with
 *    `queryKey` (e.g. a paginated/filtered list cached under varying params),
 *    so one row edit is reflected across all of them.
 *
 * Pure w.r.t. React — drives a QueryClient directly so it is unit-testable.
 *
 * @returns {{ targetQueryKey, partialMatch, previousData?, previousEntries? }}
 */
export function applyOptimisticPatch(queryClient, { queryKey, updateFn, variables, partialMatch = false }) {
  if (partialMatch) {
    const previousEntries = queryClient.getQueriesData({ queryKey });
    if (updateFn) {
      queryClient.setQueriesData({ queryKey }, (old) => (old === undefined ? old : updateFn(old, variables)));
    }
    return { targetQueryKey: queryKey, partialMatch: true, previousEntries };
  }

  const previousData = queryClient.getQueryData(queryKey);
  if (updateFn) {
    queryClient.setQueryData(queryKey, (old) => updateFn(old, variables));
  }
  return { targetQueryKey: queryKey, partialMatch: false, previousData };
}

/**
 * Restore the cache to the snapshot captured by applyOptimisticPatch.
 */
export function rollbackOptimisticPatch(queryClient, context) {
  if (!context) return;
  if (context.partialMatch) {
    for (const [key, data] of context.previousEntries ?? []) {
      queryClient.setQueryData(key, data);
    }
    return;
  }
  if (context.targetQueryKey && context.previousData !== undefined) {
    queryClient.setQueryData(context.targetQueryKey, context.previousData);
  }
}

/**
 * A reusable hook for React Query optimistic mutations.
 *
 * @param {Object} options
 * @param {Function} options.mutationFn - The actual API call function.
 * @param {Array|Function} options.queryKey - The query key (or a function returning a key based on variables) to optimistically update. In partialMatch mode this is the key PREFIX.
 * @param {Function} options.updateFn - A function to apply the optimistic patch: (oldData, variables) => newData.
 * @param {boolean} [options.partialMatch=false] - When true, patch every query matching the key prefix (paginated/filtered lists) instead of one exact key.
 * @param {Function} [options.onSuccess] - Optional success callback.
 * @param {Function} [options.onError] - Optional error callback.
 * @param {Function} [options.onSettled] - Optional settled callback.
 */
export function useOptimisticMutation({
  mutationFn,
  queryKey,
  updateFn,
  partialMatch = false,
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

      // Snapshot + optimistically patch (returns the rollback context)
      return applyOptimisticPatch(queryClient, {
        queryKey: targetQueryKey,
        updateFn,
        variables,
        partialMatch,
      });
    },
    onError: (err, variables, context) => {
      // Revert cache to snapshot on failure
      rollbackOptimisticPatch(queryClient, context);
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
