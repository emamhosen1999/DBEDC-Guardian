import { describe, it, expect, vi } from 'vitest';
import { handleCellConflict } from '../rosterCellConflict';

describe('roster cell 409 handling', () => {
  it('surfaces the server conflict message', () => {
    const showToast = { warning: vi.fn() };
    const err = { response: { status: 409, data: { message: 'changed by someone else' } } };
    const handled = handleCellConflict(err, showToast);
    expect(handled).toBe(true);
    expect(showToast.warning).toHaveBeenCalledWith('changed by someone else');
  });

  it('ignores non-409 errors (leaves them to the default error toast)', () => {
    const showToast = { warning: vi.fn() };
    const err = { response: { status: 500, data: {} } };
    expect(handleCellConflict(err, showToast)).toBe(false);
    expect(showToast.warning).not.toHaveBeenCalled();
  });
});
