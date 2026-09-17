/**
 * VizKit — the shared building blocks for analytics charts.
 *
 * Colours are roles, not hex: every chart reads `var(--viz-*)` tokens defined
 * once below for light and dark (`html.dark`). The categorical order and the
 * attendance composition set were run through the palette validator for both
 * modes (lightness band, chroma floor, adjacent CVD ΔE, normal-vision floor,
 * contrast). Where a mode landed in a warn band, the charts that use it carry
 * the required secondary encoding: a legend, 2px surface gaps between marks,
 * and a table view.
 *
 * Rules these components enforce:
 *  - one axis per chart, solid hairline grid, recessive ink;
 *  - a legend whenever there are two or more series, none for one;
 *  - every chart has a table-view twin, so no value is tooltip-only;
 *  - text is never drawn in a series colour.
 */
import React, { useId, useInsertionEffect, useMemo, useState } from 'react';
import { Box, Flex, SegmentedControl, Table, Text } from '@radix-ui/themes';
import { ArrowDownIcon, ArrowUpIcon, MinusIcon } from '@radix-ui/react-icons';

const VIZ_CSS = `
.viz-root {
  --viz-surface: var(--color-panel-solid, #fcfcfb);
  --viz-ink: var(--gray-12, #0b0b0b);
  --viz-ink-2: var(--gray-11, #52514e);
  --viz-muted: #898781;
  --viz-grid: #e1e0d9;
  --viz-axis: #c3c2b7;
  --viz-good-text: #006300;
  --viz-bad-text: #b3261e;
  /* categorical, fixed order — never cycled */
  --viz-s1: #2a78d6;
  --viz-s2: #eb6834;
  --viz-s3: #1baf7a;
  /* attendance composition (validated as a set, in this adjacency order) */
  --viz-ontime: #008300;
  --viz-late: #eda100;
  --viz-leave: #2a78d6;
  --viz-absent: #e34948;
}
html.dark .viz-root {
  --viz-muted: #898781;
  --viz-grid: #2c2c2a;
  --viz-axis: #383835;
  --viz-good-text: #0ca30c;
  --viz-bad-text: #e66767;
  --viz-s1: #3987e5;
  --viz-s2: #d95926;
  --viz-s3: #199e70;
  --viz-ontime: #008300;
  --viz-late: #c98500;
  --viz-leave: #3987e5;
  --viz-absent: #e66767;
}
.viz-card { transition: opacity 160ms ease; }
.viz-card[data-stale="true"] { opacity: .55; }
.viz-hbar-track { position: relative; height: 10px; border-radius: 4px; background: transparent; }
.viz-hbar-fill { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 0 4px 4px 0; min-width: 2px; }
.viz-hbar-row:hover .viz-hbar-fill, .viz-hbar-row:focus-visible .viz-hbar-fill { filter: brightness(1.08); }
.viz-hbar-row:focus-visible { outline: 2px solid var(--focus-8, #2a78d6); outline-offset: 2px; border-radius: 6px; }
`;

/** Add the token stylesheet to <head> once; it outlives any single chart. */
function useVizStyles() {
    useInsertionEffect(() => {
        if (typeof document === 'undefined' || document.getElementById('viz-kit-styles')) return;
        const el = document.createElement('style');
        el.id = 'viz-kit-styles';
        el.textContent = VIZ_CSS;
        document.head.appendChild(el);
    }, []);
}

/** Scope for analytics content. `stale` dims the previous render during a refetch instead of flashing a skeleton. */
export function VizRoot({ children, stale = false }) {
    useVizStyles();
    return (
        <Box className="viz-root viz-card" data-stale={stale ? 'true' : 'false'} aria-busy={stale}>
            {children}
        </Box>
    );
}

/* ── formatting ─────────────────────────────────────────────────────────── */

