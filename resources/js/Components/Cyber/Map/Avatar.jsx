import React, { useEffect, useState } from 'react';

import { initialsOf } from './util.js';

/** Profile photo, or the initials when there is none or it cannot be loaded (a dead storage URL must not show a broken image). */
export function Avatar({ name, photo }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => { setFailed(false); }, [photo]);
    return (
        <span className="cy-avatar" aria-hidden="true">
            {photo && !failed ? <img src={photo} alt="" loading="lazy" onError={() => setFailed(true)} /> : initialsOf(name)}
        </span>
    );
}

/** Punch photo thumbnail that opens the lightbox; renders nothing when the image cannot be loaded. */
export function PunchThumb({ url, label, onOpen, className = 'cy-thumb' }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => { setFailed(false); }, [url]);
    if (!url) return null;
    if (failed) return <span className="cy-pop__punch-place" role="note">Photo unavailable</span>;
    return (
        <button type="button" className={className} onClick={onOpen} aria-label={label}>
            <img src={url} alt="" loading="lazy" onError={() => setFailed(true)} />
        </button>
    );
}
