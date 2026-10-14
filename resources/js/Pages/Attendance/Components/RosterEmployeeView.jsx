import React, { useMemo } from 'react';
import dayjs from 'dayjs';
import { Badge, Icon, SelectInput } from '@/Components/Cyber';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';

/**
 * Per-employee month calendar (Saturday-start week) - a readable single-person
 * view of the roster, built as Cyber's calendar month grid. Uses the same cell display logic as the
 * grid so the two views stay consistent.
 *
 * roster: { [userId]: { name, days: { 'YYYY-MM-DD': { code, color, off, leave } } } }
 */
const WEEKDAYS = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
const colIndex = (d) => (dayjs(d).day() + 1) % 7; // Sat -> 0, Sun -> 1 ... Fri -> 6

export default function RosterEmployeeView({
    employees = [], roster = {}, days = [], holidays = {}, shifts = [],
    selectedUserId, onSelectUser, onCellClick,
}) {
    const row = roster?.[selectedUserId] ?? roster?.[String(selectedUserId)] ?? null;

    const shiftByCode = useMemo(() => {
        const m = {};
        shifts.forEach(s => { m[s.code] = s; });
        return m;
    }, [shifts]);

    // Summary: count per shift code + off + holiday across the visible month.
    const summary = useMemo(() => {
        const counts = {};
        let off = 0, holiday = 0, working = 0;
        days.forEach(d => {
            if (holidays[d]) { holiday++; return; }
            const cell = row?.days?.[d];
            if (!cell || cell.off) { off++; return; }
            const code = cell.code || '-';
            counts[code] = (counts[code] || 0) + 1;
            working++;
        });
        return { counts, off, holiday, working };
    }, [row, days, holidays]);

    // Calendar weeks (Saturday-start), padded to full weeks.
    const weeks = useMemo(() => {
        if (!days.length) return [];
        const cells = [...Array(colIndex(days[0])).fill(null), ...days];
        while (cells.length % 7 !== 0) cells.push(null);
        const out = [];
        for (let i = 0; i < cells.length; i += 7) out.push(cells.slice(i, i + 7));
        return out;
    }, [days]);

    const colorFor = (code) => shiftByCode[code]?.color || 'var(--accent-9)';

    return (
        <div>
            {/* Person picker + summary */}
            <div className="cy-toolbar">
                <div className="cy-toolbar__group">
                    <SelectInput
                        size="sm"
                        className="cy-select--person"
                        aria-label="Person"
                        value={selectedUserId ? String(selectedUserId) : ''}
                        onChange={(v) => onSelectUser?.(v)}
                        options={[...(selectedUserId ? [] : [{ value: '', label: 'Select employee...', disabled: true }]), ...employees.map(e => ({ value: String(e.id), label: e.name }))]}
                    />
                </div>
                <div className="cy-toolbar__group cy-toolbar__group--end">
                    {Object.entries(summary.counts).map(([code, n]) => (
                        <span key={code} className="cy-legendrow__item"><span className="cy-swatch" style={{ background: colorFor(code) }} aria-hidden="true" /><b>{code}</b> {n}</span>
                    ))}
                    <span className="cy-legendrow__item"><span className="cy-swatch cy-swatch--off" aria-hidden="true" />Off {summary.off}</span>
                    {summary.holiday > 0 && <span className="cy-legendrow__item"><span className="cy-swatch" style={{ background: 'var(--amber-9)' }} aria-hidden="true" />Holiday {summary.holiday}</span>}
                </div>
            </div>

            {!row ? (
                <div className="cy-empty cy-empty--flat"><Icon name="person-badge" className="cy-empty__icon" /><p className="cy-empty__text">Select an employee to view their schedule.</p></div>
            ) : (
                <div className="cy-cal cy-cal--month" role="region" aria-label={`Schedule of ${row.name || 'employee'}`}>
                    <table className="cy-table cy-rostermonth">
                        <thead>
                            <tr>{WEEKDAYS.map(w => <th key={w} scope="col" className={w === 'Fri' || w === 'Sat' ? 'is-weekend' : undefined}>{w}</th>)}</tr>
                        </thead>
                        <tbody>
                            {weeks.map((week, wi) => (
                                <tr key={wi}>
                                    {week.map((d, di) => {
                                        if (!d) return <td key={di} className="cy-rostermonth__pad" />;
                                        const cell = row.days?.[d];
                                        const disp = resolveRosterCellDisplay(cell, holidays[d]);
                                        const isOff = disp.kind === 'off';
                                        const isHoliday = disp.kind === 'holiday';
                                        const chipColor = disp.color || colorFor(disp.label);
                                        const label = `${dayjs(d).format('ddd D MMM')}: ${disp.tooltip}`;
                                        return (
                                            <td key={d} className={isHoliday ? 'is-holiday' : undefined}>
                                                <button type="button" className="cy-rostermonth__cell" onClick={() => onCellClick?.(String(selectedUserId), d, cell)} disabled={!onCellClick} aria-label={label} title={disp.tooltip}>
                                                    <span className="cy-rostermonth__day">{dayjs(d).format('D')}</span>
                                                    {isHoliday ? <Badge soft color="warning">Holiday</Badge>
                                                        : isOff ? <span className="cy-rostermonth__off">Off</span>
                                                            : <span className={`cy-rostermonth__chip${disp.kind === 'pending' ? ' cy-chip--pending' : ''}`} style={{ background: chipColor }}>{disp.label}</span>}
                                                </button>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