const taka = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const taka2 = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const fmt = {
    money: (v) => `৳${taka.format(Number(v || 0))}`,
    money2: (v) => `৳${taka2.format(Number(v || 0))}`,
    /** Short axis ticks: ৳1.2k, ৳3.4L (lakh), ৳1.1Cr. */
    moneyShort: (v) => {
        const n = Math.abs(Number(v || 0));
        const sign = Number(v) < 0 ? '−' : '';
        if (n >= 1e7) return `${sign}৳${(n / 1e7).toFixed(1)}Cr`;
        if (n >= 1e5) return `${sign}৳${(n / 1e5).toFixed(1)}L`;
        if (n >= 1e3) return `${sign}৳${(n / 1e3).toFixed(1)}k`;
        return `${sign}৳${Math.round(n)}`;
    },
    pct: (v, digits = 1) => (v == null ? '—' : `${Number(v).toFixed(digits)}%`),
    num: (v, digits = 0) => (v == null ? '—' : Number(v).toLocaleString('en-IN', { maximumFractionDigits: digits })),
    date: (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }),
};

/* ── stat tile ──────────────────────────────────────────────────────────── */

/**
 * @param {Object} props
 * @param {string} props.label
 * @param {React.ReactNode} props.value
 * @param {React.ReactNode} [props.sub]
 * @param {{ text: string, direction: 'up'|'down'|'flat', good: boolean|null }} [props.delta]
 *        `good` decides the colour, `direction` the arrow — they are independent,
 *        because "spending went up" is bad while "attendance went up" is good.
 */
export function StatTile({ label, value, sub, delta }) {
    const Icon = delta?.direction === 'up' ? ArrowUpIcon : delta?.direction === 'down' ? ArrowDownIcon : MinusIcon;
    const deltaColor = delta?.good == null ? 'var(--viz-ink-2)' : delta.good ? 'var(--viz-good-text)' : 'var(--viz-bad-text)';

    return (
        <Box
            p="3"
            style={{
                borderRadius: 14,
                border: '1px solid var(--gray-a4)',
                background: 'var(--viz-surface)',
                minWidth: 0,
            }}
        >
            <Text as="div" size="1" style={{ color: 'var(--viz-ink-2)', fontWeight: 600, letterSpacing: '.02em' }}>
                {label}
            </Text>
            <Text as="div" size="6" weight="bold" style={{ color: 'var(--viz-ink)', lineHeight: 1.2, marginTop: 4 }}>
                {value}
            </Text>
            {delta && (
                <Flex align="center" gap="1" mt="1" style={{ color: deltaColor }}>
                    <Icon width="12" height="12" aria-hidden="true" />
                    <Text size="1" weight="medium">{delta.text}</Text>
                </Flex>
            )}
            {sub && (
                <Text as="div" size="1" mt="1" style={{ color: 'var(--viz-ink-2)' }}>
                    {sub}
                </Text>
            )}
        </Box>
    );
}

/** Build a delta descriptor from a signed change. */
export function deltaOf(change, { unit = '%', goodWhen = 'up', suffix = '', digits } = {}) {
    if (change == null || Number.isNaN(Number(change))) return null;
    const n = Number(change);
    // Whole-number changes (counts) print without a spurious ".0".
    digits ??= Number.isInteger(n) ? 0 : 1;
    const direction = Math.abs(n) < 0.05 ? 'flat' : n > 0 ? 'up' : 'down';
    const good = direction === 'flat' ? null : (direction === 'up') === (goodWhen === 'up');
    const sign = n > 0 ? '+' : n < 0 ? '−' : '';
    return { direction, good, text: `${sign}${Math.abs(n).toFixed(digits)}${unit}${suffix}` };
}

/* ── legend ─────────────────────────────────────────────────────────────── */

export function Legend({ items }) {
    if (!items || items.length < 2) return null;
    return (
        <Flex gap="3" wrap="wrap" role="list" aria-label="Legend">
            {items.map((it) => (
                <Flex key={it.label} align="center" gap="1" role="listitem">
                    <Box aria-hidden="true" style={{ width: 10, height: 10, borderRadius: 3, background: it.color }} />
                    <Text size="1" style={{ color: 'var(--viz-ink-2)' }}>{it.label}</Text>
                </Flex>
            ))}
        </Flex>
    );
}

