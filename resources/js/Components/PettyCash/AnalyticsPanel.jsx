/**
 * Petty cash analytics for one fund.
 *
 * One range filter above everything it scopes; the previous render stays on
 * screen (dimmed) while a new range loads, so nothing jumps. Every chart has a
 * table view, and money is always shown as text beside its mark, never only in
 * a tooltip.
 */
import React, { useMemo } from 'react';
import { Badge, Box, Callout, Flex, Grid, SegmentedControl, Text } from '@radix-ui/themes';
import { CheckCircledIcon, ExclamationTriangleIcon, InfoCircledIcon } from '@radix-ui/react-icons';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import axios from 'axios';
import {
    Area, AreaChart, Bar, BarChart, CartesianGrid, ReferenceLine,
    ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import {
    ChartCard, DataTable, HBarList, StatTile, VizRoot, VizTooltip,
    axisProps, deltaOf, fmt, gridProps,
} from '@/Components/Charts/VizKit';

const RANGES = [
    { value: 'all', label: 'All time' },
    { value: 'mtd', label: 'This month' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: '12m', label: '12 months' },
];

const FLOW_SERIES = [
    { key: 'expenses', label: 'Expenses', color: 'var(--viz-s1)' },
    { key: 'reimbursements', label: 'Reimbursements', color: 'var(--viz-s2)' },
    { key: 'repayments', label: 'Repayments', color: 'var(--viz-s3)' },
];

export default function AnalyticsPanel({ loanId, isMobile }) {
    // The range lives in the URL (prefix `pa_`) so it survives leaving the tab.
    const f = useQueryFilters({ mode: 'client', debounceKeys: [], defaults: { pa_range: 'all' } });
    const range = RANGES.some((r) => r.value === f.values.pa_range) ? f.values.pa_range : 'all';

    const { data, isLoading, isFetching, isError, refetch } = useQuery({
        queryKey: ['petty-cash-analytics', loanId, range],
        queryFn: async () => (await axios.get('/petty-cash/analytics', { params: { loan_id: loanId, range } })).data.analytics,
        enabled: Boolean(loanId),
        placeholderData: keepPreviousData,
        staleTime: 60_000,
    });

    if (!loanId) {
        return <Empty text="Select a fund to see its analytics." />;
    }

    return (
        <VizRoot stale={isFetching && !isLoading}>
            <Flex justify="between" align="center" gap="3" wrap="wrap" mb="4">
                <Box>
                    <Text as="div" size="4" weight="bold">Fund analytics</Text>
                    {data && (
                        <Text as="div" size="1" color="gray">
                            {fmt.date(data.range.from)} – {fmt.date(data.range.to)} · {data.range.days} days
                        </Text>
                    )}
                </Box>
                <SegmentedControl.Root
                    size={isMobile ? '1' : '2'}
                    value={range}
                    onValueChange={(v) => f.set('pa_range', v)}
                    aria-label="Analytics period"
                >
                    {RANGES.map((r) => (
                        <SegmentedControl.Item key={r.value} value={r.value}>{r.label}</SegmentedControl.Item>
                    ))}
                </SegmentedControl.Root>
            </Flex>

            {isLoading ? (
                <Empty text="Loading analytics…" />
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
    const s = data.summary;
    const hasExpenses = s.expense_count > 0;

    return (
        <Flex direction="column" gap="4">
            <Headline data={data} />

            <Grid columns={{ initial: '1', lg: '2' }} gap="4">
                <BalanceChart data={data} />
                <MonthlyFlows data={data} isMobile={isMobile} />
            </Grid>

            <Grid columns={{ initial: '1', lg: '2' }} gap="4">
                <CategoryBreakdown data={data} hasExpenses={hasExpenses} />
                <WeekdaySpend data={data} hasExpenses={hasExpenses} />
            </Grid>

            <LargestExpenses data={data} hasExpenses={hasExpenses} />
        </Flex>
    );
}

/* ── headline tiles ─────────────────────────────────────────────────────── */

function Headline({ data }) {
    const s = data.summary;
    const cmp = data.comparison;
    const coverage = s.bills.coverage_pct;

    return (
        <Grid columns={{ initial: '2', sm: '3', lg: '6' }} gap="3">
            <StatTile
                label="Current balance"
                value={fmt.money(s.current_balance)}
                sub={`of ${fmt.money(s.funded)} funded · ${fmt.pct(s.utilization_pct, 0)} used`}
            />
            <StatTile
                label="Spent"
                value={fmt.money(s.spent)}
                delta={cmp?.spent_change_pct != null
                    ? deltaOf(cmp.spent_change_pct, { goodWhen: 'down', suffix: ' vs previous period' })
                    : null}
                sub={`${s.expense_count} expense${s.expense_count === 1 ? '' : 's'}`}
            />
            <StatTile
                label="Net spend"
                value={fmt.money(s.net_spend)}
                sub={`${fmt.money(s.reimbursed)} reimbursed · ${fmt.money(s.repaid)} repaid`}
            />
            <StatTile
                label="Runway"
                value={s.runway_days == null ? '—' : `${fmt.num(s.runway_days)} days`}
                sub={s.runway_days == null
                    ? 'No spending in the last 30 days'
                    : `at ${fmt.money(s.recent_daily_spend)}/day (last 30 days)`}
            />
            <StatTile
                label="Average expense"
                value={fmt.money(s.avg_expense)}
                sub={`largest ${fmt.money(s.largest_expense)}`}
            />
            <StatTile
                label="Bill coverage"
                value={coverage == null ? '—' : fmt.pct(coverage, 0)}
                sub={
                    coverage == null ? 'No expenses in this period' : (
                        <Flex as="span" align="center" gap="1">
                            {s.bills.without > 0
                                ? <><ExclamationTriangleIcon aria-hidden="true" /> {s.bills.without} without a bill</>
                                : <><CheckCircledIcon aria-hidden="true" /> Every expense has a bill</>}
                        </Flex>
                    )
                }
            />
        </Grid>
    );
}

/* ── balance over time ──────────────────────────────────────────────────── */

function BalanceChart({ data }) {
    const points = useMemo(
        () => data.balance_timeline.map((p) => ({ ...p, t: new Date(`${p.date}T00:00:00`).getTime() })),
        [data.balance_timeline],
    );
    const min = Math.min(...points.map((p) => p.balance));
    const last = points[points.length - 1];

    return (
        <ChartCard
            title="Balance over time"
            subtitle={`Opened the period at ${fmt.money(data.summary.opening_balance)}, closed at ${fmt.money(data.summary.closing_balance)}`}
            empty={points.length < 2}
            table={{
                columns: [
                    { key: 'date', label: 'Date', format: fmt.date },
                    { key: 'balance', label: 'Balance', align: 'right', format: fmt.money2 },
                ],
                rows: points,
            }}
        >
            <Box style={{ width: '100%', height: 260 }}>
                <ResponsiveContainer>
                    <AreaChart data={points} margin={{ top: 16, right: 56, left: 4, bottom: 0 }}>
                        <CartesianGrid {...gridProps} />
                        <XAxis
                            dataKey="t"
                            type="number"
                            scale="time"
                            domain={['dataMin', 'dataMax']}
                            {...axisProps}
                            tickFormatter={(t) => new Date(t).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}
                            minTickGap={32}
                        />
                        <YAxis {...axisProps} axisLine={false} tickFormatter={fmt.moneyShort} width={56} />
                        {min < 0 && <ReferenceLine y={0} stroke="var(--viz-axis)" />}
                        <Tooltip
                            cursor={{ stroke: 'var(--viz-axis)', strokeWidth: 1 }}
                            content={<VizTooltip labelFormatter={(t) => new Date(t).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} valueFormatter={fmt.money2} />}
                        />
                        <Area
                            type="stepAfter"
                            dataKey="balance"
                            name="Balance"
                            stroke="var(--viz-s1)"
                            strokeWidth={2}
                            fill="var(--viz-s1)"
                            fillOpacity={0.1}
                            dot={false}
                            activeDot={{ r: 4, stroke: 'var(--viz-surface)', strokeWidth: 2 }}
                            isAnimationActive={false}
                            label={({ x, y, index }) => (index === points.length - 1 ? (
                                <text x={x + 8} y={y} dy={4} fontSize={11} fontWeight={600} fill="var(--viz-ink)">
                                    {fmt.moneyShort(last.balance)}
                                </text>
                            ) : null)}
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </Box>
        </ChartCard>
    );
}

/* ── monthly flows ──────────────────────────────────────────────────────── */

function MonthlyFlows({ data, isMobile }) {
    const rows = data.monthly;
    const empty = rows.every((r) => !r.expenses && !r.reimbursements && !r.repayments);

    return (
        <ChartCard
            title="Monthly flows"
            subtitle="Money out (expenses, repayments) and back in (reimbursements), by month"
            legend={FLOW_SERIES.map((s) => ({ label: s.label, color: s.color }))}
            empty={empty}
            table={{
                columns: [
                    { key: 'label', label: 'Month' },
                    ...FLOW_SERIES.map((s) => ({ key: s.key, label: s.label, align: 'right', format: fmt.money })),
                    { key: 'count', label: 'Transactions', align: 'right' },
                ],
                rows,
            }}
        >
            <Box style={{ width: '100%', height: 260 }}>
                <ResponsiveContainer>
                    <BarChart data={rows} margin={{ top: 8, right: 8, left: 4, bottom: 0 }} barGap={2} barCategoryGap="24%">
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="label" {...axisProps} interval={isMobile ? 'preserveStartEnd' : 0} minTickGap={8} />
                        <YAxis {...axisProps} axisLine={false} tickFormatter={fmt.moneyShort} width={56} />
                        <Tooltip
                            cursor={{ fill: 'var(--gray-a3)' }}
                            content={<VizTooltip valueFormatter={fmt.money2} />}
                        />
                        {FLOW_SERIES.map((s) => (
                            <Bar
                                key={s.key}
                                dataKey={s.key}
                                name={s.label}
                                fill={s.color}
                                radius={[4, 4, 0, 0]}
                                maxBarSize={22}
                                isAnimationActive={false}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </Box>
        </ChartCard>
    );
}

/* ── category breakdown ─────────────────────────────────────────────────── */

function CategoryBreakdown({ data, hasExpenses }) {
    const cats = data.categories;
    const rows = useMemo(() => cats.map((c) => ({
        key: c.key,
        label: c.label,
        value: c.amount,
        display: `${fmt.money(c.amount)} · ${fmt.pct(c.share_pct, 0)}`,
        sub: `${c.count} expense${c.count === 1 ? '' : 's'} · avg ${fmt.money(c.average)}`,
    })), [cats]);

    return (
        <ChartCard
            title="Spend by category"
            subtitle="Ranked by amount; share of all expenses in the period"
            empty={!hasExpenses}
            emptyText="No expenses in this period."
            table={{
                columns: [
                    { key: 'label', label: 'Category' },
                    { key: 'amount', label: 'Amount', align: 'right', format: fmt.money2 },
                    { key: 'share_pct', label: 'Share', align: 'right', format: (v) => fmt.pct(v) },
                    { key: 'count', label: 'Expenses', align: 'right' },
                    { key: 'average', label: 'Average', align: 'right', format: fmt.money },
                ],
                rows: cats,
            }}
        >
            <HBarList
                rows={rows}
                limit={7}
                formatOther={(value) => fmt.money(value)}
            />
        </ChartCard>
    );
}

/* ── weekday pattern ────────────────────────────────────────────────────── */

function WeekdaySpend({ data, hasExpenses }) {
    const rows = data.weekday;
    const peak = rows.reduce((best, r) => (r.amount > (best?.amount ?? -1) ? r : best), null);

    return (
        <ChartCard
            title="Spend by day of week"
            subtitle={peak && peak.amount > 0 ? `Most spending lands on ${peak.day}` : undefined}
            empty={!hasExpenses}
            emptyText="No expenses in this period."
            table={{
                columns: [
                    { key: 'day', label: 'Day' },
                    { key: 'amount', label: 'Amount', align: 'right', format: fmt.money2 },
                    { key: 'count', label: 'Expenses', align: 'right' },
                ],
                rows,
            }}
        >
            <Box style={{ width: '100%', height: 240 }}>
                <ResponsiveContainer>
                    <BarChart data={rows} margin={{ top: 20, right: 8, left: 4, bottom: 0 }} barCategoryGap="28%">
                        <CartesianGrid {...gridProps} />
                        <XAxis dataKey="day" {...axisProps} />
                        <YAxis {...axisProps} axisLine={false} tickFormatter={fmt.moneyShort} width={56} />
                        <Tooltip
                            cursor={{ fill: 'var(--gray-a3)' }}
                            content={
                                <VizTooltip
                                    valueFormatter={fmt.money2}
                                    footer={(p) => (
                                        <Text size="1" color="gray">{p[0]?.payload?.count ?? 0} expense(s)</Text>
                                    )}
                                />
                            }
                        />
                        <Bar
                            dataKey="amount"
                            name="Spent"
                            fill="var(--viz-s1)"
                            radius={[4, 4, 0, 0]}
                            maxBarSize={36}
                            isAnimationActive={false}
                            label={({ x, y, width, index }) => (rows[index] === peak && peak.amount > 0 ? (
                                <text x={x + width / 2} y={y - 6} textAnchor="middle" fontSize={11} fontWeight={600} fill="var(--viz-ink)">
                                    {fmt.moneyShort(peak.amount)}
                                </text>
                            ) : null)}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </Box>
        </ChartCard>
    );
}

/* ── largest expenses ───────────────────────────────────────────────────── */

function LargestExpenses({ data, hasExpenses }) {
    const rows = data.top_expenses;
    if (!hasExpenses) return null;

    return (
        <ChartCard title="Largest expenses" subtitle="The five biggest single expenses in the period">
            <DataTable
                caption="Largest expenses"
                rows={rows}
                columns={[
                    { key: 'date', label: 'Date', format: fmt.date },
                    { key: 'category', label: 'Category' },
                    { key: 'description', label: 'Description', format: (v) => <Text truncate style={{ maxWidth: 320, display: 'inline-block' }}>{v || '—'}</Text> },
                    { key: 'amount', label: 'Amount', align: 'right', format: fmt.money2 },
                    {
                        key: 'has_bill',
                        label: 'Bill',
                        format: (v) => (v
                            ? <Badge color="green" variant="soft"><CheckCircledIcon aria-hidden="true" /> Attached</Badge>
                            : <Badge color="amber" variant="soft"><InfoCircledIcon aria-hidden="true" /> Missing</Badge>),
                    },
                ]}
            />
        </ChartCard>
    );
}

function Empty({ text }) {
    return (
        <Flex align="center" justify="center" p="6">
            <Text color="gray">{text}</Text>
        </Flex>
    );
}
