import { describe, it, expect, vi } from 'vitest';
import { notificationKeys } from '../useNotificationsQuery';

describe('notificationKeys', () => {
  it('exposes stable keys for list and unread-count', () => {
    expect(notificationKeys.list()).toEqual(['notifications', 'list']);
    expect(notificationKeys.unread()).toEqual(['notifications', 'unread']);
  });
});
