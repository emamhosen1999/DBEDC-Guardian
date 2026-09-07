import { showToast } from '@/utils/toastUtils';

export function showOperationMutationErrors(errors) {
    const message = errors?.conflict
        || Object.values(errors || {}).flat().find(Boolean)
        || 'The operation could not be completed. Refresh and try again.';

    showToast.error(String(message));
}
