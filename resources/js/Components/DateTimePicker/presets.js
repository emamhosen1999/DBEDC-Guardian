import { dateToStr } from './format.js';

// `today` is an @internationalized/date CalendarDate.
// CalendarDate supports .add()/.subtract() (durations) and .set().
export function getDatePresets(today) {
  const startOfMonth = today.set({ day: 1 });
  const endOfMonth = startOfMonth.add({ months: 1 }).subtract({ days: 1 });
  const startOfLastMonth = startOfMonth.subtract({ months: 1 });
  const endOfLastMonth = startOfMonth.subtract({ days: 1 });
  const startOfYear = today.set({ month: 1, day: 1 });
  const endOfYear = today.set({ month: 12, day: 31 });
  const yesterday = today.subtract({ days: 1 });

  const range = (start, end) => ({ start: dateToStr(start), end: dateToStr(end) });

  return [
    { key: 'today', label: 'Today', range: range(today, today) },
    { key: 'yesterday', label: 'Yesterday', range: range(yesterday, yesterday) },
    { key: 'last7', label: 'Last 7 days', range: range(today.subtract({ days: 6 }), today) },
    { key: 'last30', label: 'Last 30 days', range: range(today.subtract({ days: 29 }), today) },
    { key: 'thisMonth', label: 'This month', range: range(startOfMonth, endOfMonth) },
    { key: 'lastMonth', label: 'Last month', range: range(startOfLastMonth, endOfLastMonth) },
    { key: 'thisYear', label: 'This year', range: range(startOfYear, endOfYear) },
  ];
}
