import React, { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import { showToast } from '@/utils/toastUtils';
import { handleExportResponse } from '@/utils/exportUtils';
import { useAttendanceStore } from '@/store/attendanceStore';
import * as useAttendanceQuery from '@/api/queries/useAttendanceQuery';
import MonthlySidebar from './Components/MonthlySidebar';
import AttendanceExportDialog from './Components/AttendanceExportDialog';
import { MARK_LEGEND, SYMBOL_KEY, SYMBOL_TOTALS, markAttrs, symbolLabel, symbolTone, symbolTotals } from '@/utils/attendanceSymbols';
import DepartmentSelect from './Components/DepartmentSelect';
import { Avatar } from '@/Components/Cyber/Map/Avatar';
import { Card, Field, Icon, IconButton, Pagination, Toolbar, ToolbarGroup } from '@/Components/Cyber';

const EMPTY_LIST = [];
const EMPTY_MAP = {};

/* ── helpers ──────────────────────────────────────────────── */
const isWeekendDay = (date, weekendDays) => {
    if (!weekendDays?.length) {
        const d = dayjs(date).day();
        return d === 0 || d === 6;
    }
    return weekendDays.includes(dayjs(date).format('dddd').toLowerCase());
};

const dateKeyOf = (year, month, day) => dayjs(`${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`).format('YYYY-MM-DD');

/* One day of one employee: the register symbol itself (never a stand-in glyph). Colour is a secondary cue only:
   tone by symbol, and the register's festival colouring (pink # for the person who took the holiday, green √ for
   the person who worked it). The text alternative carries the date, the symbol's meaning and the punches. */
const DayMark = ({ cell, dateKey }) => {
    const symbol = (typeof cell === 'object' ? (cell?.symbol ?? cell?.status) : cell) || SYMBOL_NOT_YET;
    const detail = [
        `${dateKey}: ${symbolLabel(symbol)}`,
        cell?.punch_in ? `in ${cell.punch_in}` : null,
        cell?.punch_out ? `out ${cell.punch_out}` : null,
        cell?.total_work_hours ? `${cell.total_work_hours} hours` : null,
        cell?.remarks ? cell.remarks : null,
        cell?.marks?.border ? 'worked on a rest day' : null,
        cell?.marks?.fill === 'ot_unapplied' ? 'worked on a rest day or holiday, not claimed' : null,
        cell?.marks?.fill === 'ot_declined' ? 'overtime declined' : null,
        cell?.marks?.ot === 'approved' ? 'overtime approved' : null,
    ].filter(Boolean).join(', ');
    return (
        <span className="cy-mark" data-status={symbol} data-tone={symbolTone(symbol)} {...markAttrs(cell, symbol)} role="img" aria-label={detail} title={detail}>
            {symbol}
        </span>
    );
};

const LeaveCount = ({ count }) => <span className="cy-leavecount" data-zero={count === 0}>{count}</span>;

const SYMBOL_NOT_YET = SYMBOL_KEY[3][0];

/* True when any listed employee has a festival holiday on this date: the date header turns red, as in the register. */
const isFestivalDate = (rows, dateKey) => rows.some((r) => r?.[dateKey]?.is_festival);

const MonthEmpty = ({ text }) => (
    <div className="cy-empty cy-empty--flat">
        <Icon name="calendar3" className="cy-empty__icon" />
        <p className="cy-empty__text">{text}</p>
    </div>
);

const MonthLoading = () => <div className="cy-empty cy-empty--flat" role="status"><p className="cy-empty__text">Loading attendance…</p></div>;

/* ════════════════════════════════════════════════════════════
   DESKTOP VIEW — sticky-header, sticky-first-column matrix
   ═══════════════════════════════════════════════════════════ */
const DesktopMonthTable = ({ rows, days, month, year, weekendDays, loading }) => {
    const colSpan = days.length + 1 + SYMBOL_TOTALS.length;
    return (
        <div className="cy-cal" tabIndex={0} role="region" aria-label="Monthly attendance, scrollable">
            <table className="cy-table">
                <thead>
                    <tr>
                        <th scope="col">Employee</th>
                        {days.map((d) => {
                            const date = dayjs(dateKeyOf(year, month, d));
                            const wknd = isWeekendDay(date, weekendDays);
                            const festival = isFestivalDate(rows, dateKeyOf(year, month, d));
                            return (
                                <th key={d} scope="col" className={[wknd ? 'is-weekend' : '', festival ? 'is-festival' : ''].filter(Boolean).join(' ') || undefined} title={festival ? 'Festival holiday' : undefined}>
                                    <span className="cy-cal__day"><span>{d}</span><span className="cy-cal__dow">{date.format('dd')}</span></span>
                                </th>
                            );
                        })}
                        {SYMBOL_TOTALS.map(([heading, symbol]) => (
                            <th key={symbol} scope="col" className="cy-cal__leave" title={`${heading}: ${symbolLabel(symbol)}`}>
                                <span className="cy-cal__day"><span>{heading}</span><span className="cy-cal__dow">({symbol})</span></span>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {loading
                        ? <tr><td colSpan={colSpan}><MonthLoading /></td></tr>
                        : rows.length === 0
                            ? <tr><td colSpan={colSpan}><MonthEmpty text="No attendance records found for this period" /></td></tr>
                            : rows.map((row, ri) => (
                                <tr key={row.user_id || ri}>
                                    <td>
                                        <div className="cy-who">
                                            <Avatar name={row.name} photo={row.profile_image_url || row.profile_image} />
                                            <span className="cy-who__text"><span className="cy-who__name">{row.name || 'Unknown'}</span></span>
                                        </div>
                                    </td>
                                    {days.map((d) => {
                                        const dateKey = dateKeyOf(year, month, d);
                                        return (
                                            <td key={d} className={isWeekendDay(dateKey, weekendDays) ? 'is-weekend' : undefined}>
                                                <DayMark cell={row[dateKey]} dateKey={dateKey} />
                                            </td>
                                        );
                                    })}
                                    {symbolTotals(days.map((d) => row[dateKeyOf(year, month, d)])).map((t) => (
                                        <td key={t.symbol} className="cy-cal__leave"><LeaveCount count={t.count} /></td>
                                    ))}
                                </tr>
                            ))}
                </tbody>
            </table>
        </div>
    );
};

/* ════════════════════════════════════════════════════════════
   MOBILE VIEW — one row per employee, the month grid opens under it
   ═══════════════════════════════════════════════════════════ */
const MobileEmployeeCard = ({ row, days, month, year, weekendDays }) => {
    const [expanded, setExpanded] = useState(false);
    const gridId = useRef(`mcard-${row.user_id ?? Math.random().toString(36).slice(2)}`).current;
    const firstDay = dayjs(`${year}-${String(month).padStart(2, '0')}-01`).day();

    return (
        <li>
            <button type="button" className="cy-mcard__head" aria-expanded={expanded} aria-controls={gridId} onClick={() => setExpanded((e) => !e)}>
                <div className="cy-who">
                    <Avatar name={row.name} photo={row.profile_image_url || row.profile_image} />
                    <span className="cy-who__text"><span className="cy-who__name">{row.name || 'Unknown'}</span></span>
                </div>
                <span className="cy-mcard__tags">
                    {symbolTotals(days.map((d) => row[dateKeyOf(year, month, d)])).map((t) => (
                        t.count === 0 ? null : <span key={t.symbol} className="cy-text-warn" title={t.label}>{t.heading} ({t.symbol}): {t.count}</span>
                    ))}
                    <Icon name="chevron-down" className="cy-mcard__chev" />
                </span>
            </button>
            {expanded && (
                <div className="cy-mcard__grid" id={gridId}>
                    {['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map((d) => <span key={d} className="cy-mcard__dow">{d}</span>)}
                    {Array.from({ length: firstDay }, (_, i) => <span key={`b${i}`} />)}
                    {days.map((dayNum) => {
                        const dateKey = dateKeyOf(year, month, dayNum);
                        return (
                            <span key={dayNum} className={`cy-mcard__cell${isWeekendDay(dateKey, weekendDays) ? ' is-weekend' : ''}`}>
                                <span>{dayNum}</span>
                                <DayMark cell={row[dateKey]} dateKey={dateKey} />
                            </span>
                        );
                    })}
                </div>
            )}
        </li>
    );
};

const MobileMonthCalendar = ({ rows, days, month, year, weekendDays, loading }) => {
    if (loading) return <MonthLoading />;
    if (rows.length === 0) return <MonthEmpty text="No attendance data for this month" />;
    return (
        <ul className="cy-mcard">
            {rows.map((row, i) => (
                <MobileEmployeeCard key={row.user_id || i} row={row} days={days} month={month} year={year} weekendDays={weekendDays} />
            ))}
        </ul>
    );
};

/* ════════════════════════════════════════════════════════════
   MAIN COMPONENT
   ═══════════════════════════════════════════════════════════ */
const MonthlyCalendarTab = ({ selectedMonth, onMonthChange, departments = [], onSummary }) => {
    const { auth, url } = usePage().props;

    const canViewAll  = auth.permissions?.includes('attendance.view') || false;
    const isAdminView = canViewAll && url !== '/attendance-employee';

    // Zustand store for shared state
    const { employeeQuery, setEmployeeQuery } = useAttendanceStore();


    /* Department and page live in the URL under an `m_` prefix (every
       Attendance tab stays mounted). The employee search is shared with the
       timesheet through the attendance store and is left there. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'm_page',
        debounceKeys: [],
        defaults: {
            m_dept: 'all',
            m_page: 1,
            m_per: 20,
        },
    });
    const selectedDepartmentId = f.values.m_dept;
    const setSelectedDepartmentId = (v) => f.set('m_dept', v);
    const currentPage = f.values.m_page;
    const perPage = f.values.m_per;
    const setCurrentPage = f.setPage;
    const setPerPage = (v) => f.set('m_per', v);

    const [downloading, setDownloading] = useState('');
    const [sidebarOpen, setSidebarOpen] = useState(true);

    // Month and search changes come from outside this hook; return to page 1
    // when they move, but not on mount so a restored page survives.
    const externalKeyRef = useRef(`${selectedMonth}|${employeeQuery}`);
    useEffect(() => {
        const key = `${selectedMonth}|${employeeQuery}`;
        if (externalKeyRef.current === key) return;
        externalKeyRef.current = key;
        if (f.values.m_page !== 1) f.setPage(1);
    }, [selectedMonth, employeeQuery]); // eslint-disable-line react-hooks/exhaustive-deps

    // React Query mutation
    const exportMonthlyCalendar = useAttendanceQuery.useExportMonthlyCalendar();
    const isMutating = exportMonthlyCalendar.isPending;

    /* responsive detection */
    const isMobile = useMediaQuery('(max-width: 767px)');

    /* derived */
    const yearNum  = dayjs(selectedMonth + '-01').year();
    const monthNum = dayjs(selectedMonth + '-01').month() + 1;
    const daysInMonth = dayjs(selectedMonth + '-01').daysInMonth();
    // Stable identity: the sidebar's charts re-draw whenever their inputs change identity.
    const days = useMemo(() => Array.from({ length: daysInMonth }, (_, i) => i + 1), [daysInMonth]);

    // React Query hooks
    const { data: monthlySummaryData, isLoading, refetch } = useAttendanceQuery.useMonthlySummary({
        currentMonth: monthNum,
        currentYear: yearNum,
        departmentId: selectedDepartmentId !== 'all' ? parseInt(selectedDepartmentId) : null,
        employee: employeeQuery,
        page: currentPage,
        perPage: perPage,
    });

    // Derived state from React Query data
    const rows = monthlySummaryData?.data || EMPTY_LIST;
    const totalRows = monthlySummaryData?.total || 0;
    const lastPage = monthlySummaryData?.last_page || 1;
    const leaveTypes = monthlySummaryData?.leaveTypes || EMPTY_LIST;
    const leaveCounts = monthlySummaryData?.leaveCounts || EMPTY_MAP;
    const settings = monthlySummaryData?.settings || null;
    const weekendDays = settings?.weekend_days || EMPTY_LIST;

    /* export: the register for any from/to period, one sheet per department (the server defaults to the payroll cycle) */
    const [exportOpen, setExportOpen] = useState(false);
    const exportRegister = useCallback(async ({ type, from, to }) => {
        setDownloading(type);
        try {
            const mime = type === 'pdf' ? 'application/pdf' : undefined;
            const ext = type === 'excel' ? 'xlsx' : 'pdf';
            const dept = selectedDepartmentId !== 'all' ? parseInt(selectedDepartmentId) : undefined;
            const data = await exportMonthlyCalendar.mutateAsync({ from, to, type, departmentId: dept });
            await handleExportResponse(data, `DBEDC_Attendance_Register_${from}_${to}.${ext}`, mime, ext);
        } catch (err) {
            console.error('Export failed:', err);
            showToast.error(`Failed to export ${type}.`);
            throw err;
        } finally { setDownloading(''); }
    }, [selectedDepartmentId, exportMonthlyCalendar]);

    /* month nav */
    const goMonth = (delta) => {
        const newMonth = dayjs(selectedMonth + '-01').add(delta, 'month').format('YYYY-MM');
        onMonthChange(newMonth);
    };

    /* The page header shows the month's size as chips; reported by value so the effect never loops. */
    const summaryKey = JSON.stringify([isLoading ? null : totalRows, daysInMonth]);
    useEffect(() => {
        onSummary?.([
            { value: isLoading ? undefined : totalRows, label: 'Employees', tone: 'theme' },
            { value: daysInMonth, label: 'Days', tone: 'default' },
        ]);
    }, [summaryKey, onSummary]); // eslint-disable-line react-hooks/exhaustive-deps

    const footer = (lastPage > 1 || currentPage > 1) && !isLoading ? (
        <Pagination
            pagination={{ currentPage, perPage, total: totalRows }}
            onPageChange={setCurrentPage}
            onRowsPerPageChange={(v) => setPerPage(v)}
            loading={isLoading}
            label="Monthly calendar pagination"
        />
    ) : null;

    /* ── render ─────────────────────────────────────────────── */
    return (
        <div className="dl-page">
            <div className="dl-row">
                <div className="dl-col dl-col--12">
                    <Card id="attendance-monthly" title="Monthly calendar" flush footer={footer}>
                        {/* Toolbar */}
                        <Toolbar label="Monthly calendar filters">
                            {/* left: month nav + search + department */}
                            <ToolbarGroup>
                                <IconButton icon="chevron-left" label="Previous month" onClick={() => goMonth(-1)} />
                                <Field icon="calendar3" type="month" label="Month" value={selectedMonth} onChange={(e) => onMonthChange(e.target.value)} />
                                <IconButton icon="chevron-right" label="Next month" onClick={() => goMonth(1)} />

                                {isAdminView && (
                                    <Field icon="search" type="search" label="Search employee…" value={employeeQuery} onChange={(e) => setEmployeeQuery(e.target.value)} />
                                )}

                                {isAdminView && (
                                    <DepartmentSelect value={selectedDepartmentId || 'all'} onChange={setSelectedDepartmentId} departments={departments} />
                                )}
                            </ToolbarGroup>

                            {/* right: stats toggle + refresh + export (admin only) */}
                            <ToolbarGroup end>
                                {isAdminView && (
                                    <button type="button" className="cy-btn cy-btn--outline-default" aria-pressed={sidebarOpen} onClick={() => setSidebarOpen(!sidebarOpen)}>
                                        <Icon name="layout-sidebar-inset-reverse" /> {sidebarOpen ? 'Hide stats' : 'Show stats'}
                                    </button>
                                )}

                                <IconButton icon="arrow-clockwise" label="Refresh" onClick={() => refetch()} />
                                {isAdminView && (
                                    <button type="button" className="cy-btn cy-btn--outline-default" disabled={isLoading || downloading !== ''} onClick={() => setExportOpen(true)}>
                                        <Icon name="download" /> {downloading ? 'Exporting…' : 'Export'}
                                    </button>
                                )}
                            </ToolbarGroup>
                        </Toolbar>

                        {/* Symbol key: the register's, word for word */}
                        <ul className="cy-legendrow" aria-label="Symbol Key" style={{ margin: 0, listStyle: 'none' }}>
                            <li className="cy-legendrow__item cy-legendrow__lead">Symbol Key :{' '}</li>
                            {SYMBOL_KEY.map(([symbol, label]) => (
                                <li key={symbol} className="cy-legendrow__item"><span className="cy-mark" data-status={symbol} data-tone={symbolTone(symbol)} aria-hidden="true">{symbol}</span>{' '}<span>= {label}</span>{' '}</li>
                            ))}
                        </ul>
                        <ul className="cy-legendrow cy-legendrow--note" aria-label="Colour cues" style={{ margin: 0, listStyle: 'none' }}>
                            <li className="cy-legendrow__item"><span className="cy-mark" data-festival-head="true" aria-hidden="true" />Red date = festival holiday</li>
                            <li className="cy-legendrow__item"><span className="cy-mark" data-status="#" data-festival="taken" aria-hidden="true">#</span>took the holiday</li>
                            <li className="cy-legendrow__item"><span className="cy-mark" data-status="√" data-festival="worked" aria-hidden="true">√</span>worked it</li>
                            <li className="cy-legendrow__item"><span className="cy-mark is-weekend" aria-hidden="true" style={{ background: 'color-mix(in srgb, var(--amber-9) 18%, transparent)' }} />Weekend shading</li>
                        </ul>
                        <ul className="cy-legendrow cy-legendrow--note" aria-label="Marks" style={{ margin: 0, listStyle: 'none' }}>
                            <li className="cy-legendrow__item cy-legendrow__lead">Marks :{' '}</li>
                            {MARK_LEGEND.map(([key, text]) => (
                                <li key={key} className="cy-legendrow__item">
                                    <span className="cy-mark" aria-hidden="true"
                                        data-status={key === 'leave_bold' ? '§' : '√'} data-glyph={key === 'leave_bold' ? '§' : '√'}
                                        {...({ ot_approved: { 'data-mark-font': 'ot_green' }, ot_unapplied: { 'data-mark-fill': 'ot_unapplied' }, ot_declined: { 'data-mark-fill': 'ot_declined' }, rest_day_work: { 'data-mark-border': 'true', 'data-mark-font': 'ot_green' }, leave_bold: { 'data-mark-bold': 'true' } }[key])} />{' '}<span>{text}</span>{' '}
                                </li>
                            ))}
                        </ul>

                        {/* Table / cards, with the stats sidebar */}
                        <div className="cy-split">
                            <div className="cy-split__main">
                                {isMobile
                                    ? <MobileMonthCalendar
                                        rows={rows} days={days} month={monthNum} year={yearNum}
                                        weekendDays={weekendDays} loading={isLoading}
                                      />
                                    : <DesktopMonthTable
                                        rows={rows} days={days} month={monthNum} year={yearNum}
                                        weekendDays={weekendDays} loading={isLoading}
                                      />}
                            </div>
                            {isAdminView && sidebarOpen && (
                                <aside className="cy-split__side" aria-label="Monthly analytics">
                                    <MonthlySidebar
                                        rows={rows}
                                        days={days}
                                        monthNum={monthNum}
                                        yearNum={yearNum}
                                        leaveTypes={leaveTypes}
                                        leaveCounts={leaveCounts}
                                        isLoading={isLoading}
                                    />
                                </aside>
                            )}
                        </div>
                    </Card>
                </div>
            </div>
            {exportOpen && (
                <AttendanceExportDialog
                    title="Export attendance register"
                    reports={[{ value: 'register', label: 'Attendance register', help: 'One sheet per department in the company register format (PDF: one page per department). Employees come from your department scope.' }]}
                    onClose={() => setExportOpen(false)}
                    onExport={exportRegister}
                />
            )}
        </div>
    );
};

export default MonthlyCalendarTab;
