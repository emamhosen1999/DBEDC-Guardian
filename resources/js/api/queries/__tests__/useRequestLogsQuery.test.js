import { describe, expect, it, vi } from 'vitest';

vi.mock('../../client', () => ({
  requestJson: vi.fn(),
}));

import { getLogDetails } from '../useRequestLogsQuery';
import { requestJson } from '../../client';

describe('request log query endpoints', () => {
  it('uses the settings-prefixed detail route', async () => {
    requestJson.mockResolvedValueOnce({ id: 42 });

    await getLogDetails(42);

    expect(requestJson).toHaveBeenCalledWith('get', '/settings/request-logs/42');
  });

  it('exports with the filters as the query string and a blob response', async () => {
    const { exportLogs } = await import('../useRequestLogsQuery');
    requestJson.mockResolvedValueOnce('csv');

    await exportLogs({ status: '500' });

    expect(requestJson).toHaveBeenLastCalledWith('get', '/settings/request-logs/export', {
      params: { status: '500' },
      responseType: 'blob',
    });
  });
});
