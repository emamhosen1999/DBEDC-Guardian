import React from 'react';
import Icon from './Icon.jsx';

/**
 * Vertical timeline of dated events. Cyber's template has no timeline widget, so this is Cyber's own list pattern
 * (`.list-group-item` rows: .4375rem .875rem padding, 1px translucent separators, body text colour) with a dot and a
 * connecting line on the left, in the same tone tokens the badges use.
 *   events = [{ id, date, title, detail?, icon?, tone?: 'theme'|'success'|'warning'|'danger'|'info'|'secondary', badge? }]
 * Newest first is the caller's order; an event without a date reads "Since records began".
 */
export default function Timeline({ events, label, empty = 'Nothing recorded yet.', className = '', 'data-conf': conf }) {
    if (!events?.length) return <p className="cy-timeline__empty" role="status">{empty}</p>;
    return (
        <ol className={['cy-timeline', className].filter(Boolean).join(' ')} aria-label={label} data-conf={conf}>
            {events.map((ev) => (
                <li key={ev.id} className="cy-timeline__item" data-tone={ev.tone || 'secondary'}>
                    <span className="cy-timeline__dot" aria-hidden="true">{ev.icon && <Icon name={ev.icon} />}</span>
                    <div className="cy-timeline__body">
                        <div className="cy-timeline__head">
                            <strong className="cy-timeline__title">{ev.title}</strong>
                            {ev.badge}
                        </div>
                        <time className="cy-timeline__date" dateTime={ev.date || undefined}>{ev.date || 'Since records began'}</time>
                        {ev.detail && <div className="cy-timeline__detail">{ev.detail}</div>}
                    </div>
                </li>
            ))}
        </ol>
    );
}
