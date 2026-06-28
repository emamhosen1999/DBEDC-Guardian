import { describe, it, expect } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { applyOptimisticPatch, rollbackOptimisticPatch } from '../useOptimisticMutation';

/**
 * Phase C: the optimistic helper must support a PARTIAL-key patch so a single
 * row change is applied across every cached page/filter of a list (e.g. the
 * daily-timesheet cached under {date,page,perPage,employee}) — then rolled back
 * to the exact snapshot if the server rejects. Driven against a real QueryClient
 * (query-core works without React).
 */
describe('optimistic partial-match patch + rollback', () => {
  const inc = (old) => (old === undefined ? old : { ...old, n: old.n + 1 });

  it('patches every query matching the partial key', () => {
    const qc = new QueryClient();
    qc.setQueryData(['list', { page: 1 }], { n: 1 });
    qc.setQueryData(['list', { page: 2 }], { n: 5 });
    qc.setQueryData(['other'], { n: 100 });

    applyOptimisticPatch(qc, { queryKey: ['list'], partialMatch: true, updateFn: inc, variables: {} });

    expect(qc.getQueryData(['list', { page: 1 }])).toEqual({ n: 2 });
    expect(qc.getQueryData(['list', { page: 2 }])).toEqual({ n: 6 });
    // unrelated key untouched
    expect(qc.getQueryData(['other'])).toEqual({ n: 100 });
  });

  it('restores the exact pre-patch snapshot on rollback', () => {
    const qc = new QueryClient();
    qc.setQueryData(['list', { page: 1 }], { n: 1 });
    qc.setQueryData(['list', { page: 2 }], { n: 5 });

    const ctx = applyOptimisticPatch(qc, { queryKey: ['list'], partialMatch: true, updateFn: inc, variables: {} });
    expect(qc.getQueryData(['list', { page: 1 }])).toEqual({ n: 2 });

    rollbackOptimisticPatch(qc, ctx);

    expect(qc.getQueryData(['list', { page: 1 }])).toEqual({ n: 1 });
    expect(qc.getQueryData(['list', { page: 2 }])).toEqual({ n: 5 });
  });

  it('exact-key mode patches and rolls back a single query', () => {
    const qc = new QueryClient();
    qc.setQueryData(['solo'], { n: 7 });

    const ctx = applyOptimisticPatch(qc, { queryKey: ['solo'], partialMatch: false, updateFn: inc, variables: {} });
    expect(qc.getQueryData(['solo'])).toEqual({ n: 8 });

    rollbackOptimisticPatch(qc, ctx);
    expect(qc.getQueryData(['solo'])).toEqual({ n: 7 });
  });
});
