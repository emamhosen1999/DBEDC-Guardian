import { describe, it, expect } from 'vitest';
import { resolveCoverageCellDisplay } from '../coverageCellDisplay';

describe('resolveCoverageCellDisplay', () => {
  it('understaffed is red', () => {
    const out = resolveCoverageCellDisplay({ required: 3, assigned: 1, status: 'understaffed' });
    expect(out.color).toBe('var(--red-9)');
    expect(out.label).toBe('1/3');
  });
  it('met is green', () => {
    expect(resolveCoverageCellDisplay({ required: 2, assigned: 2, status: 'met' }).color).toBe('var(--green-9)');
  });
  it('overstaffed is amber', () => {
    expect(resolveCoverageCellDisplay({ required: 1, assigned: 3, status: 'overstaffed' }).color).toBe('var(--amber-9)');
  });
  it('untracked (null required) is gray with dash label', () => {
    const out = resolveCoverageCellDisplay({ required: null, assigned: 2, status: null });
    expect(out.color).toBe('var(--gray-6)');
    expect(out.label).toBe('2/–');
  });
  it('formats fractional assigned', () => {
    expect(resolveCoverageCellDisplay({ required: 3, assigned: 1.5, status: 'understaffed' }).label).toBe('1.5/3');
  });
});
