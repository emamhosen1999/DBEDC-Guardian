import React from 'react';
import dayjs from 'dayjs';
import { Badge, Icon } from '@/Components/Cyber';
import { Avatar } from '@/Components/Cyber/Map/Avatar';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';
import { resolveWorkedDotState, packShiftIntervals } from '../rosterChipLayout';

/* Roster grid: a Cyber table, one row per employee, one 24-hour timeline cell per day. Chip geometry
   (start / end hour, cross-midnight, overlapping lanes) is unchanged; only the paint moved to attendance.css. */

// Small worked/missed indicator painted top-right of a shift chip. Renders nothing when `state` is null
// (future date / no shift).
const StatusDot = ({ state }) => (state ? <span className="cy-chip__dot" data-state={state} aria-hidden="true" /> : null);

const parseTimeToHours = (timeStr) => {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    const h = parseInt(parts[0] || 0, 10);
    const m = parseInt(parts[1] || 0, 10);
    return h + m / 60;
};

const HOUR_LABELS = [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22];
const HOUR_LINES = Array.from({ length: 23 }, (_, i) => i + 1);

/* One chip, placed on the cell's 24-column grid. edge: 'both' | 'right-open' | 'left-open' (a cross-midnight shift
   reads as ONE bar running through the cell border). `half` lanes shrink overlapping shifts to stack top / bottom. */
const Chip = ({ startCol, endCol, color, code, pending, edge = 'both', half = false, alignSelf = 'center', dotState = null }) => (
    <span
        className={['cy-chip', pending ? 'cy-chip--pending' : '', edge !== 'both' ? `cy-chip--${edge}` : '', half ? 'cy-chip--half' : ''].filter(Boolean).join(' ')}
        style={{ gridColumn: `${startCol} / ${endCol}`, alignSelf, background: color || 'var(--accent-9)' }}
    >
        <span className="cy-chip__code">{code}</span>
        <StatusDot state={dotState} />
    </span>
);

/* A cross-midnight chip: one absolutely positioned bar from the start hour, overflowing into the next day. */
const CrossChip = ({ hStart, spanHours, color, code, pending, dotState }) => (
    <span
        className={`cy-chip cy-chip--cross${pending ? ' cy-chip--pending' : ''}`}
        style={{ left: `calc(${(hStart / 24) * 100}% + 1px)`, width: `calc(${(spanHours / 24) * 100}% - 2px)`, background: color || 'var(--accent-9)' }}
    >
        <span className="cy-chip__code">{code}</span>
        <StatusDot state={dotState} />
    </span>
);

