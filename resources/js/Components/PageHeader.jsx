import React from 'react';
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
 * chips    status chips: value on top (coloured by tone), label below.
 *          Pass only real values; chips whose value is null/undefined/'' are
 *          dropped. tone: success | warning | danger | theme | default.
 *          Hidden below 768px, as on Cyber.
 * actions  a node, or (earlier API) an array of { label, icon, onClick|onPress,
 *          variant, color, disabled } — `actionButtons` is an alias
 * icon     optional glyph before the title (earlier API)
 *
 * Styles: resources/css/design/cyber/shell.css (.dl-page-head, .dl-chip*).
 */
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
                <ul className="dl-chips" aria-label="Status">
                    {visibleChips.map((chip) => (
                        <li key={chip.label} className="dl-chip" data-tone={chip.tone || 'default'} title={chip.title}>
                            <span className="dl-chip__value">{chip.value}</span>
                            <span className="dl-chip__label">{chip.label}</span>
                        </li>
                    ))}
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
