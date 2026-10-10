import React, { useId, useRef } from 'react';

/**
 * Cyber .nav-tabs.nav-tabs-v2 as WAI-ARIA tabs (arrow keys, Home/End). The caller renders the panel and gives it
 * `id={panelId(tabsId, key)}` / `aria-labelledby={tabId(tabsId, key)}`; `idPrefix` makes those ids stable.
 *   tabs = [{ key, label, count, tone }]   (tone colours the count: success | danger | theme)
 * className adds a modifier to the tab list, e.g. `cy-tabs--scroll` (one scrollable row on a phone).
 */
export const tabId = (prefix, key) => `${prefix}-tab-${key}`;
export const panelId = (prefix, key) => `${prefix}-panel-${key}`;

export default function Tabs({ tabs, value, onChange, idPrefix, label, className = '' }) {
    const auto = useId();
    const prefix = idPrefix ?? auto;
    const refs = useRef({});
    const move = (index) => {
        const next = tabs[(index + tabs.length) % tabs.length];
        onChange(next.key);
        refs.current[next.key]?.focus();
    };
    const onKeyDown = (event, index) => {
        if (event.key === 'ArrowRight') { event.preventDefault(); move(index + 1); }
        else if (event.key === 'ArrowLeft') { event.preventDefault(); move(index - 1); }
        else if (event.key === 'Home') { event.preventDefault(); move(0); }
        else if (event.key === 'End') { event.preventDefault(); move(tabs.length - 1); }
    };
    return (
        <div className={className ? `cy-tabs ${className}` : 'cy-tabs'} role="tablist" aria-label={label}>
            {tabs.map((tab, index) => {
                const selected = tab.key === value;
                return (
                    <button
                        key={tab.key}
                        ref={(el) => { refs.current[tab.key] = el; }}
                        type="button"
                        role="tab"
                        id={tabId(prefix, tab.key)}
                        aria-selected={selected}
                        aria-controls={panelId(prefix, tab.key)}
                        tabIndex={selected ? 0 : -1}
                        className="cy-tabs__tab"
                        onClick={() => onChange(tab.key)}
                        onKeyDown={(e) => onKeyDown(e, index)}
                    >
                        {tab.label}
                        {tab.count !== undefined && <span className="cy-tabs__count" data-tone={tab.tone}>{tab.count}</span>}
                    </button>
                );
            })}
        </div>
    );
}
