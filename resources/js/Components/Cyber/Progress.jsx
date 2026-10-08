import React from 'react';

/** Cyber .progress (3px track, theme bar). Always carries its accessible name through `label`. */
export default function Progress({ value = 0, max = 100, color = 'theme', label, className = '' }) {
    const pct = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0;
    return (
        <div className={`cy-progress ${className}`.trim()} role="progressbar" aria-label={label} aria-valuenow={value} aria-valuemin={0} aria-valuemax={max}>
            <div className={`cy-progress__bar cy-progress__bar--${color}`} style={{ width: `${pct}%` }} />
        </div>
    );
}
