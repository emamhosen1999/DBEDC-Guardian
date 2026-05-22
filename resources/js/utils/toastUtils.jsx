/**
 * Toast Utility - Consistent, Promise-based Toast Notifications
 *
 * Backed by @radix-ui/react-toast. The showToast API is identical to the previous
 * sonner/react-toastify versions so all 75+ call-sites require zero changes.
 *
 * Usage:
 *   import { showToast } from '@/utils/toastUtils';
 *
 *   showToast.promise(apiCall(), {
 *     loading: 'Saving...',
 *     success: 'Saved!',
 *     error: (err) => err.response?.data?.message || 'Failed',
 *   });
 */

import * as Toast from '@radix-ui/react-toast';
import { create } from 'zustand';
import React from 'react';

// ── Toast State Management (Zustand) ───────────────────────────────────────────

const useToastStore = create((set) => ({
  toasts: [],
  addToast: (toast) => set((state) => ({ toasts: [...state.toasts, { ...toast, id: crypto.randomUUID() }] })),
  updateToast: (id, updates) => set((state) => ({
    toasts: state.toasts.map((t) => (t.id === id ? { ...t, ...updates } : t))
  })),
  removeToast: (id) => set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })),
}));

// ── Helpers ──────────────────────────────────────────────────────────────────

const getErrorMessage = (error, fallback = 'An error occurred') => {
  if (typeof error === 'string') return error;
  if (error?.response?.data?.message) return error.response.data.message;
  if (error?.response?.data?.error)   return error.response.data.error;
  if (error?.message)                 return error.message;
  return fallback;
};

const getSuccessMessage = (response, fallback = 'Operation completed') => {
  if (typeof response === 'string')   return response;
  if (response?.data?.message)        return response.data.message;
  if (response?.message)              return response.message;
  return fallback;
};

// ── Action message templates ──────────────────────────────────────────────────

export const actionMessages = {
  create:   { loading: (e) => `Creating ${e}...`,   success: (e) => `${e} created successfully!`,  error: (e) => `Failed to create ${e}`   },
  update:   { loading: (e) => `Updating ${e}...`,   success: (e) => `${e} updated successfully!`,  error: (e) => `Failed to update ${e}`   },
  delete:   { loading: (e) => `Deleting ${e}...`,   success: (e) => `${e} deleted successfully!`,  error: (e) => `Failed to delete ${e}`   },
  save:     { loading: (e) => `Saving ${e}...`,     success: (e) => `${e} saved successfully!`,    error: (e) => `Failed to save ${e}`     },
  fetch:    { loading: (e) => `Loading ${e}...`,    success: (e) => `${e} loaded!`,                error: (e) => `Failed to load ${e}`     },
  refresh:  { loading: (e) => `Refreshing ${e}...`, success: (e) => `${e} refreshed!`,             error: (e) => `Failed to refresh ${e}`  },
  export:   { loading: (e) => `Exporting ${e}...`,  success: (e) => `${e} exported!`,              error: (e) => `Failed to export ${e}`   },
  import:   { loading: (e) => `Importing ${e}...`,  success: (e) => `${e} imported!`,              error: (e) => `Failed to import ${e}`   },
  approve:  { loading: (e) => `Approving ${e}...`,  success: (e) => `${e} approved!`,              error: (e) => `Failed to approve ${e}`  },
  reject:   { loading: (e) => `Rejecting ${e}...`,  success: (e) => `${e} rejected!`,              error: (e) => `Failed to reject ${e}`   },
  submit:   { loading: (e) => `Submitting ${e}...`, success: (e) => `${e} submitted!`,             error: (e) => `Failed to submit ${e}`   },
  cancel:   { loading: (e) => `Cancelling ${e}...`, success: (e) => `${e} cancelled!`,             error: (e) => `Failed to cancel ${e}`   },
  assign:   { loading: (e) => `Assigning ${e}...`,  success: (e) => `${e} assigned!`,              error: (e) => `Failed to assign ${e}`   },
  unassign: { loading: (e) => `Removing ${e}...`,   success: (e) => `${e} removed!`,               error: (e) => `Failed to remove ${e}`   },
  login:    { loading: () => 'Signing in...',        success: () => 'Signed in successfully!',      error: () => 'Failed to sign in'         },
  logout:   { loading: () => 'Signing out...',       success: () => 'Signed out successfully!',     error: () => 'Failed to sign out'        },
  register: { loading: () => 'Creating account...',  success: () => 'Account created successfully!', error: () => 'Failed to create account' },
};

// Kept for backwards compatibility — sonner manages its own styling
export const toastStyles = {};

// ── Normalise options: map react-toastify keys → sonner keys ─────────────────

const normOpts = ({ autoClose, style: _s, icon: _i, position: _p, ...rest } = {}) => ({
  duration: autoClose,
  ...rest,
});