/* ── chart card with table-view twin ────────────────────────────────────── */

/**
 * @param {Object} props
 * @param {string} props.title
 * @param {string} [props.subtitle]
 * @param {Array<{label:string,color:string}>} [props.legend]
 * @param {{ columns: Array<{key:string,label:string,align?:'left'|'right',format?:Function}>, rows: Array<Object> }} [props.table]
 * @param {boolean} [props.empty]
 * @param {string} [props.emptyText]
 */
export function ChartCard({ title, subtitle, legend, table, empty, emptyText = 'No data for this period.', actions, children }) {
    const [view, setView] = useState('chart');
    const headingId = useId();

    return (
        <Box
            role="region"
            aria-labelledby={headingId}
            p={{ initial: '3', sm: '4' }}
            style={{ borderRadius: 16, border: '1px solid var(--gray-a4)', background: 'var(--viz-surface)', minWidth: 0 }}
        >
            <Flex justify="between" align="start" gap="3" mb="3" wrap="wrap">
                <Box style={{ minWidth: 0 }}>
                    <Text as="div" id={headingId} size="3" weight="bold" style={{ color: 'var(--viz-ink)' }}>{title}</Text>
                    {subtitle && <Text as="div" size="1" mt="1" style={{ color: 'var(--viz-ink-2)' }}>{subtitle}</Text>}
                </Box>
                <Flex gap="2" align="center">
                    {actions}
                    {table && !empty && (
                        <SegmentedControl.Root size="1" value={view} onValueChange={setView} aria-label={`${title}: view as`}>
                            <SegmentedControl.Item value="chart">Chart</SegmentedControl.Item>
                            <SegmentedControl.Item value="table">Table</SegmentedControl.Item>
                        </SegmentedControl.Root>
                    )}
                </Flex>
            </Flex>

            {empty ? (
                <Flex align="center" justify="center" style={{ minHeight: 160 }}>
                    <Text size="2" style={{ color: 'var(--viz-ink-2)' }}>{emptyText}</Text>
                </Flex>
            ) : view === 'table' && table ? (
                <DataTable columns={table.columns} rows={table.rows} caption={title} />
            ) : (
                <>
                    {legend && legend.length > 1 && <Box mb="2"><Legend items={legend} /></Box>}
                    {children}
                </>
            )}
        </Box>
    );
}

