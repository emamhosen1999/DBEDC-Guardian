import React from 'react';
import { Flex, Box, Heading, Text, Badge, Grid } from '@radix-ui/themes';
import {
    ComposedChart, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
    AreaChart, Area, RadialBarChart, RadialBar, PolarAngleAxis, Cell, LabelList,
} from 'recharts';
import {
    CommandCard, SectionLabel, Kpi, StatRow, TONE, SERIES, MONO, R,
    fmtNum, fmtCr, chLabel, tooltipStyle,
} from './kit.jsx';

const ROAD_KM = 48;
const ic = (path) => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
        strokeLinecap="round" strokeLinejoin="round">{path}</svg>
);

/* ══════════════════════════ KPI BAND ═══════════════════════════════ */
export function KpiBand({ kpis = {}, quality = {} }) {
    return (
        <Grid columns={{ initial: '2', sm: '3', lg: '6' }} gap="3">
            <Kpi tone="accent" label={<>Physical<br />progress</>} value={kpis.progress ?? '—'} unit="%"
                icon={ic(<><path d="M3 12h4l3-8 4 16 3-8h4" /></>)}
                />
            <Kpi tone="info" label={<>RFIs<br />logged</>} value={fmtNum(kpis.rfi_total)}
                icon={ic(<><path d="M9 11l3 3 8-8" /><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9" /></>)}
                foot={<Text size="1" style={{ fontFamily: MONO, color: 'var(--gray-11)' }}>{fmtNum(kpis.rfi_recent)} · 30d</Text>} />
            <Kpi tone="good" label={<>First-pass<br />rate</>} value={quality.first_pass_rate ?? kpis.first_pass_rate ?? '—'} unit="%"
                icon={ic(<><path d="M22 11.1V12a10 10 0 1 1-5.9-9.1" /><path d="M22 4 12 14.01l-3-3" /></>)}
                />
            <Kpi tone="crit" label={<>Open<br />NCRs</>} value={kpis.ncr_open ?? '—'}
                icon={ic(<><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" /></>)}
                foot={<Text size="1" style={{ fontFamily: MONO, color: 'var(--gray-11)' }}>of {kpis.ncr_total} issued</Text>} />
            <Kpi tone="design" label={<>Open site<br />instructions</>} value={kpis.si_open ?? '—'}
                icon={ic(<><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><path d="M14 2v6h6M9 15h6" /></>)}
                foot={<Text size="1" style={{ fontFamily: MONO, color: 'var(--gray-11)' }}>IE register</Text>} />
            <Kpi tone="warn" label={<>Budget<br />utilized</>} value={kpis.budget_utilization ?? '—'} unit="%"
                icon={ic(<><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" /></>)}
                foot={<Text size="1" style={{ fontFamily: MONO, color: 'var(--gray-11)' }}>{fmtCr(kpis.budget_spent_cr)}</Text>}
                />
        </Grid>
    );
}

/* ══════════════════════════ RFI THROUGHPUT ═════════════════════════ */
export function RfiThroughput({ data = [] }) {
    return (
        <CommandCard title="RFI Throughput" sub="requests for inspection · monthly disposition" minHeight={300}
            right={<Flex gap="3" style={{ fontFamily: MONO, fontSize: 11, color: 'var(--gray-11)' }}>
                <Lg c={SERIES.completed} t="Approved" /><Lg c={SERIES.resubmission} t="Resubmitted" /><Lg c={SERIES.new} t="Pending" />
            </Flex>}>
            <ResponsiveContainer width="100%" height={240}>
                <BarChart data={data} margin={{ top: 18, right: 8, left: -18, bottom: 0 }} barCategoryGap="24%">
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--gray-a4)" vertical={false} />
                    <XAxis dataKey="label" tick={{ fontSize: 11, fill: 'var(--gray-10)', fontFamily: MONO }} axisLine={false} tickLine={false} />
                    <YAxis tick={{ fontSize: 11, fill: 'var(--gray-10)', fontFamily: MONO }} axisLine={false} tickLine={false} />
                    <Tooltip contentStyle={tooltipStyle} cursor={{ fill: 'var(--gray-a3)' }} />
                    <Bar dataKey="completed" name="Approved" stackId="a" fill={SERIES.completed} radius={[0, 0, 0, 0]} />
                    <Bar dataKey="resubmission" name="Resubmitted" stackId="a" fill={SERIES.resubmission} />
                    <Bar dataKey="new" name="Pending" stackId="a" fill={SERIES.new} radius={[4, 4, 0, 0]}>
                        <LabelList dataKey="approval" position="top" formatter={(v) => `${v}%`}
                            style={{ fontFamily: MONO, fontSize: 10, fill: 'var(--gray-11)', fontWeight: 700 }} />
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
            <Text size="1" color="gray" style={{ fontFamily: MONO }}>% = approval rate of dispositioned RFIs that month</Text>
        </CommandCard>
    );
}

/* ══════════════════════════ QUALITY GAUGE ══════════════════════════ */
export function QualitySignal({ quality = {} }) {
    const rate = quality.first_pass_rate ?? 0;
    const data = [{ name: 'first-pass', value: rate, fill: 'var(--jade-9)' }];
    return (
        <CommandCard title="First-Pass Quality" sub="RFIs cleared without resubmission" minHeight={300}>
            <Box style={{ position: 'relative', height: 168 }}>
                <ResponsiveContainer width="100%" height="100%">
                    <RadialBarChart innerRadius="72%" outerRadius="100%" data={data} startAngle={90} endAngle={-270}>
                        <PolarAngleAxis type="number" domain={[0, 100]} tick={false} />
                        <RadialBar background={{ fill: 'var(--gray-a4)' }} dataKey="value" cornerRadius={20} />
                    </RadialBarChart>
                </ResponsiveContainer>
                <Flex direction="column" align="center" justify="center" style={{ position: 'absolute', inset: 0 }}>
                    <Text style={{ fontFamily: MONO, fontWeight: 700, fontSize: 30, color: 'var(--jade-11)' }}>{rate}%</Text>
                    <Text size="1" color="gray" style={{ fontFamily: MONO, textTransform: 'uppercase', letterSpacing: '0.1em' }}>first pass</Text>
                </Flex>
            </Box>
            <Box mt="2">
                <StatRow dot="var(--jade-9)" name="Cleared first time" value={fmtNum(quality.first_pass)} />
                <StatRow dot="var(--amber-9)" name="Needed resubmission" value={fmtNum(quality.resubmitted)} />
                <StatRow dot="var(--iris-9)" name="Inspections passed / failed" value={`${fmtNum(quality.inspection_pass)} / ${quality.inspection_fail}`} />
            </Box>
        </CommandCard>
    );
}

/* ══════════════════════════ DISCIPLINE MIX ═════════════════════════ */
export function DisciplineMix({ data = [] }) {
    const max = Math.max(1, ...data.map((d) => d.total));
    return (
        <CommandCard title="Works by Discipline" sub="RFI volume & completion" minHeight={220}>
            <Flex direction="column" gap="3" mt="1">
                {data.map((d) => (
                    <Box key={d.name}>
                        <Flex justify="between" mb="1">
                            <Text size="2" weight="medium">{d.name}</Text>
                            <Text size="1" style={{ fontFamily: MONO, color: 'var(--gray-11)' }}>{fmtNum(d.total)} · {d.rate}%</Text>
                        </Flex>
                        <Box style={{ height: 16, borderRadius: R(5), background: 'var(--gray-a4)', overflow: 'hidden', position: 'relative' }}>
                            <Box style={{ width: `${(d.total / max) * 100}%`, height: '100%', background: 'var(--blue-a5)' }} />
                            <Box style={{ position: 'absolute', top: 0, left: 0, height: '100%',
                                width: `${(d.completed / max) * 100}%`, background: 'var(--jade-9)', borderRadius: R(5) }} />
                        </Box>
                    </Box>
                ))}
            </Flex>
            <Flex gap="4" mt="3" pt="3" style={{ borderTop: '1px solid var(--gray-a3)', fontFamily: MONO, fontSize: 11, color: 'var(--gray-11)' }}>
                <Lg c="var(--jade-9)" t="completed" /><Lg c="var(--blue-a5)" t="total raised" />
            </Flex>
        </CommandCard>
    );
}

/* ══════════════════════════ NCR REGISTER ═══════════════════════════ */
export function NcrPanel({ ncr = {} }) {
    const sev = ncr.severity || {};
    const funnel = [
        ['Open', ncr.open, 'var(--tomato-9)'],
        ['In process for submission', ncr.in_process, 'var(--amber-9)'],
        ['Under IE review', ncr.under_review, 'var(--blue-9)'],
        ['IE-recommended (RHD consent)', ncr.consent, 'var(--jade-9)'],
    ];
    return (
        <CommandCard title="Non-Conformance (NCR)" sub={`${ncr.issued} issued · ${ncr.closed} closed`} minHeight={220}
            right={<Badge color="tomato" variant="soft">{ncr.open} open</Badge>}>
            <Flex gap="2" mb="3">
                {[['critical', sev.critical, 'var(--tomato-9)'], ['major', sev.major, 'var(--amber-9)'], ['minor', sev.minor, 'var(--iris-9)']].map(([k, v, c]) => (
                    <Box key={k} style={{ flex: 1, borderRadius: R(8), padding: '8px 10px', background: 'var(--gray-a2)', border: '1px solid var(--gray-a4)' }}>
                        <Text style={{ fontFamily: MONO, fontWeight: 700, fontSize: 20, color: c }}>{v ?? 0}</Text>
                        <Text size="1" color="gray" style={{ display: 'block', fontFamily: MONO, textTransform: 'capitalize' }}>{k}</Text>
                    </Box>
                ))}
            </Flex>
            {funnel.map(([label, val, color]) => (
                <StatRow key={label} dot={color} name={label} value={val ?? 0} />
            ))}
        </CommandCard>
    );
}

/* ══════════════════════════ SITE INSTRUCTIONS ══════════════════════ */
export function SiPanel({ si = {} }) {
    const deptColor = { 'Quality Control': 'var(--jade-9)', Pavement: 'var(--amber-9)', Structure: 'var(--iris-9)' };
    return (
        <CommandCard title="Site Instructions (SI)" sub={`${si.issued} issued · ${si.closed} closed`} minHeight={220}
            right={<Badge color="amber" variant="soft">{si.open} open</Badge>}>
            <Flex gap="2" mb="3">
                {(si.by_department || []).map((d) => (
                    <Box key={d.name} style={{ flex: 1, borderRadius: R(8), padding: '8px 10px', background: 'var(--gray-a2)', border: '1px solid var(--gray-a4)' }}>
                        <Text style={{ fontFamily: MONO, fontWeight: 700, fontSize: 20, color: deptColor[d.name] || 'var(--gray-11)' }}>{d.count}</Text>
                        <Text size="1" color="gray" style={{ display: 'block', fontFamily: MONO }}>{d.name}</Text>
                    </Box>
                ))}
            </Flex>
            <Box style={{ maxHeight: 168, overflowY: 'auto' }}>
                {(si.items || []).map((s) => (
                    <StatRow key={s.si_number} dot={deptColor[s.department] || 'var(--gray-9)'}
                        name={<Text size="2" style={{ display: 'block', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.description}</Text>}
                        sub={`${s.si_number} · ${s.location || 'project-wide'}`} />
                ))}
            </Box>
        </CommandCard>
    );
}

/* ══════════════════════════ OBJECTION HOTSPOTS ═════════════════════ */
export function ObjectionHotspots({ objections = {} }) {
    const points = objections.points || [];
    const max = Math.max(1, ...points.map((p) => p.count));
    const W = 680, H = 74, y = 40;
    return (
        <CommandCard title="Objection Hotspots" sub={`${objections.count} chainage objections mapped along the road`} minHeight={160}
            right={<Badge color="amber" variant="soft">{points.length} locations</Badge>}>
            <Box style={{ overflowX: 'auto' }}>
                <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ minWidth: 520 }}>
                    <line x1="12" y1={y} x2={W - 12} y2={y} stroke="var(--gray-a6)" strokeWidth="2" strokeLinecap="round" />
                    {Array.from({ length: 7 }, (_, i) => i * 8).map((km) => {
                        const x = 12 + (km / ROAD_KM) * (W - 24);
                        return (<g key={km}>
                            <line x1={x} y1={y - 4} x2={x} y2={y + 4} stroke="var(--gray-a7)" />
                            <text x={x} y={y + 18} textAnchor="middle" fill="var(--gray-10)" fontSize="10" fontFamily="monospace">{km}</text>
                        </g>);
                    })}
                    {points.map((p) => {
                        const x = 12 + (p.km / ROAD_KM) * (W - 24);
                        const r = 4 + (p.count / max) * 9;
                        return (<g key={p.km}>
                            <circle cx={x} cy={y} r={r} fill="var(--amber-a5)" stroke="var(--amber-9)" strokeWidth="2">
                                <title>{`${chLabel(p.km)} · ${p.count} objections`}</title>
                            </circle>
                            <circle cx={x} cy={y} r="2" fill="var(--amber-11)" />
                        </g>);
                    })}
                </svg>
            </Box>
        </CommandCard>
    );
}

/* ══════════════════════════ BUDGET BURN-DOWN ═══════════════════════ */
export function BudgetBurndown({ budget = {} }) {
    const data = budget.series || [];
    return (
        <CommandCard title="Budget Burn-down" sub="cumulative certified vs planned · ৳ crore" minHeight={300}
            right={<Flex gap="3" style={{ fontFamily: MONO, fontSize: 11, color: 'var(--gray-11)' }}>
                <Lg c="var(--blue-9)" t="Planned" /><Lg c="var(--accent-9)" t="Certified" />
            </Flex>}>
            <ResponsiveContainer width="100%" height={230}>
                <AreaChart data={data} margin={{ top: 10, right: 10, left: -6, bottom: 0 }}>
                    <defs>
                        <linearGradient id="ccCert" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="var(--accent-9)" stopOpacity="0.28" />
                            <stop offset="100%" stopColor="var(--accent-9)" stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--gray-a4)" vertical={false} />
                    <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--gray-10)', fontFamily: MONO }} axisLine={false} tickLine={false} interval={4} />
                    <YAxis tick={{ fontSize: 10, fill: 'var(--gray-10)', fontFamily: MONO }} axisLine={false} tickLine={false} tickFormatter={(v) => `৳${v}`} />
                    <Tooltip contentStyle={tooltipStyle} formatter={(v, n) => [`৳${fmtNum(v)} Cr`, n]} />
                    <Area type="monotone" dataKey="planned" name="Planned" stroke="var(--blue-9)" strokeWidth={2} strokeDasharray="6 5" fill="none" dot={false} />
                    <Area type="monotone" dataKey="certified" name="Certified" stroke="var(--accent-9)" strokeWidth={2.4} fill="url(#ccCert)" dot={false} />
                </AreaChart>
            </ResponsiveContainer>
        </CommandCard>
    );
}

/* ══════════════════════════ MILESTONE / WORK PACKAGES ══════════════ */
export function WorkPackages({ milestones = [] }) {
    const cmap = { completed: 'var(--jade-9)', in_progress: 'var(--amber-9)', not_started: 'var(--blue-9)', behind: 'var(--tomato-9)' };
    const smap = { completed: 'On track', in_progress: 'In progress', not_started: 'Not started', behind: 'Behind' };
    return (
        <CommandCard title="Work Packages & Milestones" sub="programme against SPCD" minHeight={300}
            right={<Badge color="gray" variant="soft">{milestones.length} packages</Badge>}>
            <Flex direction="column" gap="3">
                {milestones.map((m) => (
                    <Grid key={m.name} columns="180px 1fr" gap="3" align="center">
                        <Box style={{ minWidth: 0 }}>
                            <Text size="2" style={{ display: 'block', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{m.name}</Text>
                            <Text size="1" color="gray" style={{ fontFamily: MONO }}>{m.description}</Text>
                        </Box>
                        <Flex align="center" gap="3">
                            <Box style={{ flex: 1, height: 18, borderRadius: R(5), background: 'var(--gray-a4)', overflow: 'hidden' }}>
                                <Box style={{ width: `${m.progress}%`, height: '100%', background: cmap[m.status] || 'var(--gray-9)',
                                    borderRadius: R(5), transition: 'width .8s cubic-bezier(.2,.7,.3,1)' }} />
                            </Box>
                            <Text style={{ fontFamily: MONO, fontSize: 11, fontWeight: 700, width: 34, textAlign: 'right' }}>{m.progress}%</Text>
                        </Flex>
                    </Grid>
                ))}
            </Flex>
            <Flex gap="4" mt="3" pt="3" style={{ borderTop: '1px solid var(--gray-a3)', fontFamily: MONO, fontSize: 11, color: 'var(--gray-11)', flexWrap: 'wrap' }}>
                {Object.entries(smap).map(([k, v]) => <Lg key={k} c={cmap[k]} t={v} />)}
            </Flex>
        </CommandCard>
    );
}
