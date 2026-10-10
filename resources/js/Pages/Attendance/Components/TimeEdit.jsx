import React, { useState } from 'react';
import { Icon } from '@/Components/Cyber';

/** Inline punch-time editor: a time field with save and cancel (Enter saves, Escape cancels). `value` is "HH:mm". */
export default function TimeEdit({ value, onSave, onCancel, label = 'Time' }) {
    const [time, setTime] = useState(value || '');
    const save = () => { if (time.trim()) onSave(time); };
    return (
        <span className="cy-timeedit">
            <input
                className="cy-input" type="time" value={time} aria-label={`${label} time`} autoFocus
                onChange={(e) => setTime(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } else if (e.key === 'Escape') { e.preventDefault(); onCancel(); } }}
            />
            <button type="button" className="cy-iconbtn cy-iconbtn--good" onClick={save} aria-label={`Save ${label.toLowerCase()} time`} title="Save"><Icon name="check-lg" /></button>
            <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={onCancel} aria-label="Cancel" title="Cancel"><Icon name="x-lg" /></button>
        </span>
    );
}