export function DataTable({ columns, rows, caption }) {
    return (
        <Box style={{ overflowX: 'auto', maxHeight: 360 }}>
            <Table.Root size="1" variant="ghost" aria-label={caption}>
                <Table.Header>
                    <Table.Row>
                        {columns.map((c) => (
                            <Table.ColumnHeaderCell key={c.key} style={{ textAlign: c.align ?? 'left', whiteSpace: 'nowrap' }}>
                                {c.label}
                            </Table.ColumnHeaderCell>
                        ))}
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {rows.map((r, i) => (
                        <Table.Row key={r.id ?? r.key ?? i}>
                            {columns.map((c) => (
                                <Table.Cell
                                    key={c.key}
                                    style={{
                                        textAlign: c.align ?? 'left',
                                        fontVariantNumeric: c.align === 'right' ? 'tabular-nums' : undefined,
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {c.format ? c.format(r[c.key], r) : r[c.key]}
                                </Table.Cell>
                            ))}
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}

/* ── recharts chrome ────────────────────────────────────────────────────── */

export const axisProps = {
    tick: { fontSize: 11, fill: 'var(--viz-muted)' },
    tickLine: false,
    axisLine: { stroke: 'var(--viz-axis)' },
};

export const gridProps = { stroke: 'var(--viz-grid)', vertical: false };

/** Tooltip body for Recharts' `content` prop. */
export function VizTooltip({ active, payload, label, labelFormatter, valueFormatter = (v) => v, footer }) {
    if (!active || !payload?.length) return null;
    return (
        <Box
            p="2"
            style={{
                background: 'var(--color-panel-solid)',
                border: '1px solid var(--gray-a6)',
                borderRadius: 8,
                boxShadow: '0 6px 20px rgba(0,0,0,.12)',
                minWidth: 150,
            }}
        >
            <Text as="div" size="1" weight="bold" mb="1" style={{ color: 'var(--viz-ink)' }}>
                {labelFormatter ? labelFormatter(label, payload) : label}
            </Text>
            {payload.map((p) => (
                <Flex key={p.dataKey} justify="between" align="center" gap="3">
                    <Flex align="center" gap="1">
                        <Box aria-hidden="true" style={{ width: 8, height: 8, borderRadius: 2, background: p.color || p.fill || p.stroke }} />
                        <Text size="1" style={{ color: 'var(--viz-ink-2)' }}>{p.name}</Text>
                    </Flex>
                    <Text size="1" weight="medium" style={{ color: 'var(--viz-ink)', fontVariantNumeric: 'tabular-nums' }}>
                        {valueFormatter(p.value, p)}
                    </Text>
                </Flex>
            ))}
            {footer && <Box mt="1">{typeof footer === 'function' ? footer(payload) : footer}</Box>}
        </Box>
    );
}

/* ── horizontal bar list (HTML, direct-labelled) ─────────────────────────── */

/**
 * Ranked horizontal bars drawn in HTML, so labels never collide and every row
 * is keyboard-focusable with its full detail as its accessible name.
 *
 * @param {Object} props
 * @param {Array<{key:string,label:string,value:number,display:string,sub?:string}>} props.rows
 * @param {string} [props.color]
 * @param {number} [props.max] Scale maximum; defaults to the largest value.
 * @param {number} [props.limit] Fold everything past this into "Other".
 */
export function HBarList({ rows, color = 'var(--viz-s1)', max, limit, otherLabel = 'Other', formatOther }) {
    const shown = useMemo(() => {
        if (!limit || rows.length <= limit) return rows;
        const head = rows.slice(0, limit - 1);
        const tail = rows.slice(limit - 1);
        const value = tail.reduce((s, r) => s + Number(r.value || 0), 0);
        return [...head, {
            key: '__other',
            label: `${otherLabel} (${tail.length})`,
            value,
            display: formatOther ? formatOther(value, tail) : String(value),
            sub: tail.map((t) => t.label).join(', '),
        }];
    }, [rows, limit, otherLabel, formatOther]);

    const scaleMax = max ?? Math.max(1, ...shown.map((r) => Number(r.value || 0)));

    return (
        <Flex direction="column" gap="3" role="list">
            {shown.map((r) => {
                const width = `${Math.max(0, Math.min(100, (Number(r.value || 0) / scaleMax) * 100))}%`;
                return (
                    <Box
                        key={r.key}
                        className="viz-hbar-row"
                        role="listitem"
                        tabIndex={0}
                        aria-label={`${r.label}: ${r.display}${r.sub ? `, ${r.sub}` : ''}`}
                        title={r.sub}
                    >
                        <Flex justify="between" align="baseline" gap="3" mb="1">
                            <Text size="2" style={{ color: 'var(--viz-ink)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {r.label}
                            </Text>
                            <Text size="2" weight="bold" style={{ color: 'var(--viz-ink)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                                {r.display}
                            </Text>
                        </Flex>
                        <div className="viz-hbar-track" style={{ borderBottom: '1px solid var(--viz-grid)' }}>
                            <div className="viz-hbar-fill" style={{ width, background: r.color ?? color }} />
                        </div>
                        {r.sub && (
                            <Text as="div" size="1" mt="1" style={{ color: 'var(--viz-muted)' }}>{r.sub}</Text>
                        )}
                    </Box>
                );
            })}
        </Flex>
    );
}
