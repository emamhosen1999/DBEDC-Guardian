import { extractErrorMessage } from '@/utils/toastUtils';

/**
 * Handle a 409 from the roster-cell write. Returns true if it was a conflict
 * (and a warning toast was shown), false otherwise so the caller can fall
 * back to its default error handling.
 */
export function handleCellConflict(err, showToast) {
  if (err?.response?.status !== 409) return false;
  showToast.warning(extractErrorMessage(err, 'This cell was changed by someone else.'));
  return true;
}