// ── Main utility ──────────────────────────────────────────────────────────────

export const showToast = {
  /**
   * Promise toast: loading → success / error
   *
   * Accepts both formats:
   *   new  : { loading, success, error }           (strings or (data) => string)
   *   legacy: { pending, success: { render }, error: { render } }
   */
  promise: (promise, messages = {}, options = {}) => {
    const isLegacy =
      messages.pending ||
      (messages.success && typeof messages.success === 'object' && messages.success.render) ||
      (messages.error   && typeof messages.error   === 'object' && messages.error.render);

    const loadingMsg = isLegacy
      ? (typeof messages.pending === 'object' ? messages.pending.render?.() : messages.pending) ?? 'Processing...'
      : messages.loading ?? 'Processing...';

    const successFn = isLegacy
      ? (data) => messages.success?.render?.({ data }) ?? 'Done!'
      : (data) => {
          const s = messages.success ?? 'Success!';
          return typeof s === 'function' ? s(data) : getSuccessMessage(data, s);
        };

    const errorFn = isLegacy
      ? (err) => messages.error?.render?.({ data: err }) ?? 'Error'
      : (err) => {
          const e = messages.error ?? 'Something went wrong';
          return typeof e === 'function' ? e(err) : getErrorMessage(err, e);
        };

    // Create loading toast
    const toastId = crypto.randomUUID();
    useToastStore.getState().addToast({
      id: toastId,
      title: loadingMsg,
      variant: 'default',
    });

    // Handle promise
    promise
      .then((data) => {
        useToastStore.getState().updateToast(toastId, {
          title: successFn(data),
          variant: 'success',
        });
        setTimeout(() => useToastStore.getState().removeToast(toastId), options.autoClose ?? 3000);
      })
      .catch((err) => {
        useToastStore.getState().updateToast(toastId, {
          title: errorFn(err),
          variant: 'error',
        });
        setTimeout(() => useToastStore.getState().removeToast(toastId), 5000);
      });

    return promise;
  },

  /** Shorthand: showToast.action('update', 'department', axios.put(...)) */
  action: (action, entity, promise, options = {}) => {
    const msgs = actionMessages[action];
    if (!msgs) {
      return showToast.promise(promise, {
        loading: `Processing ${entity}...`,
        success: `${entity} operation completed!`,
        error:   `Failed to process ${entity}`,
      }, options);
    }
    return showToast.promise(promise, {
      loading: msgs.loading(entity),
      success: (data) => getSuccessMessage(data, msgs.success(entity)),
      error:   (err)  => getErrorMessage(err,  msgs.error(entity)),
    }, options);
  },

  success: (message, options = {}) => {
    const toastId = crypto.randomUUID();
    useToastStore.getState().addToast({
      id: toastId,
      title: message,
      variant: 'success',
    });
    setTimeout(() => useToastStore.getState().removeToast(toastId), 3000);
    return toastId;
  },

  error: (message, options = {}) => {
    const toastId = crypto.randomUUID();
    useToastStore.getState().addToast({
      id: toastId,
      title: message,
      variant: 'error',
    });
    setTimeout(() => useToastStore.getState().removeToast(toastId), 5000);
    return toastId;
  },

  warning: (message, options = {}) => {
    const toastId = crypto.randomUUID();
    useToastStore.getState().addToast({
      id: toastId,
      title: message,
      variant: 'warning',
    });
    setTimeout(() => useToastStore.getState().removeToast(toastId), 4000);
    return toastId;
  },

  info: (message, options = {}) => {
    const toastId = crypto.randomUUID();
    useToastStore.getState().addToast({
      id: toastId,
      title: message,
      variant: 'default',
    });
    setTimeout(() => useToastStore.getState().removeToast(toastId), 3000);
    return toastId;
  },

  /** Returns a toast ID that can be passed to updateSuccess / updateError */
  loading: (message, options = {}) => {
    const toastId = crypto.randomUUID();
    useToastStore.getState().addToast({
      id: toastId,
      title: message,
      variant: 'default',
    });
    return toastId;
  },

  /** Transition a loading toast to success */
  updateSuccess: (toastId, message, options = {}) => {
    useToastStore.getState().updateToast(toastId, {
      title: message,
      variant: 'success',
    });
    setTimeout(() => useToastStore.getState().removeToast(toastId), 3000);
  },

  /** Transition a loading toast to error */
  updateError: (toastId, message, options = {}) => {
    useToastStore.getState().updateToast(toastId, {
      title: message,
      variant: 'error',
    });
    setTimeout(() => useToastStore.getState().removeToast(toastId), 5000);
  },

  dismiss: (toastId) => useToastStore.getState().removeToast(toastId),
};

export const extractErrorMessage   = getErrorMessage;
export const extractSuccessMessage = getSuccessMessage;
export { useToastStore };

export default showToast;
