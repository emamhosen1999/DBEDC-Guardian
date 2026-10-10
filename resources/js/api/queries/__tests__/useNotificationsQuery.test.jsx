import { describe, it, expect, vi } from 'vitest';
import { notificationKeys } from '../useNotificationsQuery';

describe('notificationKeys', () => {
  it('exposes stable keys for list and unread-count', () => {
    expect(notificationKeys.list()).toEqual(['notifications', 'list']);
    expect(notificationKeys.unread()).toEqual(['notifications', 'unread']);
  });

  it('keys each page of the list separately, so paging refetches and the header bell keeps its own entry', () => {
    expect(notificationKeys.list({ page: 2, per_page: 20 })).toEqual(['notifications', 'list', { page: 2, per_page: 20 }]);
    expect(notificationKeys.list({ page: 2, per_page: 20 })).not.toEqual(notificationKeys.list());
    expect(notificationKeys.list({})).toEqual(['notifications', 'list']);
  });
});
