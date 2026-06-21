import {
  Calendar, RangeCalendar, CalendarGrid, CalendarCell,
  CalendarGridHeader, CalendarHeaderCell, CalendarGridBody,
  Heading, Button,
} from 'react-aria-components';
import { today, getLocalTimeZone } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { getDatePresets } from './presets.js';
import './datetimepicker.css';

function CalendarBody({ range }) {
  return (
    <>
      <header>
        <Button slot="previous" className="dtp-nav-btn" aria-label="Previous month">
          <ChevronLeft size={16} />
        </Button>
        <Heading />
        <Button slot="next" className="dtp-nav-btn" aria-label="Next month">
          <ChevronRight size={16} />
        </Button>
      </header>
      <CalendarGrid className="dtp-grid" data-range={range || undefined}>
        <CalendarGridHeader>
          {(day) => <CalendarHeaderCell>{day}</CalendarHeaderCell>}
        </CalendarGridHeader>
        <CalendarGridBody>
          {(date) => <CalendarCell date={date} className="dtp-cell" />}
        </CalendarGridBody>
      </CalendarGrid>
    </>
  );
}

export default function CalendarPanel({
  range = false,
  value,
  onChange,
  minValue,
  maxValue,
  isDateUnavailable,
  showPresets = false,
  onPreset,
}) {
  const presets = range && showPresets
    ? getDatePresets(today(getLocalTimeZone()))
    : null;

  return (
    <div className="dtp-panel">
      {range ? (
        <RangeCalendar
          className="dtp-calendar"
          aria-label="Date range"
          value={value}
          onChange={onChange}
          minValue={minValue}
          maxValue={maxValue}
          isDateUnavailable={isDateUnavailable}
        >
          <CalendarBody range />
        </RangeCalendar>
      ) : (
        <Calendar
          className="dtp-calendar"
          aria-label="Date"
          value={value}
          onChange={onChange}
          minValue={minValue}
          maxValue={maxValue}
          isDateUnavailable={isDateUnavailable}
        >
          <CalendarBody />
        </Calendar>
      )}

      {presets && (
        <div className="dtp-presets">
          {presets.map((p) => (
            <button
              key={p.key}
              type="button"
              className="dtp-preset-btn"
              onClick={() => onPreset(p.range)}
            >
              {p.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
