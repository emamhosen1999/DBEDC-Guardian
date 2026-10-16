import React from 'react';

/**
 * A picture of a document or photo inside a list row: a plain image box (Cyber's gallery images carry no border, padding or
 * radius), fixed to a document-like ratio so a column of them lines up. A PDF without a page image shows its label instead.
 */
export default function Thumbnail({ src, alt, label = 'PDF', size = 'md', className = '', ...props }) {
    const cls = ['cy-docthumb', `cy-docthumb--${size}`, className].filter(Boolean).join(' ');
    if (!src) return <span className={`${cls} cy-docthumb--empty`} role="img" aria-label={alt}>{label}</span>;
    return <img className={cls} src={src} alt={alt} loading="lazy" {...props} />;
}
