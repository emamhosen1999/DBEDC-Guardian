import dayjs from 'dayjs';

export const RANGE_PRESETS = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'this_week', label: 'This Week' },
  { value: 'last_week', label: 'Last Week' },
  { value: 'this_month', label: 'This Month' },
  { value: 'last_month', label: 'Last Month' },
  { value: 'custom', label: 'Custom' },
];

const fmt = (d) => d.format('YYYY-MM-DD');

export const resolvePreset = (value, today = dayjs()) => {
  const t = dayjs(today);
  switch (value) {
    case 'today':
      return { from: fmt(t), to: fmt(t) };
    case 'yesterday': {
      const y = t.subtract(1, 'day');
      return { from: fmt(y), to: fmt(y) };
    }
    case 'this_week':
      return { from: fmt(t.startOf('week')), to: fmt(t.endOf('week')) };
    case 'last_week': {
      const lw = t.subtract(1, 'week');
      return { from: fmt(lw.startOf('week')), to: fmt(lw.endOf('week')) };
    }
    case 'this_month':
      return { from: fmt(t.startOf('month')), to: fmt(t.endOf('month')) };
    case 'last_month': {
      const lm = t.subtract(1, 'month');
      return { from: fmt(lm.startOf('month')), to: fmt(lm.endOf('month')) };
    }
    case 'custom':
    default:
      return null;
  }
};

export const isRangeMode = (from, to) => dayjs(to).isAfter(dayjs(from), 'day');
