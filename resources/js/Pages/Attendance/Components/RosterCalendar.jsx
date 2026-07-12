import React from 'react';
import { Box, Flex, Text, Tooltip } from '@radix-ui/themes';
import dayjs from 'dayjs';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';

const NAME_W = 168;
const CELL_W = 120; // Increased from 64 to 120 for 24 segmented columns
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
                                
                                {/* Timeline ruler axis at the bottom */}
                                <Box style={{ position: 'absolute', bottom: 2, left: 2, right: 2, height: 10 }}>
                                    {/* Horizontal axis line */}
                                    <Box style={{ position: 'absolute', left: 0, right: 0, top: 0, height: 1, background: 'var(--gray-a6)' }} />
                                    
                                    {/* Ticks at every 2 hours */}
                                    {Array.from({ length: 13 }).map((_, index) => {
                                        const h = index * 2;
                                        const isMajor = h % 6 === 0;
                                        return (
                                            <Box
                                                key={h}
                                                style={{
                                                    position: 'absolute',
                                                    left: `${(h / 24) * 100}%`,
                                                    top: 0,
                                                    width: 1,
                                                    height: isMajor ? 3 : 2,
                                                    background: isMajor ? 'var(--gray-a8)' : 'var(--gray-a5)',
                                                }}
                                            />
                                        );
                                    })}
                                    
                                    {/* Labels at 0, 6, 12, 18, 24 */}
                                    {[0, 6, 12, 18, 24].map(h => (
                                        <span
                                            key={h}
                                            style={{
                                                position: 'absolute',
                                                left: `${(h / 24) * 100}%`,
                                                transform: 'translateX(-50%)',
                                                top: 3,
                                                fontSize: 7,
                                                color: 'var(--gray-8)',
                                                fontWeight: 600,
                                                lineHeight: 1
                                            }}
                                        >
                                            {h}
                                        </span>
                                    ))}
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

                            // Render Holiday (Full Width)
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
                                                    textTransform: 'uppercase',
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
                                                    textTransform: 'uppercase',
                                                    padding: '0 4px',
                                                    overflow: 'hidden',
                                                    textOverflow: 'ellipsis',
                                                    whiteSpace: 'nowrap',
                                                }}
                                            >
                                                {disp.leaveType || 'On Leave'}
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
                                        borderRight: LINE, borderBottom: LINE, padding: '4px 2px',
                                        boxSizing: 'border-box',
                                        cursor: onCellClick ? 'pointer' : 'default',
                                    }}
                                >
                                    <Tooltip content={disp.tooltip}>
                                        <Box style={{
                                            width: '100%',
                                            height: '100%',
                                            display: 'grid',
                                            gridTemplateColumns: 'repeat(24, 1fr)',
                                            position: 'relative'
                                        }}>
                                            {/* 24 visual columns background */}
                                            {Array.from({ length: 24 }).map((_, hIndex) => (
                                                <Box
                                                    key={hIndex}
                                                    style={{
                                                        gridColumn: `${hIndex + 1} / ${hIndex + 2}`,
                                                        gridRow: 1,
                                                        height: '100%',
                                                        borderRight: hIndex < 23 ? '1px solid var(--gray-a3)' : 'none',
                                                        background: hIndex % 2 === 0 ? 'var(--gray-a1)' : 'transparent',
                                                    }}
                                                />
                                            ))}

                                            {/* Half-day Leave background overlay */}
                                            {disp.kind === 'leave-half' && (
                                                <Box
                                                    style={{
                                                        gridColumn: disp.session === 'first_half' ? '1 / 13' : '13 / 25',
                                                        gridRow: 1,
                                                        height: '100%',
                                                        background: 'var(--amber-a3)',
                                                        border: '1px dashed var(--amber-8)',
                                                        borderRadius: 3,
                                                        zIndex: 0,
                                                    }}
                                                />
                                            )}

                                            {/* Off-day dashed line overlay */}
                                            {disp.kind === 'off' && (
                                                <Box
                                                    style={{
                                                        gridColumn: '1 / 25',
                                                        gridRow: 1,
                                                        height: 2,
                                                        alignSelf: 'center',
                                                        borderTop: '2px dashed var(--gray-a6)',
                                                        zIndex: 1,
                                                    }}
                                                />
                                            )}

                                            {/* Shift chip positioned absolutely in column range */}
                                            {shift && (() => {
                                                const hStart = Math.round(parseTimeToHours(shift.start_time));
                                                const hEnd = Math.round(parseTimeToHours(shift.end_time));
                                                const crosses = shift.crosses_midnight || hEnd < hStart;

                                                const renderChip = (startCol, endCol, key) => (
                                                    <Box
                                                        key={key}
                                                        style={{
                                                            gridColumn: `${startCol} / ${endCol}`,
                                                            gridRow: 1,
                                                            height: 20,
                                                            alignSelf: 'center',
                                                            background: disp.color || 'var(--accent-9)',
                                                            borderRadius: 3,
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'center',
                                                            color: '#fff',
                                                            boxShadow: 'var(--shadow-1)',
                                                            zIndex: 2,
                                                            overflow: 'hidden',
                                                            whiteSpace: 'nowrap',
                                                            padding: '0 2px',
                                                            border: disp.kind === 'pending' ? '1px dashed var(--amber-8)' : 'none',
                                                        }}
                                                    >
                                                        <Text style={{ fontSize: 8, fontWeight: 700 }}>
                                                            {shift.code}
                                                        </Text>
                                                    </Box>
                                                );

                                                if (crosses) {
                                                    return (
                                                        <>
                                                            {hStart < 24 && renderChip(hStart + 1, 25, 'part1')}
                                                            {hEnd > 0 && renderChip(1, hEnd + 1, 'part2')}
                                                        </>
                                                    );
                                                } else {
                                                    return renderChip(hStart + 1, hEnd + 1, 'full');
                                                }
                                            })()}

                                            {/* Fallback centered chip if shift config is missing */}
                                            {!shift && (disp.kind === 'shift' || disp.kind === 'pending') && (
                                                <Box
                                                    style={{
                                                        gridColumn: '1 / 25',
                                                        gridRow: 1,
                                                        borderRadius: 4,
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                        background: disp.color || 'var(--accent-9)',
                                                        color: '#fff',
                                                        fontSize: 9,
                                                        fontWeight: 700,
                                                        zIndex: 2,
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
