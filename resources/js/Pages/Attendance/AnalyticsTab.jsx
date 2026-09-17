/**
 * Attendance analytics for one month.
 *
 * Team view (attendance.view) covers everyone in scope and adds department
 * comparison and a watch list; everyone else sees their own month. All figures
 * come from the same engine pass as the monthly stats, so they reconcile with
 * the timesheet and calendar.
 *
 * The month is the Attendance page's own month; scope and department live in
 * the URL under an `aa_` prefix (every Attendance tab stays mounted).
 */
import React, { useMemo } from 'react';
import { Box, Callout, Flex, Grid, SegmentedControl, Select, Text, TextField } from '@radix-ui/themes';
import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import axios from 'axios';
import dayjs from 'dayjs';
import {
    Bar, BarChart, CartesianGrid, Line, LineChart, ReferenceLine,
    ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import {
    ChartCard, DataTable, HBarList, StatTile, VizRoot, VizTooltip,
    axisProps, deltaOf, fmt, gridProps,
} from '@/Components/Charts/VizKit';

// Stack order matches the order the palette was validated in.
const COMPOSITION = [
    { key: 'on_time', label: 'On time', color: 'var(--viz-ontime)' },
    { key: 'late', label: 'Late', color: 'var(--viz-late)' },
    { key: 'leave', label: 'On leave', color: 'var(--viz-leave)' },
    { key: 'absent', label: 'Absent', color: 'var(--viz-absent)' },
];

const dayCount = (v) => fmt.num(v, 1);

export default function AnalyticsTab({ month, onMonthChange, departments = [], canViewTeam = false, isActive = true, isMobile = false }) {
    const f = useQueryFilters({
        mode: 'client',
        debounceKeys: [],
        defaults: { aa_scope: canViewTeam ? 'team' : 'self', aa_dept: '' },
    });
    const scope = canViewTeam && f.values.aa_scope === 'team' ? 'team' : 'self';
    const departmentId = scope === 'team' ? f.values.aa_dept : '';

    const { data, isLoading, isFetching, isError, refetch } = useQuery({
        queryKey: ['attendance-analytics', month, scope, departmentId],
        queryFn: async () => (await axios.get(route('attendance.analytics'), {
            params: { month, scope, department_id: departmentId || undefined },
        })).data.data,
        enabled: isActive && Boolean(month),
        placeholderData: keepPreviousData,
        staleTime: 60_000,
    });

    const locked = data?.meta?.departmentLocked;

    return (
        <VizRoot stale={isFetching && !isLoading}>
            {/* One filter row scopes every chart below. */}
            <Flex gap="3" align="end" wrap="wrap" mb="4">
                <Box>
                    <Text as="label" size="1" color="gray" htmlFor="aa-month">Month</Text>
                    <TextField.Root
                        id="aa-month"
                        type="month"
                        size="2"
                        value={month}
                        max={dayjs().format('YYYY-MM')}
                        onChange={(e) => e.target.value && onMonthChange?.(e.target.value)}
                    />
                </Box>
                {canViewTeam && (
                    <Box>
                        <Text as="div" size="1" color="gray">View</Text>
                        <SegmentedControl.Root size="2" value={scope} onValueChange={(v) => f.set('aa_scope', v)} aria-label="Whose attendance">
                            <SegmentedControl.Item value="team">Team</SegmentedControl.Item>
                            <SegmentedControl.Item value="self">Me</SegmentedControl.Item>
                        </SegmentedControl.Root>
                    </Box>
                )}
                {scope === 'team' && !locked && departments.length > 0 && (
                    <Box style={{ minWidth: 200 }}>
                        <Text as="div" size="1" color="gray">Department</Text>
                        <Select.Root value={departmentId || 'all'} onValueChange={(v) => f.set('aa_dept', v === 'all' ? '' : v)}>
                            <Select.Trigger style={{ width: '100%' }} aria-label="Department" />
                            <Select.Content>
                                <Select.Item value="all">All departments</Select.Item>
                                {departments.map((d) => (
                                    <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>
                )}
            </Flex>

            {isLoading ? (
                <Flex align="center" justify="center" p="6"><Text color="gray">Loading analytics…</Text></Flex>
            ) : isError || !data ? (
                <Callout.Root color="red" role="alert">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>
                        Analytics could not be loaded.{' '}
                        <button type="button" onClick={() => refetch()} style={{ textDecoration: 'underline', background: 'none', border: 0, color: 'inherit', cursor: 'pointer' }}>
                            Try again
                        </button>
                    </Callout.Text>
                </Callout.Root>
            ) : (
                <Content data={data} isMobile={isMobile} />
            )}
        </VizRoot>
    );
}

function Content({ data, isMobile }) {
    const b = data.breakdown;
    const isTeam = data.meta.scope === 'team';
    const working = useMemo(() => b.daily.filter((d) => d.expected > 0 || d.leave > 0), [b.daily]);
    const nothingYet = working.length === 0;

    return (
        <Flex direction="column" gap="4">
            <Headline data={data} isTeam={isTeam} />

            <DailyComposition rows={working} isMobile={isMobile} empty={nothingYet} />

            <Grid columns={{ initial: '1', lg: '2' }} gap="4">
                <DailyRate rows={working} target={b.rate.scheduled} empty={nothingYet} isMobile={isMobile} />
                <Punctuality punctuality={b.punctuality} late={data.attendance.lateArrivals} />
            </Grid>

            <Grid columns={{ initial: '1', md: '2' }} gap="4">
                <WeekdayBars
                    title="Late arrivals by weekday"
                    rows={b.weekday}
                    dataKey="late"
                    color="var(--viz-late)"
                    name="Late arrivals"
                    empty={!data.attendance.lateArrivals}
                    emptyText="No late arrivals this month."
                />
                <WeekdayBars
                    title="Absences by weekday"
                    rows={b.weekday}
                    dataKey="absent"
                    color="var(--viz-absent)"
                    name="Absent days"
                    empty={!data.attendance.absent}
                    emptyText="No absences this month."
                />
            </Grid>

            {isTeam && b.departments.length > 1 && <Departments rows={b.departments} />}
            {isTeam && b.watchlist && <Watchlist watchlist={b.watchlist} />}
        </Flex>
    );
}

/* ── headline tiles ─────────────────────────────────────────────────────── */

function Headline({ data, isTeam }) {
    const a = data.attendance;
    const h = data.hours;
    const cmp = data.comparison;
    const r = data.breakdown.rate;

    return (
        <Grid columns={{ initial: '2', sm: '4' }} gap="3">
            <StatTile
                label="Attendance rate"
                value={fmt.pct(r.scheduled)}
                delta={cmp?.rate != null && r.scheduled != null
                    ? deltaOf(r.scheduled - cmp.rate, { unit: ' pts', goodWhen: 'up', digits: 1, suffix: ` vs ${cmp.month}` })
                    : null}
                sub={isTeam ? `${data.meta.totalEmployees} employees · ${data.meta.month}` : data.meta.month}
            />
            <StatTile
                label="Present"
                value={`${dayCount(r.present_on_schedule)} days`}
                sub={
                    `of ${dayCount(r.expected_days)} scheduled working days`
                    + (r.off_schedule_days > 0 ? ` · +${dayCount(r.off_schedule_days)} worked on days off` : '')
                }
            />
            <StatTile
                label="Absent"
                value={`${dayCount(a.absent)} days`}
                delta={cmp ? deltaOf(a.absent - cmp.absent, { unit: '', goodWhen: 'down', suffix: ` vs ${cmp.month}` }) : null}
            />
            <StatTile
                label="Late arrivals"
                value={fmt.num(a.lateArrivals)}
                delta={cmp ? deltaOf(a.lateArrivals - cmp.lateArrivals, { unit: '', goodWhen: 'down', digits: 0, suffix: ` vs ${cmp.month}` }) : null}
                sub={a.lateArrivals ? `avg ${fmt.num(data.breakdown.punctuality.average_late_minutes, 0)} min past grace` : 'Everyone on time'}
            />
            <StatTile label="On leave" value={`${dayCount(a.leaves)} days`} />
            <StatTile
                label="Perfect attendance"
                value={isTeam ? fmt.num(a.perfectCount) : (a.perfectCount ? 'Yes' : 'Not yet')}
                sub={isTeam ? 'employees with no missed working day' : 'every working day attended'}
            />
            <StatTile label="Average hours" value={`${fmt.num(h.averageDaily, 1)} h`} sub={`per present day · ${fmt.num(h.totalWork, 0)} h total`} />
            <StatTile label="Overtime" value={`${fmt.num(h.overtime, 1)} h`} />
        </Grid>
    );
}

/* ── daily composition (stacked) ────────────────────────────────────────── */

function DailyComposition({ rows, isMobile, empty }) {
    return (
        <ChartCard
            title="Daily attendance"
            subtitle="Man-days per working day: on time, late, on leave and absent"
            legend={COMPOSITION.map((c) => ({ label: c.label, color: c.color }))}
            empty={empty}
            emptyText="No working days recorded for this month yet."
            table={{
                columns: [
                    { key: 'date', label: 'Date', format: (v, r) => `${fmt.date(v)} (${r.weekday})` },
                    ...COMPOSITION.map((c) => ({ key: c.key, label: c.label, align: 'right', format: dayCount })),
                    { key: 'rate', label: 'Rate', align: 'right', format: (v) => fmt.pct(v) },
                ],
                rows,
            }}
        >
            <Box style={{ width: '100%', height: 280 }}>
                <ResponsiveContainer>
                    <BarChart data={rows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barCategoryGap={isMobile ? '12%' : '22%'}>
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} interval="preserveStartEnd" minTickGap={10} />
                        <YAxis {...axisProps} axisLine={false} allowDecimals={false} width={36} />
                        <Tooltip
                            cursor={{ fill: 'var(--gray-a3)' }}
                            content={
                                <VizTooltip
                                    labelFormatter={(label, p) => `${label} (${p[0]?.payload?.weekday ?? ''})`}
                                    valueFormatter={dayCount}
                                    footer={(p) => (
                                        <Text size="1" color="gray">Rate {fmt.pct(p[0]?.payload?.rate)}</Text>
                                    )}
                                />
                            }
                        />
                        {COMPOSITION.map((c, i) => (
                            <Bar
                                key={c.key}
                                dataKey={c.key}
                                name={c.label}
                                stackId="day"
                                fill={c.color}
                                // 2px surface gap between stacked segments (secondary encoding).
                                stroke="var(--viz-surface)"
                                strokeWidth={2}
                                radius={i === COMPOSITION.length - 1 ? [4, 4, 0, 0] : 0}
                                maxBarSize={28}
                                isAnimationActive={false}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </Box>
        </ChartCard>
    );
}

/* ── daily rate line ────────────────────────────────────────────────────── */

function DailyRate({ rows, target, empty, isMobile }) {
    const points = rows.filter((r) => r.rate != null);
    const low = points.reduce((m, r) => (m == null || r.rate < m.rate ? r : m), null);

    return (
        <ChartCard
            title="Attendance rate by day"
            subtitle={low ? `Lowest: ${fmt.pct(low.rate)} on ${low.label} (${low.weekday})` : undefined}
            empty={empty || points.length === 0}
            table={{
                columns: [
                    { key: 'date', label: 'Date', format: (v, r) => `${fmt.date(v)} (${r.weekday})` },
                    { key: 'rate', label: 'Rate', align: 'right', format: (v) => fmt.pct(v) },
                ],
                rows: points,
            }}
        >
            <Box style={{ width: '100%', height: 240 }}>
                <ResponsiveContainer>
                    <LineChart data={points} margin={{ top: 12, right: 44, left: 0, bottom: 0 }}>
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} interval="preserveStartEnd" minTickGap={isMobile ? 18 : 10} />
                        <YAxis {...axisProps} axisLine={false} domain={[0, 100]} ticks={[0, 25, 50, 75, 100]} tickFormatter={(v) => `${v}%`} width={40} />
                        {target != null && <ReferenceLine
                            y={target}
                            stroke="var(--viz-axis)"
                            label={{ value: `month ${fmt.pct(target, 0)}`, position: 'right', fontSize: 10, fill: 'var(--viz-muted)' }}
                        />}
                        <Tooltip
                            cursor={{ stroke: 'var(--viz-axis)', strokeWidth: 1 }}
                            content={<VizTooltip valueFormatter={(v) => fmt.pct(v)} labelFormatter={(l, p) => `${l} (${p[0]?.payload?.weekday ?? ''})`} />}
                        />
                        <Line
                            type="monotone"
                            dataKey="rate"
                            name="Attendance rate"
                            stroke="var(--viz-s1)"
                            strokeWidth={2}
                            dot={points.length <= 12 ? { r: 3, fill: 'var(--viz-s1)', stroke: 'var(--viz-surface)', strokeWidth: 2 } : false}
                            activeDot={{ r: 5, stroke: 'var(--viz-surface)', strokeWidth: 2 }}
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </Box>
        </ChartCard>
    );
}

/* ── punctuality distribution ───────────────────────────────────────────── */

function Punctuality({ punctuality, late }) {
    const rows = punctuality.buckets;

    return (
        <ChartCard
            title="How late"
            subtitle={late ? `${fmt.num(late)} late arrivals · ${fmt.num(punctuality.total_late_minutes)} minutes past grace in total` : undefined}
            empty={!late}
            emptyText="No late arrivals this month."
            table={{
                columns: [
                    { key: 'bucket', label: 'Minutes late' },
                    { key: 'count', label: 'Arrivals', align: 'right' },
                ],
                rows,
            }}
        >
            <HBarList
                color="var(--viz-late)"
                rows={rows.map((r) => ({
                    key: r.bucket,
                    label: r.bucket,
                    value: r.count,
                    display: `${fmt.num(r.count)} · ${fmt.pct(late ? (r.count / late) * 100 : 0, 0)}`,
                }))}
            />
        </ChartCard>
    );
}

/* ── weekday small multiples ────────────────────────────────────────────── */

// Late and absent are shown as two single-series charts rather than one grouped
// chart: in dark mode the late/absent pair fails the normal-vision separation
// floor, so they must not share a plot.
function WeekdayBars({ title, rows, dataKey, color, name, empty, emptyText }) {
    const shown = rows.filter((r) => r.expected > 0);

    return (
        <ChartCard
            title={title}
            empty={empty || shown.length === 0}
            emptyText={emptyText}
            table={{
                columns: [
                    { key: 'day', label: 'Day' },
                    { key: dataKey, label: name, align: 'right', format: dayCount },
                    { key: 'rate', label: 'Attendance rate', align: 'right', format: (v) => fmt.pct(v) },
                ],
                rows: shown,
            }}
        >
            <Box style={{ width: '100%', height: 200 }}>
                <ResponsiveContainer>
                    <BarChart data={shown} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barCategoryGap="30%">
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="day" {...axisProps} />
                        <YAxis {...axisProps} axisLine={false} allowDecimals={false} width={32} />
                        <Tooltip
                            cursor={{ fill: 'var(--gray-a3)' }}
                            content={
                                <VizTooltip
                                    valueFormatter={dayCount}
                                    footer={(p) => <Text size="1" color="gray">Attendance {fmt.pct(p[0]?.payload?.rate)}</Text>}
                                />
                            }
                        />
                        <Bar dataKey={dataKey} name={name} fill={color} radius={[4, 4, 0, 0]} maxBarSize={32} isAnimationActive={false} />
                    </BarChart>
                </ResponsiveContainer>
            </Box>
        </ChartCard>
    );
}

/* ── departments ────────────────────────────────────────────────────────── */

function Departments({ rows }) {
    return (
        <ChartCard
            title="Attendance by department"
            subtitle="Lowest rate first"
            table={{
                columns: [
                    { key: 'department', label: 'Department' },
                    { key: 'employees', label: 'Staff', align: 'right' },
                    { key: 'rate', label: 'Rate', align: 'right', format: (v) => fmt.pct(v) },
                    { key: 'absent', label: 'Absent days', align: 'right', format: dayCount },
                    { key: 'late', label: 'Late', align: 'right' },
                    { key: 'late_rate', label: 'Late share', align: 'right', format: (v) => fmt.pct(v) },
                    { key: 'leave', label: 'Leave days', align: 'right', format: dayCount },
                ],
                rows,
            }}
        >
            <HBarList
                max={100}
                rows={rows.map((d) => ({
                    key: d.department,
                    label: d.department,
                    value: d.rate ?? 0,
                    display: fmt.pct(d.rate),
                    sub: `${d.employees} staff · ${dayCount(d.absent)} absent days · ${fmt.num(d.late)} late`,
                }))}
            />
        </ChartCard>
    );
}

/* ── watch list ─────────────────────────────────────────────────────────── */

function Watchlist({ watchlist }) {
    const lists = [
        { key: 'most_late', title: 'Most late arrivals', value: (p) => `${fmt.num(p.late)} · ${fmt.num(p.late_minutes)} min` },
        { key: 'most_absent', title: 'Most absent days', value: (p) => dayCount(p.absent) },
        { key: 'lowest_rate', title: 'Lowest attendance rate', value: (p) => fmt.pct(p.rate) },
    ];

    return (
        <Grid columns={{ initial: '1', lg: '3' }} gap="4">
            {lists.map((l) => (
                <ChartCard key={l.key} title={l.title} empty={!watchlist[l.key]?.length} emptyText="Nobody to flag this month.">
                    <DataTable
                        caption={l.title}
                        rows={watchlist[l.key] ?? []}
                        columns={[
                            { key: 'name', label: 'Employee', format: (v, r) => <Box><Text size="2">{v}</Text><Text as="div" size="1" color="gray">{r.department}</Text></Box> },
                            { key: 'value', label: '', align: 'right', format: (_, r) => l.value(r) },
                        ]}
                    />
                </ChartCard>
            ))}
        </Grid>
    );
}
