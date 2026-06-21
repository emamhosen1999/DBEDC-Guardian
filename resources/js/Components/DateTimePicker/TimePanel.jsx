import { TimeField, DateInput, DateSegment } from 'react-aria-components';
import './datetimepicker.css';

export default function TimePanel({
  value,
  onChange,
  hourCycle = 24,
  granularity = 'minute',
  'aria-label': ariaLabel = 'Time',
}) {
  return (
    <TimeField
      value={value}
      onChange={onChange}
      hourCycle={hourCycle}
      granularity={granularity}
      aria-label={ariaLabel}
    >
      <DateInput className="dtp-timefield">
        {(segment) => <DateSegment segment={segment} className="dtp-segment" />}
      </DateInput>
    </TimeField>
  );
}
