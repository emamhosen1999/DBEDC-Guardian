import React, { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

import Icon from '../Icon.jsx';

/**
 * Modal on top of everything (above a maximized card): backdrop, Escape and backdrop-click close, focus moves in
 * and returns to the trigger. Rendered into document.body so the maximized card's overflow never clips it.
 */
export default function Dialog({ title, onClose, children, className = 'cy-modal', labelledBy }) {
    const panel = useRef(null);
    const opener = useRef(typeof document !== 'undefined' ? document.activeElement : null);
    useEffect(() => {
        panel.current?.focus();
        const onKey = (e) => {
            if (e.key === 'Escape') { e.stopPropagation(); onClose(); }
            if (e.key === 'Tab' && panel.current) {
                const items = panel.current.querySelectorAll('a[href], button:not(:disabled), input, [tabindex]:not([tabindex="-1"])');
                if (!items.length) return;
                const first = items[0]; const last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        };
        document.addEventListener('keydown', onKey, true);
        const target = opener.current;
        return () => { document.removeEventListener('keydown', onKey, true); target?.focus?.(); };
    }, [onClose]);

    return createPortal(
        <div className="cy-modal-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div ref={panel} className={className} role="dialog" aria-modal="true" aria-label={labelledBy ? undefined : title} aria-labelledby={labelledBy} tabIndex={-1}>
                <div className="cy-modal__head">
                    <h2 id={labelledBy}>{title}</h2>
                    <button type="button" className="cy-pop__close" onClick={onClose} aria-label="Close"><Icon name="x-lg" /></button>
                </div>
                {children}
            </div>
        </div>,
        document.body,
    );
}
