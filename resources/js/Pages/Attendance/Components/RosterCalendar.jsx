import React from 'react';
import { Box, Flex, Text, Tooltip } from '@radix-ui/themes';
import dayjs from 'dayjs';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';
import { resolveWorkedDotState, packShiftIntervals } from '../rosterChipLayout';

const NAME_W = 168;
const CELL_W = 144; // Width of each day cell
const HEADER_H = 56; // Header height to fit date and hourly labels
const LINE = '1px solid var(--gray-a5)';

// Small worked/missed indicator painted top-right of a shift chip. Absolute,
// ~7px, bordered in the panel background for contrast against any chip
// color. Renders nothing when `state` is null (future date / no shift).
const StatusDot = ({ state }) => {
    if (!state) return null;
    return (
        <Box
            style={{
                position: 'absolute',
                top: -3,
                right: -3,
                width: 7,
                height: 7,
                borderRadius: '50%',
                background: state === 'worked' ? 'var(--green-9)' : 'var(--red-9)',
                border: '1.5px solid var(--color-panel-background)',
                zIndex: 3,
                pointerEvents: 'none',
            }}
        />
    );
};

const parseTimeToHours = (timeStr) => {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    const h = parseInt(parts[0] || 0, 10);
    const m = parseInt(parts[1] || 0, 10);
    return h + m / 60;
};

const getInitials = (name) => {
    if (!name) return 'U';
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
};

