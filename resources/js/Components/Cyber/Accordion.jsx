import React, { useId, useState } from 'react';
import Icon from './Icon.jsx';

/**
 * Cyber .accordion: one item open at a time (clicking the open one closes it). items = [{ key, title, content }].
 * Each header is a button with aria-expanded / aria-controls; closed panels are `hidden`.
 */
export default function Accordion({ items, defaultOpen = 0, className = '' }) {
    const base = useId();
    const [open, setOpen] = useState(defaultOpen);
    return (
        <ol className={`cy-accordion ${className}`.trim()}>
            {items.map((item, index) => {
                const isOpen = open === index;
                const panel = `${base}-${item.key}`;
                return (
                    <li key={item.key} className={`cy-accordion__item${isOpen ? ' is-open' : ''}`}>
                        <button type="button" className="cy-accordion__button" aria-expanded={isOpen} aria-controls={panel} onClick={() => setOpen(isOpen ? -1 : index)}>
                            <span className="cy-accordion__num">{String(index + 1).padStart(2, '0')}</span>
                            <span className="cy-accordion__title">{item.title}</span>
                            <Icon name="chevron-down" className="cy-accordion__chevron" />
                        </button>
                        <p className="cy-accordion__panel" id={panel} hidden={!isOpen}>{item.content}</p>
                    </li>
                );
            })}
        </ol>
    );
}
