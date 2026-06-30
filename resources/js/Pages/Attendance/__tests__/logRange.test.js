import { describe, it, expect } from 'vitest';
import dayjs from 'dayjs';
import { RANGE_PRESETS, resolvePreset, isRangeMode } from '../logRange';

describe('logRange helpers', () => {
  const today = dayjs('2026-06-15'); // a Monday

  it('exposes presets including today and custom', () => {
    const values = RANGE_PRESETS.map((p) => p.value);
    expect(values).toContain('today');
    expect(values).toContain('custom');
  });

  it('resolves "today" to a single day', () => {
    expect(resolvePreset('today', today)).toEqual({ from: '2026-06-15', to: '2026-06-15' });
  });

  it('resolves "this_month" to month bounds', () => {
    expect(resolvePreset('this_month', today)).toEqual({ from: '2026-06-01', to: '2026-06-30' });
  });

  it('returns null for custom', () => {
    expect(resolvePreset('custom', today)).toBeNull();
  });

  it('isRangeMode is false for same day, true for a span', () => {
    expect(isRangeMode('2026-06-15', '2026-06-15')).toBe(false);
    expect(isRangeMode('2026-06-15', '2026-06-20')).toBe(true);
  });
});