export default function RosterCalendar({ roster = {}, days = [], holidays = {}, shifts = [], onCellClick }) {
    const rows = Object.entries(roster);

    if (rows.length === 0) {
        return (
            <div className="cy-empty cy-empty--flat">
                <Icon name="calendar3" className="cy-empty__icon" />
                <p className="cy-empty__text">No roster data for this range</p>
            </div>
        );
    }

    const renderBody = (row, d, dayIndex, cell, disp) => {
        const shift = shifts.find(s => s.code === cell?.code);

        // Multi-shift cells (contract: cell.shifts = ALL shifts rostered that
        // user+date, legacy code/color remain = primary/first shift) fan out
        // into one chip per shift below; single/legacy cells keep the old path.
        const hasMultiShifts = Array.isArray(cell?.shifts) && cell.shifts.length > 1;
        const dotStateToday = resolveWorkedDotState(cell?.worked);

        // Previous day's shift for midnight crossing part2 rendering
        const prevDate = dayjs(d).subtract(1, 'day').format('YYYY-MM-DD');
        const cellYesterday = row.days?.[prevDate];
        const dispYesterday = cellYesterday ? resolveRosterCellDisplay(cellYesterday, holidays[prevDate]) : null;
        const shiftYesterday = cellYesterday && dispYesterday && (dispYesterday.kind === 'shift' || dispYesterday.kind === 'pending')
            ? shifts.find(s => s.code === cellYesterday.code)
            : null;
        const hasMultiShiftsYesterday = Array.isArray(cellYesterday?.shifts) && cellYesterday.shifts.length > 1;
        const dotStateYesterday = resolveWorkedDotState(cellYesterday?.worked);

        const chips = [];
        const isPendingCell = disp.kind === 'pending';

        // 1. Today's shift(s) - multi-shift fan-out when cell.shifts has >1 entry (each looked up in the shifts
        // catalog by id, same as the legacy single-code lookup); falls back to the legacy single-chip path.
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

            // Same-window (overlapping) shifts shrink to half-height and stack top/bottom instead of silently overlapping.
            const packed = packShiftIntervals(sameDay.map(r => ({ start: r.hStart, end: r.hEnd })));
            sameDay.forEach((r, i) => {
                const color = r.entry.color || r.config?.color || null;
                const overlapping = !!packed[i]?.overlapping;
                const lane = packed[i]?.lane;
                chips.push(
                    <Chip key={`m-${r.entry.id}-${i}`} startCol={r.hStart + 1} endCol={r.hEnd + 1} color={color} code={r.entry.code}
                        pending={isPendingCell} half={overlapping} alignSelf={overlapping ? (lane === 1 ? 'end' : 'start') : 'center'} dotState={dotStateToday} />,
                );
            });

            // Cross-midnight members keep the single-piece overflow rendering (one absolutely-positioned bar per shift).
            crossing.forEach((r) => {
                if (r.hStart >= 24) return;
                const spanHours = (24 - r.hStart) + r.hEnd;
                const color = r.entry.color || r.config?.color || null;
                chips.push(<CrossChip key={`m-cross-${r.entry.id}`} hStart={r.hStart} spanHours={spanHours} color={color} code={r.entry.code} pending={isPendingCell} dotState={dotStateToday} />);
            });
        } else if (shift) {
            const hStart = Math.round(parseTimeToHours(shift.start_time));
            const hEnd = Math.round(parseTimeToHours(shift.end_time));
            const crosses = shift.crosses_midnight || hEnd < hStart;

            if (crosses) {
                if (hStart < 24) {
                    const spanHours = (24 - hStart) + hEnd;
                    chips.push(<CrossChip key="cross" hStart={hStart} spanHours={spanHours} color={disp.color} code={shift.code} pending={disp.kind === 'pending'} dotState={dotStateToday} />);
                }
            } else {
                chips.push(<Chip key="full" startCol={hStart + 1} endCol={hEnd + 1} color={disp.color} code={shift.code} pending={disp.kind === 'pending'} dotState={dotStateToday} />);
            }
        }

        // 2. Yesterday's shift(s) (part2) - same multi-shift fan-out, falling back to the legacy single-shift path.
        if (hasMultiShiftsYesterday && dayIndex === 0) {
            cellYesterday.shifts.forEach((e) => {
                const config = shifts.find(s => s.id === e.id);
                if (!config) return;
                const hStartYes = Math.round(parseTimeToHours(config.start_time));
                const hEndYes = Math.round(parseTimeToHours(config.end_time));
                const crossesYes = config.crosses_midnight || hEndYes < hStartYes;
                if (crossesYes && hEndYes > 0) {
                    const color = e.color || config.color || null;
                    chips.push(<Chip key={`m-part2-${e.id}`} startCol={1} endCol={hEndYes + 1} color={color} code={e.code} pending={isPendingCell} edge="left-open" dotState={dotStateYesterday} />);
                }
            });
        } else if (shiftYesterday) {
            const hStartYes = Math.round(parseTimeToHours(shiftYesterday.start_time));
            const hEndYes = Math.round(parseTimeToHours(shiftYesterday.end_time));
            const crossesYes = shiftYesterday.crosses_midnight || hEndYes < hStartYes;

            // Only needed when yesterday's cell is not rendered in this month view (first column): otherwise
            // yesterday's own chip already overflows across the boundary as one piece.
            if (crossesYes && hEndYes > 0 && dayIndex === 0) {
                const isPendingYes = dispYesterday?.kind === 'pending';
                chips.push(<Chip key="part2" startCol={1} endCol={hEndYes + 1} color={dispYesterday?.color} code={shiftYesterday.code} pending={isPendingYes} edge="left-open" dotState={dotStateYesterday} />);
            }
        }

        return (
            <>
                {HOUR_LINES.map(h => <span key={h} className="cy-roster__hline" style={{ gridColumn: `${h} / ${h + 1}` }} aria-hidden="true" />)}
                {/* Half-day leave background overlay */}
                {disp.kind === 'leave-half' && <span className="cy-roster__half" style={{ gridColumn: disp.session === 'first_half' ? '1 / 13' : '13 / 25' }} aria-hidden="true" />}
                {chips}
                {/* Fallback centered chip if shift config is missing */}
                {!shift && !hasMultiShifts && (disp.kind === 'shift' || disp.kind === 'pending') && (
                    <span className="cy-chip cy-chip--fallback" style={{ gridColumn: '1 / 25', background: disp.color || 'var(--accent-9)' }}>
                        <span className="cy-chip__code">{disp.label}</span>
                        <StatusDot state={dotStateToday} />
                    </span>
                )}
            </>
        );
    };

    return (
        <div className="cy-roster" tabIndex={0} role="region" aria-label="Roster, scrollable">
            <table className="cy-table cy-roster__table" style={{ minWidth: `calc(10.5rem + ${days.length} * 9rem)` }}>
                <thead>
                    <tr>
                        <th scope="col" className="cy-roster__name">Employee</th>
                        {days.map((d) => {
                            const wd = dayjs(d).day(); // 0 Sun ... 6 Sat
                            const weekend = wd === 5 || wd === 6; // Fri/Sat (local week)
                            return (
                                <th key={d} scope="col" className={['cy-roster__day', holidays[d] ? 'is-holiday' : weekend ? 'is-weekend' : ''].filter(Boolean).join(' ')}>
                                    <span className="cy-roster__date">{dayjs(d).format('ddd D MMM')}</span>
                                    {/* vertical hour division lines + 2-hourly labels */}
                                    <span className="cy-roster__hours" aria-hidden="true">
                                        {HOUR_LABELS.map(h => <span key={h} style={{ left: `${(h / 24) * 100}%` }}>{String(h).padStart(2, '0')}</span>)}
                                    </span>
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody>
                    {rows.map(([userId, row]) => (
                        <tr key={userId}>
                            <th scope="row" className="cy-roster__name">
                                <span className="cy-who">
                                    <Avatar name={row.name} photo={row.profile_image_url} />
                                    <span className="cy-who__text"><span className="cy-who__name">{row.name || 'Unknown'}</span></span>
                                </span>
                            </th>
                            {days.map((d, dayIndex) => {
                                const cell = row.days?.[d];
                                const disp = resolveRosterCellDisplay(cell, holidays[d]);
                                const isShift = disp.kind !== 'holiday' && disp.kind !== 'leave';
                                const workedState = isShift ? resolveWorkedDotState(cell?.worked) : null;
                                const workedSuffix = workedState === 'worked' ? ' - worked' : workedState === 'missed' ? ' - missed' : '';
                                const tip = isShift ? `${disp.tooltip}${workedSuffix}${cell?.locked ? ' - Finalized by HR' : ''}` : disp.tooltip;
                                const label = `${row.name || 'Unknown'}, ${dayjs(d).format('ddd D MMM')}: ${tip}`;
                                return (
                                    <td key={d} className={['cy-roster__cell', disp.kind === 'holiday' || disp.holiday ? 'is-holiday' : ''].filter(Boolean).join(' ')}>
                                        <button type="button" className="cy-roster__btn" onClick={() => onCellClick?.(userId, d, cell)} disabled={!onCellClick} aria-label={label} title={tip}>
                                            {disp.kind === 'holiday' ? (
                                                <Badge soft color="warning" className="cy-roster__block">Holiday</Badge>
                                            ) : disp.kind === 'leave' ? (
                                                <Badge color="warning" className="cy-roster__block">{disp.leaveType || 'On Leave'}</Badge>
                                            ) : (
                                                <span className="cy-roster__line">{renderBody(row, d, dayIndex, cell, disp)}</span>
                                            )}
                                        </button>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
