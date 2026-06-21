import { useState } from 'react';
import { Popover, TextField, Flex, Text } from '@radix-ui/themes';
import { CalendarDateTime, Time } from '@internationalized/date';
import { CalendarDays, Clock, X } from 'lucide-react';
import {
  strToDate, strToDateTime, strToTime,
  dateToStr, dateTimeToStr, timeToStr,
  strRangeToDateRange, strRangeToDateTimeRange,
} from './format.js';
import CalendarPanel from './CalendarPanel.jsx';
import TimePanel from './TimePanel.jsx';

const RANGE_MODES = new Set(['dateRange', 'datetimeRange']);
const TIME_MODES = new Set(['datetime', 'datetimeRange', 'time']);
const DATE_MODES = new Set(['date', 'dateRange', 'datetime', 'datetimeRange']);

// Merge a CalendarDate (date part) with a Time into a CalendarDateTime string.
function mergeDateTime(calDate, time, granularity) {
  if (!calDate) return '';
  const t = time ?? new Time(0, 0);
  const dt = new CalendarDateTime(calDate.year, calDate.month, calDate.day, t.hour, t.minute, t.second);
  return dateTimeToStr(dt, granularity);
}

function displayText(mode, value) {
  if (RANGE_MODES.has(mode)) {
    const { start, end } = value || {};
    if (!start && !end) return '';
    return `${start || '…'} → ${end || '…'}`;
  }
  return value || '';
}

