import React, { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@radix-ui/themes';

/**
 * Cyber page header (.app-content-header > .page-header + status chips), the
 * first row of every shell page. Edge to edge, 0.875rem padding, one border
 * line underneath; the content below starts flush against it.
 *
 *   <PageHeader title="Command" muted="Center" subtitle="…"
 *     chips={[{ value: 112, label: 'Present', tone: 'success' }]}
 *     actions={<Button …/>} />
 *
 * title    page title. Inner pages: mixed case + `subtitle` (Cyber "Table
 *          Elements <small>…</small>"). Dashboards: pass `upper` and `muted`
 *          for the two-tone uppercase header (Cyber "SYSTEM ANALYTICS").
 * muted    light part (Cyber "ANALYTICS", opacity .5, weight 300)
 * subtitle short description after the title (Cyber .page-header small);
 *          `description` is accepted as an alias (earlier API)
 * chips    status chips in PRIORITY order: value on top (coloured by tone), label below.
 *          Pass only real values; chips whose value is null/undefined/'' are
 *          dropped. tone: success | warning | danger | theme | default.
 *          From 1200px the row shows the first MAX_CHIPS (5) and folds the rest
 *          into a "+N" menu; below 1200px every chip shows and the row wraps
 *          inside the header; at phone width it is one scrollable row under
 *          the title. The header always contains its chips (no overlap).
 * actions  a node, or (earlier API) an array of { label, icon, onClick|onPress,
 *          variant, color, disabled } — `actionButtons` is an alias
 * icon     optional glyph before the title (earlier API)
 *
 * Styles: resources/css/design/cyber/shell.css (.dl-page-head, .dl-chip*).
 */
/** Chips shown in the row from 1200px up; any further chips go into the "+N" menu. */
export const MAX_CHIPS = 5;

/** "+N" chip: the chips that do not fit the row, in a Cyber dropdown. */
function MoreChips({ chips }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const menuId = useId();

    useEffect(() => {
        if (!open) return undefined;
        const onPointer = (event) => { if (!ref.current?.contains(event.target)) setOpen(false); };
        const onKey = (event) => { if (event.key === 'Escape') { setOpen(false); ref.current?.querySelector('button')?.focus(); } };
        document.addEventListener('pointerdown', onPointer);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('pointerdown', onPointer); document.removeEventListener('keydown', onKey); };
    }, [open]);

    return (
        <li className="dl-chip dl-chip--more" ref={ref} data-tone="default">
            <button type="button" className="dl-chip__more-btn" aria-haspopup="true" aria-expanded={open} aria-controls={open ? menuId : undefined} onClick={() => setOpen((o) => !o)}>
                <span className="dl-chip__value">+{chips.length}</span>
                <span className="dl-chip__label">More</span>
            </button>
            {open && (
                <ul className="dl-chip-menu" id={menuId}>
                    {chips.map((chip) => (
                        <li key={chip.label} data-tone={chip.tone || 'default'}>
                            <span className="dl-chip-menu__label">{chip.label}</span>
                            <span className="dl-chip-menu__value">{chip.value}</span>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function PageHeader({
    title,
    muted,
    subtitle,
    description,
    chips = [],
    actions,
    actionButtons,
    icon,
    upper = false,
    id,
    children,
    variant, // eslint-disable-line no-unused-vars -- earlier API, absorbed
    compact, // eslint-disable-line no-unused-vars -- earlier API, absorbed
}) {
    const sub = subtitle ?? description;
    const visibleChips = chips.filter((chip) => chip && chip.value !== null && chip.value !== undefined && chip.value !== '');
    const buttonList = actionButtons ?? (Array.isArray(actions) ? actions : null);

    return (
        <div className={upper ? 'dl-page-head dl-page-head--upper' : 'dl-page-head'}>
            <div className="dl-page-head__main">
                <h1 className="dl-page-head__title" id={id}>
                    {icon && <span className="dl-page-head__icon" aria-hidden="true">{icon}</span>}
                    {title}
                    {muted && <> <span className="dl-page-head__muted">{muted}</span></>}
                    {sub && <small className="dl-page-head__sub">{sub}</small>}
                </h1>
                {children}
            </div>
            {visibleChips.length > 0 && (
                <ul className="dl-chips" aria-label="Status" tabIndex={0}>
                    {visibleChips.map((chip, index) => (
                        <li key={chip.label} className={index >= MAX_CHIPS ? 'dl-chip dl-chip--extra' : 'dl-chip'} data-tone={chip.tone || 'default'} title={chip.title ?? `${chip.label}: ${chip.value}`}>
                            <span className="dl-chip__value">{chip.value}</span>
                            <span className="dl-chip__label">{chip.label}</span>
                        </li>
                    ))}
                    {visibleChips.length > MAX_CHIPS && <MoreChips chips={visibleChips.slice(MAX_CHIPS)} />}
                </ul>
            )}
            {buttonList && buttonList.length > 0 && (
                <div className="dl-page-head__actions">
                    {buttonList.map((btn, i) => (
                        <Button
                            key={btn.label ?? i}
                            size="2"
                            variant={btn.variant || 'solid'}
                            color={btn.color}
                            disabled={btn.disabled}
                            onClick={btn.onPress || btn.onClick}
                        >
                            {btn.icon}
                            {btn.label}
                        </Button>
                    ))}
                </div>
            )}
            {actions && !Array.isArray(actions) && <div className="dl-page-head__actions">{actions}</div>}
        </div>
    );
}
