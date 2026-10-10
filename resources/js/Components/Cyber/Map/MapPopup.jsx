import React from 'react';
import { Link } from '@inertiajs/react';

import Badge from '../Badge.jsx';
import Icon from '../Icon.jsx';
import { Avatar, PunchThumb } from './Avatar.jsx';
import { BADGE, clockOf, placeOf } from './util.js';

function Punch({ label, punch, name, onPhoto }) {
    if (!punch) return null;
    const when = clockOf(punch.time);
    const photoTitle = `${name} - ${label.toLowerCase()}`;
    return (
        <div className="cy-pop__punch">
            <div className="cy-pop__punch-main">
                <div className="cy-pop__punch-label">{label}</div>
                <div className="cy-pop__punch-time">{when ?? 'Not recorded'}</div>
                {placeOf(punch) && <div className="cy-pop__punch-place">{placeOf(punch)}</div>}
            </div>
            <PunchThumb url={punch.photo} label={`Open ${label.toLowerCase()} photo`} onOpen={() => onPhoto({ url: punch.photo, title: photoTitle, caption: `${photoTitle}${when ? ` at ${when}` : ''}` })} />
        </div>
    );
}

/**
 * Cyber popup for a map feature: a header strip with the title, the layer's fields as rows and a drill-down footer.
 * An attendance marker carries the employee: photo, name, designation, active/done status, punch-in and punch-out
 * time and place, the punch photos (opened in the lightbox) and a link to the timesheet - the same content the
 * Daily Timesheet map shows.
 */
export default function MapPopup({ feature, layer, onClose, onPhoto, onDetails, drill }) {
    const person = feature.person;
    const done = person?.status === 'completed';
    return (
        <div className="cy-pop" role="dialog" aria-label={feature.title}>
            <div className="cy-pop__head">
                <h4 className="cy-pop__title" title={feature.title}>{person ? layer.label : feature.title}</h4>
                {person && <Badge color={done ? 'info' : 'success'}>{done ? 'Done' : 'Active'}</Badge>}
                <button type="button" className="cy-pop__close" onClick={onClose} aria-label="Close popup"><Icon name="x-lg" /></button>
            </div>
            {person ? (
                <>
                    <div className="cy-pop__person">
                        <Avatar name={person.name} photo={person.photo} />
                        <div className="cy-pop__who">
                            <div className="cy-pop__name" title={person.name}>{person.name}</div>
                            <div className="cy-pop__sub">{[person.designation, person.department].filter(Boolean).join(' · ')}</div>
                        </div>
                    </div>
                    <Punch label="Punch in" punch={person.punch_in} name={person.name} onPhoto={onPhoto} />
                    <Punch label="Punch out" punch={person.punch_out} name={person.name} onPhoto={onPhoto} />
                </>
            ) : (
                <dl className="cy-pop__rows">
                    {feature.fields.map((f) => (<React.Fragment key={f.label}><dt>{f.label}</dt><dd>{f.value}</dd></React.Fragment>))}
                </dl>
            )}
            <div className="cy-pop__foot">
                {person && onDetails && <button type="button" onClick={() => onDetails(feature)}>Details</button>}
                {person?.timesheet && <Link href={person.timesheet}>Open timesheet</Link>}
                {!person && (feature.href ?? drill) && <Link href={feature.href ?? drill}>Open {layer.label.toLowerCase()}</Link>}
            </div>
        </div>
    );
}

export { BADGE };
