import React from 'react';
import { Box, Flex, Text, Tooltip } from '@radix-ui/themes';
import dayjs from 'dayjs';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';

const NAME_W = 168;
const CELL_W = 64; // Increased from 46 to 64 for visual timeline room
const ROW_H = 36;
const HEADER_H = 46; // Dedicated header height to accommodate the ruler
const LINE = '1px solid var(--gray-a5)';

const parseTimeToHours = (timeStr) => {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    const h = parseInt(parts[0] || 0, 10);
    const m = parseInt(parts[1] || 0, 10);
    return h + m / 60;
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

    const nameCell = (children, { header = false, zIndex = 1 } = {}) => (
        <Box
            style={{
                minWidth: NAME_W, width: NAME_W, height: header ? HEADER_H : ROW_H,
                position: 'sticky', left: 0, zIndex,
                background: header ? 'var(--gray-a2)' : 'var(--color-panel)',
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
                <Flex style={{ background: 'var(--gray-a2)' }}>
                    {nameCell(<Text size="2" weight="bold">Employee</Text>, { header: true, zIndex: 3 })}
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
                                    alignItems: 'center', justifyContent: 'flex-start',
                                    paddingTop: 4, position: 'relative',
                                    boxSizing: 'border-box',
                                }}
                            >
                                <Text size="1" weight="medium" style={{ lineHeight: 1 }}>{dayjs(d).format('D')}</Text>
                                <Text size="1" color="gray" style={{ fontSize: 9, lineHeight: 1, marginBottom: 2 }}>{dayjs(d).format('dd')}</Text>
                                
                                {/* Tiny ruler axis at the bottom */}
                                <Box style={{ position: 'absolute', bottom: 2, left: 4, right: 4, height: 10 }}>
                                    {/* Horizontal axis line */}
                                    <Box style={{ position: 'absolute', left: 0, right: 0, top: 0, height: 1, background: 'var(--gray-a6)' }} />
                                    
                                    {/* Ticks */}
                                    <Box style={{ position: 'absolute', left: '0%', top: 0, width: 1, height: 3, background: 'var(--gray-a8)' }} />
                                    <Box style={{ position: 'absolute', left: '25%', top: 0, width: 1, height: 2, background: 'var(--gray-a5)' }} />
                                    <Box style={{ position: 'absolute', left: '50%', top: 0, width: 1, height: 3, background: 'var(--gray-a8)' }} />
                                    <Box style={{ position: 'absolute', left: '75%', top: 0, width: 1, height: 2, background: 'var(--gray-a5)' }} />
                                    <Box style={{ position: 'absolute', left: '100%', top: 0, width: 1, height: 3, background: 'var(--gray-a8)' }} />
                                    
                                    {/* Labels */}
                                    <span style={{ position: 'absolute', left: '0%', transform: 'translateX(-50%)', top: 3, fontSize: 7, color: 'var(--gray-8)', fontWeight: 600, lineHeight: 1 }}>0</span>
                                    <span style={{ position: 'absolute', left: '50%', transform: 'translateX(-50%)', top: 3, fontSize: 7, color: 'var(--gray-8)', fontWeight: 600, lineHeight: 1 }}>12</span>
                                    <span style={{ position: 'absolute', left: '100%', transform: 'translateX(-50%)', top: 3, fontSize: 7, color: 'var(--gray-8)', fontWeight: 600, lineHeight: 1 }}>24</span>
                                </Box>
                            </Box>
                        );
                    })}
                </Flex>

                {/* ── Employee rows ──────────────────────────── */}
                {rows.map(([userId, row], i) => (
                    <Flex key={userId} style={{ background: i % 2 ? 'var(--gray-a1)' : 'transparent' }}>
                        {nameCell(<Text size="2">{row.name || 'Unknown'}</Text>, { zIndex: 1 })}
                        {days.map((d) => {
                            const cell = row.days?.[d];
                            const disp = resolveRosterCellDisplay(cell, holidays[d]);

                            // Render Holiday
                            if (disp.kind === 'holiday') {
                                return (
                                    <Box
                                        key={d}
                                        onClick={() => onCellClick?.(userId, d, cell)}
                                        style={{
                                            width: CELL_W, minWidth: CELL_W, height: ROW_H,
                                            borderRight: LINE, borderBottom: LINE, padding: 4,
                                            boxSizing: 'border-box',
                                            background: 'var(--amber-a2)',
                                            cursor: onCellClick ? 'pointer' : 'default',
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
                                                }}
                                            >
                                                H
                                            </Box>
                                        </Tooltip>
                                    </Box>
                                );
                            }

                            // Render Full Leave
                            if (disp.kind === 'leave') {
                                return (
                                    <Box
                                        key={d}
                                        onClick={() => onCellClick?.(userId, d, cell)}
                                        style={{
                                            width: CELL_W, minWidth: CELL_W, height: ROW_H,
                                            borderRight: LINE, borderBottom: LINE, padding: 4,
                                            boxSizing: 'border-box',
                                            cursor: onCellClick ? 'pointer' : 'default',
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
                                                }}
                                            >
                                                LV
                                            </Box>
                                        </Tooltip>
                                    </Box>
                                );
                            }

                            const shift = shifts.find(s => s.code === cell?.code);

                            return (
                                <Box
                                    key={d}
                                    onClick={() => onCellClick?.(userId, d, cell)}
                                    style={{
                                        width: CELL_W, minWidth: CELL_W, height: ROW_H,
                                        borderRight: LINE, borderBottom: LINE, padding: 4,
                                        boxSizing: 'border-box',
                                        position: 'relative',
                                        cursor: onCellClick ? 'pointer' : 'default',
                                    }}
                                >
                                    <Tooltip content={disp.tooltip}>
                                        <Box style={{ width: '100%', height: '100%', position: 'relative' }}>
                                            {/* 24-hour timeline track */}
                                            <Box
                                                style={{
                                                    position: 'absolute',
                                                    left: 0,
                                                    right: 0,
                                                    top: 'calc(50% - 1.5px)',
                                                    height: 3,
                                                    background: 'var(--gray-a4)',
                                                    borderRadius: 1.5,
                                                    border: disp.kind === 'pending' ? '1px dashed var(--amber-8)' : 'none',
                                                }}
                                            />

                                            {/* Half-day Leave marker */}
                                            {disp.kind === 'leave-half' && (
                                                <Box
                                                    style={{
                                                        position: 'absolute',
                                                        left: disp.session === 'first_half' ? '0%' : '50%',
                                                        width: '50%',
                                                        top: 'calc(50% - 1.5px)',
                                                        height: 3,
                                                        background: 'var(--amber-9)',
                                                        borderRadius: 1.5,
                                                    }}
                                                />
                                            )}

                                            {/* Off-day dashed line */}
                                            {disp.kind === 'off' && (
                                                <Box
                                                    style={{
                                                        position: 'absolute',
                                                        left: 0,
                                                        right: 0,
                                                        top: 'calc(50% - 1px)',
                                                        height: 2,
                                                        borderTop: '2px dashed var(--gray-a6)',
                                                    }}
                                                />
                                            )}

                                            {/* Shift chip positioned absolutely */}
                                            {shift && (() => {
                                                const hStart = parseTimeToHours(shift.start_time);
                                                const hEnd = parseTimeToHours(shift.end_time);
                                                const crosses = shift.crosses_midnight || hEnd < hStart;

                                                const renderChip = (leftPct, widthPct) => (
                                                    <Box
                                                        style={{
                                                            position: 'absolute',
                                                            left: `${leftPct}%`,
                                                            width: `${widthPct}%`,
                                                            top: 'calc(50% - 9px)',
                                                            height: 18,
                                                            background: disp.color || 'var(--accent-9)',
                                                            borderRadius: 3,
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'center',
                                                            color: '#fff',
                                                            boxShadow: 'var(--shadow-1)',
                                                        }}
                                                    >
                                                        <Text style={{ fontSize: 8, fontWeight: 700 }}>
                                                            {shift.code.charAt(0)}
                                                        </Text>
                                                    </Box>
                                                );

                                                if (crosses) {
                                                    const p1Width = 24 - hStart;
                                                    return (
                                                        <>
                                                            {p1Width > 0 && renderChip((hStart / 24) * 100, (p1Width / 24) * 100)}
                                                            {hEnd > 0 && renderChip(0, (hEnd / 24) * 100)}
                                                        </>
                                                    );
                                                } else {
                                                    return renderChip((hStart / 24) * 100, ((hEnd - hStart) / 24) * 100);
                                                }
                                            })()}

                                            {/* Fallback centered chip if shift config is missing */}
                                            {!shift && (disp.kind === 'shift' || disp.kind === 'pending') && (
                                                <Box
                                                    style={{
                                                        position: 'absolute',
                                                        left: 0,
                                                        right: 0,
                                                        top: 0,
                                                        bottom: 0,
                                                        borderRadius: 4,
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                        background: disp.color || 'var(--accent-9)',
                                                        color: '#fff',
                                                        fontSize: 9,
                                                        fontWeight: 700,
                                                    }}
                                                >
                                                    {disp.label}
                                                </Box>
                                            )}
                                        </Box>
                                    </Tooltip>
                                </Box>
                            );
                        })}
                    </Flex>
                ))}
            </Box>
        </Box>
    );
}
