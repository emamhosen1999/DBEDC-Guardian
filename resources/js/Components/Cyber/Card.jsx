import React, { useEffect, useId, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon from './Icon.jsx';

/* Minimized state is remembered per viewer and per card (localStorage may be blocked: always guarded). */
const storageKeyFor = (viewer, id) => `cy-card:${viewer ?? 'anon'}:${id}`;
const readMinimized = (key) => { try { return window.localStorage.getItem(key) === '1'; } catch { return false; } };
const writeMinimized = (key, value) => { try { value ? window.localStorage.setItem(key, '1') : window.localStorage.removeItem(key); } catch { /* storage unavailable: state stays in memory */ } };

/**
 * Cyber .card: header strip (uppercase title, HUD stripe, .card-header-btn tools), body, optional
 * footer. Structure and measurements are the dashboard's .dl-card (shell.css), so every Cyber
 * panel in the app stays identical; this component adds Bootstrap Icons tools and a11y wiring.
 *
 *   <Card title="Team today" sub="Whole organization" actions={<Button …/>} flush>…</Card>
 *
 * flush   body without padding (tables, tile grids, lists - edge to edge as on Cyber)
 * tools   minimize + maximize buttons (default on). There is deliberately NO close tool.
 * id      stable card id: the minimized state is remembered per viewer under it. Maximized is never stored.
 */
export default function Card({ title, sub, actions, children, footer, flush = false, tools = true, id, className = '', ...props }) {
    const bodyId = useId();
    const viewer = usePage().props?.auth?.user?.employee_id;
    const storageKey = id ? storageKeyFor(viewer, id) : null;
    const [collapsed, setCollapsed] = useState(() => (storageKey ? readMinimized(storageKey) : false));
    const [expanded, setExpanded] = useState(false);
    const cardRef = useRef(null);
    const expandRef = useRef(null);
    const wasExpanded = useRef(false);

    const toggleCollapsed = () => setCollapsed((current) => {
        if (storageKey) writeMinimized(storageKey, !current);
        return !current;
    });

    useEffect(() => {
        if (!expanded) return undefined;
        const onKeyDown = (event) => { if (event.key === 'Escape') setExpanded(false); };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [expanded]);

    // Focus moves into the maximized card and returns to its toggle when restored.
    useEffect(() => {
        if (expanded) { cardRef.current?.focus(); wasExpanded.current = true; }
        else if (wasExpanded.current) { expandRef.current?.focus(); wasExpanded.current = false; }
    }, [expanded]);

    const label = typeof title === 'string' ? title : 'panel';
    const classes = ['dl-card', collapsed ? 'dl-card--collapsed' : '', expanded ? 'dl-card--expanded' : '', className].filter(Boolean).join(' ');

    return (
        <section ref={cardRef} tabIndex={expanded ? -1 : undefined} className={classes} role={expanded ? 'dialog' : undefined} aria-modal={expanded || undefined} aria-label={expanded ? label : undefined} {...props}>
            {(title || actions) && (
                <header className="dl-card__header">
                    {title && <h3 className="dl-card__title" title={typeof title === 'string' ? title : undefined}>{title}</h3>}
                    <span className="dl-hud-line" aria-hidden="true" />
                    {actions && <div className="dl-card__actions">{actions}</div>}
                    {tools && (
                        <div className="dl-card__tools">
                            <button type="button" className="dl-card__tool" onClick={toggleCollapsed} aria-expanded={!collapsed} aria-controls={bodyId} aria-label={collapsed ? `Show ${label}` : `Collapse ${label}`}>
                                <Icon name="dash-lg" />
                            </button>
                            <button ref={expandRef} type="button" className="dl-card__tool" onClick={() => setExpanded((e) => !e)} aria-pressed={expanded} aria-label={expanded ? `Exit full screen: ${label}` : `Full screen: ${label}`}>
                                <Icon name={expanded ? 'fullscreen-exit' : 'fullscreen'} />
                            </button>
                        </div>
                    )}
                </header>
            )}
            <div className={`dl-card__body${flush ? ' dl-card__body--flush' : ''}`} id={bodyId}>
                {sub && <span className="dl-card__sub dl-card__sub--padded">{sub}</span>}
                {children}
            </div>
            {footer && <footer className="dl-card__foot">{footer}</footer>}
        </section>
    );
}