export default function DateTimePicker({
  mode = 'date',
  value,
  onChange,
  min,
  max,
  disabledDates,
  hourCycle = 24,
  granularity = 'minute',
  presets = true,
  clearable = true,
  label,
  error,
  size = '2',
  placeholder = 'Select…',
  disabled = false,
  readOnly = false,
}) {
  const [open, setOpen] = useState(false);
  const isRange = RANGE_MODES.has(mode);
  const hasDate = DATE_MODES.has(mode);

  const minValue = strToDate(min);
  const maxValue = strToDate(max);
  const isDateUnavailable = typeof disabledDates === 'function'
    ? (date) => disabledDates(dateToStr(date))
    : Array.isArray(disabledDates)
      ? (date) => disabledDates.includes(dateToStr(date))
      : undefined;

  // ----- value -> react-aria objects -----
  const calValue = (() => {
    if (mode === 'time') return strToTime(value);
    if (mode === 'date') return strToDate(value);
    if (mode === 'datetime') {
      return strToDateTime(value); // CalendarDateTime works directly in Calendar
    }
    if (mode === 'dateRange') return strRangeToDateRange(value?.start, value?.end);
    if (mode === 'datetimeRange') return strRangeToDateTimeRange(value?.start, value?.end);
    return null;
  })();

  // ----- handlers -----
  const handleSingleDate = (calDate) => {
    if (mode === 'date') {
      onChange(dateToStr(calDate));
      setOpen(false);
    } else if (mode === 'datetime') {
      const existing = strToDateTime(value);
      const t = existing ? new Time(existing.hour, existing.minute, existing.second) : null;
      onChange(mergeDateTime(calDate, t, granularity));
    }
  };

  const handleRange = (range) => {
    // range.start / range.end are CalendarDate or CalendarDateTime
    if (mode === 'dateRange') {
      onChange({ start: dateToStr(range.start), end: dateToStr(range.end) });
      setOpen(false);
    } else {
      const sExisting = strToDateTime(value?.start);
      const eExisting = strToDateTime(value?.end);
      const sTime = sExisting ? new Time(sExisting.hour, sExisting.minute, sExisting.second) : null;
      const eTime = eExisting ? new Time(eExisting.hour, eExisting.minute, eExisting.second) : null;
      onChange({
        start: mergeDateTime(range.start, sTime, granularity),
        end: mergeDateTime(range.end, eTime, granularity),
      });
    }
  };

  const handlePreset = (rangeStrings) => {
    onChange(rangeStrings);
    setOpen(false);
  };

  const handleTimeOnly = (time) => onChange(timeToStr(time, granularity));

  const handleDateTimeTimeChange = (which, time) => {
    if (mode === 'datetime') {
      const d = strToDate(value); // date part from current value
      const baseDate = d || strToDateTime(value);
      onChange(mergeDateTime(baseDate, time, granularity));
    } else {
      // datetimeRange: which is 'start' | 'end'
      const cur = value?.[which];
      const d = strToDateTime(cur);
      onChange({ ...value, [which]: mergeDateTime(d, time, granularity) });
    }
  };

  const clear = (e) => {
    e.stopPropagation();
    onChange(isRange ? { start: '', end: '' } : '');
  };

  const text = displayText(mode, value);
  const showClear = clearable && !disabled && !readOnly &&
    (isRange ? (value?.start || value?.end) : value);

  return (
    <div>
      {label && <Text size="2" weight="medium" mb="1" as="div">{label}</Text>}
      <Popover.Root open={open} onOpenChange={setOpen}>
        <Popover.Trigger disabled={disabled || readOnly}>
          <TextField.Root
            size={size}
            value={text}
            placeholder={placeholder}
            readOnly
            disabled={disabled}
            style={{ cursor: disabled ? 'default' : 'pointer' }}
            color={error ? 'red' : undefined}
          >
            <TextField.Slot>{hasDate ? <CalendarDays size={16} /> : <Clock size={16} />}</TextField.Slot>
            {showClear && (
              <TextField.Slot side="right" onClick={clear} style={{ cursor: 'pointer' }}>
                <X size={14} />
              </TextField.Slot>
            )}
          </TextField.Root>
        </Popover.Trigger>

        <Popover.Content>
          {hasDate && (
            <CalendarPanel
              range={isRange}
              value={calValue}
              onChange={isRange ? handleRange : handleSingleDate}
              minValue={minValue}
              maxValue={maxValue}
              isDateUnavailable={isDateUnavailable}
              showPresets={isRange && presets}
              onPreset={handlePreset}
            />
          )}

          {mode === 'datetime' && (
            <Flex gap="2" align="center" px="3" pb="3">
              <Text size="2">Time</Text>
              <TimePanel
                value={(() => { const dt = strToDateTime(value); return dt ? new Time(dt.hour, dt.minute, dt.second) : null; })()}
                onChange={(t) => handleDateTimeTimeChange('start', t)}
                hourCycle={hourCycle}
                granularity={granularity}
              />
            </Flex>
          )}

          {mode === 'datetimeRange' && (
            <Flex gap="3" align="center" px="3" pb="3">
              <Flex gap="2" align="center">
                <Text size="2">Start</Text>
                <TimePanel
                  value={(() => { const dt = strToDateTime(value?.start); return dt ? new Time(dt.hour, dt.minute, dt.second) : null; })()}
                  onChange={(t) => handleDateTimeTimeChange('start', t)}
                  hourCycle={hourCycle} granularity={granularity} aria-label="Start time"
                />
              </Flex>
              <Flex gap="2" align="center">
                <Text size="2">End</Text>
                <TimePanel
                  value={(() => { const dt = strToDateTime(value?.end); return dt ? new Time(dt.hour, dt.minute, dt.second) : null; })()}
                  onChange={(t) => handleDateTimeTimeChange('end', t)}
                  hourCycle={hourCycle} granularity={granularity} aria-label="End time"
                />
              </Flex>
            </Flex>
          )}

          {mode === 'time' && (
            <div style={{ padding: 'var(--space-3)' }}>
              <TimePanel
                value={strToTime(value)}
                onChange={handleTimeOnly}
                hourCycle={hourCycle}
                granularity={granularity}
              />
            </div>
          )}
        </Popover.Content>
      </Popover.Root>
      {error && <Text size="1" color="red" as="div" mt="1">{error}</Text>}
    </div>
  );
}
