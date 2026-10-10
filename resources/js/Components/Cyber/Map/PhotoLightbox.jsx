import React from 'react';

import Dialog from './Dialog.jsx';

/** Punch photo, full size, with who/when as its caption. */
export default function PhotoLightbox({ photo, onClose }) {
    if (!photo) return null;
    return (
        <Dialog title={photo.title ?? 'Punch photo'} onClose={onClose} className="cy-lightbox">
            <img src={photo.url} alt={photo.caption ?? photo.title ?? 'Punch photo'} />
            {photo.caption && <div className="cy-lightbox__cap">{photo.caption}</div>}
        </Dialog>
    );
}
