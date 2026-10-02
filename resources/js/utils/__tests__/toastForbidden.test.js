import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { showToast, showForbiddenToastUnlessHandled } from '../toastUtils';

describe('generic 403 toast is de-duplicated', () => {
  beforeEach(() => { vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

  it('is dropped when the caller shows its own error toast (the double-toast bug)', () => {
    const spy = vi.spyOn(showToast, 'error');
    showForbiddenToastUnlessHandled('Access Denied: generic');
    showToast.error('You do not have access to this employee.'); // the form's own catch, right after
    vi.advanceTimersByTime(500);
    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith('You do not have access to this employee.');
  });

  it('still shows when nothing else explained the failure', () => {
    const spy = vi.spyOn(showToast, 'error');
    showForbiddenToastUnlessHandled('Access Denied: generic');
    vi.advanceTimersByTime(500);
    expect(spy).toHaveBeenCalledWith('Access Denied: generic');
  });
});
