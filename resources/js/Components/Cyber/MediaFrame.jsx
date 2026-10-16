import React from 'react';

/**
 * Shows one document inline: an image, or a PDF in the browser's own viewer. Fills its container with no chrome of its own
 * (the surrounding Cyber dialog or card draws the lines).
 */
export default function MediaFrame({ src, mime, title, className = '' }) {
    if (!src) return <p className="cy-row__sub">No document to show.</p>;
    const cls = ['cy-media', className].filter(Boolean).join(' ');
    return mime === 'application/pdf'
        ? <iframe className={cls} title={title} src={src} />
        : <img className={cls} src={src} alt={title} />;
}
