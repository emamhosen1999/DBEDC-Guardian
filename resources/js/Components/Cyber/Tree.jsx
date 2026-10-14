import React, { useCallback, useId, useMemo, useRef, useState } from 'react';
import Icon from './Icon.jsx';

/**
 * Cyber file tree (`.file-tree` / `.file-node` / `.file-link`, file_manager.html) as a WAI-ARIA tree:
 * role tree / treeitem / group, aria-level, aria-setsize, aria-posinset, aria-expanded, aria-selected and a roving tabindex.
 * Keys: Down / Up next / previous visible item; Right opens a closed branch, else moves to its first child; Left closes an
 * open branch, else moves to its parent; Home / End first / last visible item; Enter or Space selects; * opens every branch
 * of the level. Cyber draws the branch toggle with a Font Awesome "+"; here it is the Bootstrap Icons plus / dash (the app's
 * icon set) in the same box.
 *
 *   items = [{ id, label, icon?, meta?, content?, children? }]   content (node) replaces label + meta
 */
function flatten(items, expanded, level = 1, parent = null, out = []) {
    items.forEach((item, i) => {
        out.push({ item, level, parent, index: i, size: items.length });
        if (item.children?.length && expanded.has(item.id)) flatten(item.children, expanded, level + 1, item.id, out);
    });
    return out;
}

function collectBranches(items, out = []) {
    items.forEach((item) => { if (item.children?.length) { out.push(item.id); collectBranches(item.children, out); } });
    return out;
}

export default function Tree({ items, label, selectedId, onSelect, defaultExpanded = 'all', className = '', 'data-conf': conf }) {
    const treeId = useId();
    const [expanded, setExpanded] = useState(() => new Set(defaultExpanded === 'all' ? collectBranches(items) : defaultExpanded || []));
    const [focusId, setFocusId] = useState(null);
    const refs = useRef(new Map());
    const visible = useMemo(() => flatten(items, expanded), [items, expanded]);
    const active = focusId && visible.some((v) => v.item.id === focusId) ? focusId : (selectedId && visible.some((v) => v.item.id === selectedId) ? selectedId : visible[0]?.item.id);

    const focusItem = useCallback((id) => { setFocusId(id); refs.current.get(id)?.focus(); }, []);
    const toggle = useCallback((id, open) => setExpanded((cur) => {
        const next = new Set(cur);
        (open ?? !next.has(id)) ? next.add(id) : next.delete(id);
        return next;
    }), []);

    const onKeyDown = (e, entry) => {
        if (e.target !== e.currentTarget) return;
        const i = visible.findIndex((v) => v.item.id === entry.item.id);
        const hasKids = !!entry.item.children?.length;
        const isOpen = expanded.has(entry.item.id);
        let handled = true;
        switch (e.key) {
            case 'ArrowDown': if (visible[i + 1]) focusItem(visible[i + 1].item.id); break;
            case 'ArrowUp': if (visible[i - 1]) focusItem(visible[i - 1].item.id); break;
            case 'ArrowRight': if (hasKids && !isOpen) toggle(entry.item.id, true); else if (hasKids && visible[i + 1]) focusItem(visible[i + 1].item.id); break;
            case 'ArrowLeft': if (hasKids && isOpen) toggle(entry.item.id, false); else if (entry.parent) focusItem(entry.parent); break;
            case 'Home': focusItem(visible[0].item.id); break;
            case 'End': focusItem(visible[visible.length - 1].item.id); break;
            case 'Enter': case ' ': onSelect?.(entry.item); break;
            case '*': setExpanded((cur) => { const next = new Set(cur); items.forEach((it) => { if (it.children?.length) next.add(it.id); }); return next; }); break;
            default: handled = false;
        }
        if (handled) { e.preventDefault(); e.stopPropagation(); }
    };

    const renderLevel = (nodes, level) => nodes.map((item, index) => {
        const hasKids = !!item.children?.length;
        const isOpen = hasKids && expanded.has(item.id);
        const entry = { item, parent: visible.find((v) => v.item.children?.some((c) => c.id === item.id))?.item.id ?? null };
        const labelId = `${treeId}-${item.id}`;
        return (
            <div
                key={item.id}
                ref={(el) => { if (el) refs.current.set(item.id, el); else refs.current.delete(item.id); }}
                className={['file-node', hasKids ? 'has-sub' : '', isOpen ? 'expand' : '', selectedId === item.id ? 'selected' : ''].filter(Boolean).join(' ')}
                role="treeitem"
                aria-level={level}
                aria-setsize={nodes.length}
                aria-posinset={index + 1}
                aria-expanded={hasKids ? isOpen : undefined}
                aria-selected={selectedId === item.id}
                aria-labelledby={labelId}
                tabIndex={active === item.id ? 0 : -1}
                onKeyDown={(e) => onKeyDown(e, entry)}
                onFocus={(e) => { if (e.target === e.currentTarget) setFocusId(item.id); }}
            >
                <div
                    className="file-link"
                    onClick={(e) => { e.stopPropagation(); setFocusId(item.id); onSelect?.(item); }}
                >
                    <span className="file-arrow" aria-hidden="true">
                        {hasKids && (
                            <span className="file-arrow__btn" onClick={(e) => { e.stopPropagation(); toggle(item.id); }}>
                                <Icon name={isOpen ? 'dash-lg' : 'plus-lg'} />
                            </span>
                        )}
                    </span>
                    <span className="file-info" id={labelId}>
                        {item.icon && <span className="file-icon"><Icon name={item.icon} /></span>}
                        {item.content ?? <span className="file-text">{item.label}{item.meta && <small className="file-meta"> {item.meta}</small>}</span>}
                    </span>
                </div>
                {isOpen && <div className="file-tree" role="group">{renderLevel(item.children, level + 1)}</div>}
            </div>
        );
    });

    return (
        <div className={['file-tree', className].filter(Boolean).join(' ')} role="tree" aria-label={label} data-conf={conf}>
            {renderLevel(items, 1)}
        </div>
    );
}
