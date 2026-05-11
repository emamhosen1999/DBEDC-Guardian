/**
 * Toast Utility - Consistent, Promise-based Toast Notifications
 *
 * Backed by sonner. The showToast API is identical to the previous
 * react-toastify version so all 75+ call-sites require zero changes.
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

import { toast } from 'sonner';

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

    return toast.promise(promise, {
      loading: loadingMsg,
      success: successFn,
      error:   errorFn,
    }, { duration: options.autoClose ?? 3000, ...normOpts(options) });
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

  success: (message, options = {}) =>
    toast.success(message, { duration: 3000, ...normOpts(options) }),

  error: (message, options = {}) =>
    toast.error(message, { duration: 5000, ...normOpts(options) }),

  warning: (message, options = {}) =>
    toast.warning(message, { duration: 4000, ...normOpts(options) }),

  info: (message, options = {}) =>
    toast.info(message, { duration: 3000, ...normOpts(options) }),

  /** Returns a toast ID that can be passed to updateSuccess / updateError */
  loading: (message, options = {}) =>
    toast.loading(message, normOpts(options)),

  /** Transition a loading toast to success */
  updateSuccess: (toastId, message, options = {}) =>
    toast.success(message, { id: toastId, duration: 3000, ...normOpts(options) }),

  /** Transition a loading toast to error */
  updateError: (toastId, message, options = {}) =>
    toast.error(message, { id: toastId, duration: 5000, ...normOpts(options) }),

  dismiss: (toastId) => toast.dismiss(toastId),
};

export const extractErrorMessage   = getErrorMessage;
export const extractSuccessMessage = getSuccessMessage;

export default showToast;
