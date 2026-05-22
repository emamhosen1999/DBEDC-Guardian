import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '../client';

/**
 * Create objection mutation
 */
export const useCreateObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (formData) => requestJson('post', route('objections.store'), formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};

/**
 * Suggest RFIs mutation
 */
export const useSuggestRfis = () => {
  return useMutation({
    mutationFn: (params) => requestJson('get', route('objections.suggestRfis'), { params }),
  });
};

/**
 * Attach RFIs mutation
 */
export const useAttachRfis = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ objectionId, rfiIds }) => 
      requestJson('post', route('objections.attachRfis', objectionId), { rfi_ids: rfiIds }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
    },
  });
};

/**
 * Update objection mutation
 */
export const useUpdateObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }) => requestJson('put', route('objections.update', id), data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};

/**
 * Submit objection mutation
 */
export const useSubmitObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => requestJson('post', route('objections.submit', id)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};

/**
 * Review objection mutation
 */
export const useReviewObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => requestJson('post', route('objections.review', id)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};

/**
 * Resolve objection mutation
 */
export const useResolveObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, resolutionNotes }) => 
      requestJson('post', route('objections.resolve', id), { resolution_notes: resolutionNotes }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};

/**
 * Reject objection mutation
 */
export const useRejectObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, resolutionNotes }) => 
      requestJson('post', route('objections.reject', id), { resolution_notes: resolutionNotes }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};

/**
 * Delete objection mutation
 */
export const useDeleteObjection = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id) => requestJson('delete', route('objections.destroy', id)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['objections'] });
      queryClient.invalidateQueries({ queryKey: ['statistics'] });
    },
  });
};