export default function RosterCalendar({ roster = {}, days = [], holidays = {}, shifts = [], onCellClick }) {
    const rows = Object.entries(roster);

    if (rows.length === 0) {
        return (
            <Flex direction="column" align="center" py="9" gap="3">
                <Text size="2" color="gray">No roster data for this range</Text>
            </Flex>
        );
    }

    const gridWidth = NAME_W + days.length * CELL_W;

    const nameCell = (children, { header = false, zIndex = 2, bg = 'var(--color-panel-background)' } = {}) => (
        <Box
            style={{
                minWidth: NAME_W, width: NAME_W,
                height: header ? HEADER_H : 'auto',
                alignSelf: 'stretch',
                position: 'sticky', left: 0, zIndex,
                background: bg,
                borderRight: LINE, borderBottom: LINE,
                display: 'flex', alignItems: 'center', padding: '0 12px',
            }}
        >
            {children}
        </Box>
    );

    return (
        <Box style={{ overflowX: 'auto', border: LINE, borderRadius: 8 }}>
            <Box style={{ minWidth: gridWidth }}>
                {/* ── Day header ─────────────────────────────── */}
                <Flex style={{ background: 'var(--gray-3)' }}>
                    {nameCell(<Text size="2" weight="bold">Employee</Text>, { header: true, zIndex: 5, bg: 'var(--gray-3)' })}
                    {days.map((d) => {
                        const wd = dayjs(d).day(); // 0 Sun … 6 Sat
                        const weekend = wd === 5 || wd === 6; // Fri/Sat (local week)
                        return (
                            <Box
                                key={d}
                                style={{
                                    width: CELL_W, minWidth: CELL_W, height: HEADER_H,
                                    borderRight: LINE, borderBottom: LINE,
                                    background: holidays[d] ? 'var(--amber-a3)' : (weekend ? 'var(--gray-a3)' : 'transparent'),
                                    display: 'flex', flexDirection: 'column',
                                    alignItems: 'center', justifyContent: 'space-between',
                                    paddingTop: 6, paddingBottom: 4, position: 'relative',
                                    boxSizing: 'border-box',
                                }}
                            >
                                <Text size="1" weight="bold" style={{ fontSize: 10, lineHeight: 1 }}>{dayjs(d).format('ddd D MMM')}</Text>
                                
                                {/* Vertical hour division lines inside header */}
                                {Array.from({ length: 24 }).map((_, h) => {
                                    if (h === 0) return null;
                                    return (
                                        <Box
                                            key={h}
                                            style={{
                                                position: 'absolute',
                                                left: `${(h / 24) * 100}%`,
                                                top: '22px', // starts below date text
                                                bottom: 0,
                                                width: 1,
                                                borderLeft: '1px solid var(--gray-a3)',
                                                pointerEvents: 'none',
                                            }}
                                        />
                                    );
                                })}

                                {/* 2-hourly labels at the bottom of header cell */}
                                <Box style={{ position: 'relative', width: '100%', height: 10 }}>
                                    {[0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22].map(h => (
                                        <span
                                            key={h}
                                            style={{
                                                position: 'absolute',
                                                left: `${(h / 24) * 100}%`,
                                                transform: 'translateX(-50%)',
                                                bottom: 0,
                                                fontSize: 7,
                                                color: 'var(--gray-8)',
                                                fontWeight: 600,
                                                lineHeight: 1
                                            }}
                                        >
                                            {String(h).padStart(2, '0')}
                                        </span>
                                    ))}
                                </Box>
                            </Box>
                        );
                    })}
                </Flex>

                {/* ── Employee rows ──────────────────────────── */}
                {rows.map(([userId, row], i) => {
                    const rowBg = i % 2 ? 'var(--gray-2)' : 'var(--gray-1)';
                    return (
                        <Flex key={userId} style={{ background: rowBg, alignSelf: 'stretch' }}>
                            {nameCell(
                                <Flex gap="2" align="center" style={{ width: '100%' }}>
                                    <Box style={{
                                        width: 24, height: 24, borderRadius: '50%',
                                        background: 'var(--accent-3)', color: 'var(--accent-11)',
                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                        fontSize: 10, fontWeight: 700, flexShrink: 0,
                                        overflow: 'hidden',
                                    }}>
                                        {row.profile_image_url ? (
                                            <img
                                                src={row.profile_image_url}
                                                alt=""
                                                style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                                                onError={(e) => { e.currentTarget.style.display = 'none'; }}
                                            />
                                        ) : (
                                            getInitials(row.name)
                                        )}
                                    </Box>
                                    <Text size="1" weight="medium" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                        {row.name || 'Unknown'}
                                    </Text>
                                </Flex>,
                                { zIndex: 4, bg: rowBg }
                            )}
                            {days.map((d, dayIndex) => {
                                const cell = row.days?.[d];
                                const disp = resolveRosterCellDisplay(cell, holidays[d]);

                                // Render Holiday (Full Width)
                                if (disp.kind === 'holiday') {
                                    return (
                                        <Box
                                            key={d}
                                            onClick={() => onCellClick?.(userId, d, cell)}
                                            style={{
                                                width: CELL_W, minWidth: CELL_W, minHeight: 36, height: 'auto',
                                                borderRight: LINE, borderBottom: LINE, padding: 4,
                                                boxSizing: 'border-box',
                                                background: 'var(--amber-a2)',
                                                cursor: onCellClick ? 'pointer' : 'default',
                                                display: 'flex', alignItems: 'stretch'
                                            }}
                                        >
                                            <Tooltip content={disp.tooltip}>
                                                <Box
                                                    style={{
                                                        width: '100%', height: '100%', borderRadius: 4,
                                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                        background: 'var(--amber-4)',
                                                        color: 'var(--amber-11)',
                                                        fontSize: 9, fontWeight: 700,
                                                        textTransform: 'uppercase',
                                                        minHeight: 24,
                                                    }}
                                                >
                                                    Holiday
                                                </Box>
                                            </Tooltip>
                                        </Box>
                                    );
                                }

                                // Render Full Leave (Full Width)
                                if (disp.kind === 'leave') {
                                    return (
                                        <Box
                                            key={d}
                                            onClick={() => onCellClick?.(userId, d, cell)}
                                            style={{
                                                width: CELL_W, minWidth: CELL_W, minHeight: 36, height: 'auto',
                                                borderRight: LINE, borderBottom: LINE, padding: 4,
                                                boxSizing: 'border-box',
                                                cursor: onCellClick ? 'pointer' : 'default',
                                                display: 'flex', alignItems: 'stretch'
                                            }}
                                        >
                                            <Tooltip content={disp.tooltip}>
                                                <Box
                                                    style={{
                                                        width: '100%', height: '100%', borderRadius: 4,
                                                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                        background: 'var(--amber-9)',
                                                        color: '#fff',
                                                        fontSize: 9, fontWeight: 700,
                                                        textTransform: 'uppercase',
                                                        padding: '0 4px',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                        minHeight: 24,
                                                    }}
                                                >
                                                    {disp.leaveType || 'On Leave'}
                                                </Box>
                                            </Tooltip>
                                        </Box>
                                    );
                                }

                                const shift = shifts.find(s => s.code === cell?.code);

                                // Multi-shift cells (contract: cell.shifts = ALL shifts rostered that
                                // user+date, legacy code/color remain = primary/first shift) fan out
                                // into one chip per shift below; single/legacy cells keep the old path.
                                const hasMultiShifts = Array.isArray(cell?.shifts) && cell.shifts.length > 1;
                                const dotStateToday = resolveWorkedDotState(cell?.worked);
                                const workedSuffix = dotStateToday === 'worked' ? ' — worked' : dotStateToday === 'missed' ? ' — missed' : '';

                                // Previous day's shift for midnight crossing part2 rendering
                                const prevDate = dayjs(d).subtract(1, 'day').format('YYYY-MM-DD');
                                const cellYesterday = row.days?.[prevDate];
                                const dispYesterday = cellYesterday ? resolveRosterCellDisplay(cellYesterday, holidays[prevDate]) : null;
                                const shiftYesterday = cellYesterday && dispYesterday && (dispYesterday.kind === 'shift' || dispYesterday.kind === 'pending')
                                    ? shifts.find(s => s.code === cellYesterday.code)
                                    : null;
                                const hasMultiShiftsYesterday = Array.isArray(cellYesterday?.shifts) && cellYesterday.shifts.length > 1;
                                const dotStateYesterday = resolveWorkedDotState(cellYesterday?.worked);

                                return (
                                    <Box
                                        key={d}
                                        onClick={() => onCellClick?.(userId, d, cell)}
                                        style={{
                                            width: CELL_W, minWidth: CELL_W, minHeight: 36, height: 'auto',
                                            borderRight: LINE, borderBottom: LINE, padding: '4px 0px',
                                            boxSizing: 'border-box',
                                            background: disp.holiday ? 'var(--amber-a2)' : undefined,
                                            cursor: onCellClick ? 'pointer' : 'default',
                                            display: 'flex', alignItems: 'stretch'
                                        }}
                                    >
                                        <Tooltip content={`${disp.tooltip}${workedSuffix}`}>
                                            <Box style={{
                                                width: '100%',
                                                height: '100%',
                                                minHeight: 24,
                                                display: 'grid',
                                                gridTemplateColumns: 'repeat(24, 1fr)',
                                                position: 'relative'
                                            }}>
                                                {/* 24 visual columns background */}
                                                {Array.from({ length: 24 }).map((_, hIndex) => {
                                                    if (hIndex === 0) return null;
                                                    return (
                                                        <Box
                                                            key={hIndex}
                                                            style={{
                                                                gridColumn: `${hIndex} / ${hIndex + 1}`,
                                                                gridRow: 1,
                                                                height: '100%',
                                                                borderRight: '1px solid var(--gray-a3)',
                                                                pointerEvents: 'none',
                                                                zIndex: 0,
                                                            }}
                                                        />
                                                    );
                                                })}

                                                {/* Half-day Leave background overlay */}
                                                {disp.kind === 'leave-half' && (
                                                    <Box
                                                        style={{
                                                            gridColumn: disp.session === 'first_half' ? '1 / 13' : '13 / 25',
                                                            gridRow: 1,
                                                            height: '24px',
                                                            alignSelf: 'center',
                                                            background: 'var(--amber-a3)',
                                                            border: '1px dashed var(--amber-8)',
                                                            borderRadius: 4,
                                                            zIndex: 1,
                                                            margin: '0 2px',
                                                        }}
                                                    />
                                                )}

                                                {/* Shift chip positioned absolutely in column range */}
                                                {(() => {
                                                    // edge: 'both' (default) rounds all corners; 'right-open' /
                                                    // 'left-open' square the side that continues into the adjacent
                                                    // day cell, so a cross-midnight shift reads as ONE bar running
                                                    // through the cell border instead of two spliced chips.
                                                    // heightPx/alignSelf let overlapping multi-shift chips shrink to
                                                    // half height and stack top/bottom instead of painting over
                                                    // each other; dotState paints the worked/missed indicator.
                                                    const renderChip = (startCol, endCol, color, code, isPending, key, edge = 'both', showLabel = true, heightPx = 24, alignSelf = 'center', dotState = null) => (
                                                        <Box
                                                            key={key}
                                                            style={{
                                                                gridColumn: `${startCol} / ${endCol}`,
                                                                gridRow: 1,
                                                                height: `${heightPx}px`,
                                                                alignSelf,
                                                                position: 'relative',
                                                                background: color || 'var(--accent-9)',
                                                                borderRadius: edge === 'right-open' ? '4px 0 0 4px'
                                                                    : edge === 'left-open' ? '0 4px 4px 0' : 4,
                                                                display: 'flex',
                                                                alignItems: 'center',
                                                                justifyContent: 'center',
                                                                color: '#fff',
                                                                boxShadow: 'var(--shadow-2)',
                                                                zIndex: 1,
                                                                overflow: 'visible',
                                                                whiteSpace: 'nowrap',
                                                                padding: showLabel ? '0 4px' : 0,
                                                                border: isPending ? '1px dashed var(--amber-8)' : 'none',
                                                                margin: edge === 'right-open' ? '0 0 0 1px'
                                                                    : edge === 'left-open' ? '0 1px 0 0' : '0 1px',
                                                            }}
                                                        >
                                                            {showLabel && (
                                                                <Text style={{ fontSize: heightPx < 20 ? 7 : 9, fontWeight: 700 }}>
                                                                    {code}
                                                                </Text>
                                                            )}
                                                            <StatusDot state={dotState} />
                                                        </Box>
                                                    );

                                                    const chips = [];
                                                    const isPendingCell = disp.kind === 'pending';

                                                    // 1. Today's shift(s) — multi-shift fan-out when cell.shifts has
                                                    // >1 entry (each looked up in the shifts catalog by id, same as
                                                    // the legacy single-code lookup); falls back to the legacy
                                                    // single-chip path when shifts is absent/length<=1.
                                                    if (hasMultiShifts) {
                                                        const resolved = cell.shifts.map(e => {
                                                            const config = shifts.find(s => s.id === e.id);
                                                            const hStart = config ? Math.round(parseTimeToHours(config.start_time)) : 0;
                                                            const hEnd = config ? Math.round(parseTimeToHours(config.end_time)) : 0;
                                                            const crosses = !!(config && (config.crosses_midnight || hEnd < hStart));
                                                            return { entry: e, config, hStart, hEnd, crosses };
                                                        });

                                                        const sameDay = resolved.filter(r => !r.crosses);
                                                        const crossing = resolved.filter(r => r.crosses);

                                                        // Same-window (overlapping) shifts shrink to half-height and
                                                        // stack top/bottom instead of silently overlapping.
                                                        const packed = packShiftIntervals(sameDay.map(r => ({ start: r.hStart, end: r.hEnd })));
                                                        sameDay.forEach((r, i) => {
                                                            const color = r.entry.color || r.config?.color || null;
                                                            const overlapping = !!packed[i]?.overlapping;
                                                            const lane = packed[i]?.lane;
                                                            chips.push(renderChip(
                                                                r.hStart + 1, r.hEnd + 1, color, r.entry.code, isPendingCell,
                                                                `m-${r.entry.id}-${i}`, 'both', true,
                                                                overlapping ? 12 : 24,
                                                                overlapping ? (lane === 1 ? 'end' : 'start') : 'center',
                                                                dotStateToday,
                                                            ));
                                                        });

                                                        // Cross-midnight members keep the single-piece overflow
                                                        // rendering (one absolutely-positioned bar per shift).
                                                        crossing.forEach((r) => {
                                                            if (r.hStart >= 24) return;
                                                            const spanHours = (24 - r.hStart) + r.hEnd;
                                                            const color = r.entry.color || r.config?.color || null;
                                                            chips.push(
                                                                <Box
                                                                    key={`m-cross-${r.entry.id}`}
                                                                    style={{
                                                                        position: 'absolute',
                                                                        left: `calc(${(r.hStart / 24) * 100}% + 1px)`,
                                                                        width: `calc(${(spanHours / 24) * 100}% - 2px)`,
                                                                        top: '50%',
                                                                        transform: 'translateY(-50%)',
                                                                        height: '24px',
                                                                        background: color || 'var(--accent-9)',
                                                                        borderRadius: 4,
                                                                        display: 'flex',
                                                                        alignItems: 'center',
                                                                        justifyContent: 'center',
                                                                        color: '#fff',
                                                                        boxShadow: 'var(--shadow-2)',
                                                                        zIndex: 2,
                                                                        whiteSpace: 'nowrap',
                                                                        padding: '0 4px',
                                                                        border: isPendingCell ? '1px dashed var(--amber-8)' : 'none',
                                                                    }}
                                                                >
                                                                    <Text style={{ fontSize: 9, fontWeight: 700 }}>
                                                                        {r.entry.code}
                                                                    </Text>
                                                                    <StatusDot state={dotStateToday} />
                                                                </Box>
                                                            );
                                                        });
                                                    } else if (shift) {
                                                        const hStart = Math.round(parseTimeToHours(shift.start_time));
                                                        const hEnd = Math.round(parseTimeToHours(shift.end_time));
                                                        const crosses = shift.crosses_midnight || hEnd < hStart;

                                                        if (crosses) {
                                                            if (hStart < 24) {
                                                                // ONE unbroken chip: absolutely positioned from the
                                                                // start hour, overflowing the cell into the next day
                                                                // (the sibling cell's content sits at a lower z-index,
                                                                // so the chip paints over its border and hour lines).
                                                                const spanHours = (24 - hStart) + hEnd;
                                                                chips.push(
                                                                    <Box
                                                                        key="cross"
                                                                        style={{
                                                                            position: 'absolute',
                                                                            left: `calc(${(hStart / 24) * 100}% + 1px)`,
                                                                            width: `calc(${(spanHours / 24) * 100}% - 2px)`,
                                                                            top: '50%',
                                                                            transform: 'translateY(-50%)',
                                                                            height: '24px',
                                                                            background: disp.color || 'var(--accent-9)',
                                                                            borderRadius: 4,
                                                                            display: 'flex',
                                                                            alignItems: 'center',
                                                                            justifyContent: 'center',
                                                                            color: '#fff',
                                                                            boxShadow: 'var(--shadow-2)',
                                                                            zIndex: 2,
                                                                            whiteSpace: 'nowrap',
                                                                            padding: '0 4px',
                                                                            border: disp.kind === 'pending' ? '1px dashed var(--amber-8)' : 'none',
                                                                        }}
                                                                    >
                                                                        <Text style={{ fontSize: 9, fontWeight: 700 }}>
                                                                            {shift.code}
                                                                        </Text>
                                                                        <StatusDot state={dotStateToday} />
                                                                    </Box>
                                                                );
                                                            }
                                                        } else {
                                                            chips.push(renderChip(hStart + 1, hEnd + 1, disp.color, shift.code, disp.kind === 'pending', 'full', 'both', true, 24, 'center', dotStateToday));
                                                        }
                                                    }

                                                    // 2. Yesterday's shift(s) (part2) — same multi-shift fan-out,
                                                    // falling back to the legacy single-shift path.
                                                    if (hasMultiShiftsYesterday && dayIndex === 0) {
                                                        cellYesterday.shifts.forEach((e) => {
                                                            const config = shifts.find(s => s.id === e.id);
                                                            if (!config) return;
                                                            const hStartYes = Math.round(parseTimeToHours(config.start_time));
                                                            const hEndYes = Math.round(parseTimeToHours(config.end_time));
                                                            const crossesYes = config.crosses_midnight || hEndYes < hStartYes;
                                                            if (crossesYes && hEndYes > 0) {
                                                                const color = e.color || config.color || null;
                                                                chips.push(renderChip(1, hEndYes + 1, color, e.code, isPendingCell, `m-part2-${e.id}`, 'left-open', true, 24, 'center', dotStateYesterday));
                                                            }
                                                        });
                                                    } else if (shiftYesterday) {
                                                        const hStartYes = Math.round(parseTimeToHours(shiftYesterday.start_time));
                                                        const hEndYes = Math.round(parseTimeToHours(shiftYesterday.end_time));
                                                        const crossesYes = shiftYesterday.crosses_midnight || hEndYes < hStartYes;

                                                        // Only needed when yesterday's cell is not rendered in this
                                                        // month view (first column): otherwise yesterday's own chip
                                                        // already overflows across the boundary as one piece.
                                                        if (crossesYes && hEndYes > 0 && dayIndex === 0) {
                                                            const isPendingYes = dispYesterday?.kind === 'pending';
                                                            chips.push(renderChip(1, hEndYes + 1, dispYesterday?.color, shiftYesterday.code, isPendingYes, 'part2', 'left-open', true, 24, 'center', dotStateYesterday));
                                                        }
                                                    }

                                                    return <>{chips}</>;
                                                })()}

                                                {/* Fallback centered chip if shift config is missing */}
                                                {!shift && !hasMultiShifts && (disp.kind === 'shift' || disp.kind === 'pending') && (
                                                    <Box
                                                        style={{
                                                            gridColumn: '1 / 25',
                                                            gridRow: 1,
                                                            height: '24px',
                                                            alignSelf: 'center',
                                                            position: 'relative',
                                                            borderRadius: 4,
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'center',
                                                            background: disp.color || 'var(--accent-9)',
                                                            color: '#fff',
                                                            fontSize: 9,
                                                            fontWeight: 700,
                                                            zIndex: 1,
                                                            margin: '0 4px',
                                                        }}
                                                    >
                                                        {disp.label}
                                                        <StatusDot state={dotStateToday} />
                                                    </Box>
                                                )}
                                            </Box>
                                        </Tooltip>
                                    </Box>
                                );
                            })}
                        </Flex>
                    );
                })}
            </Box>
        </Box>
    );
}
