import { Head } from '@inertiajs/react';
import React from 'react';
import { Text, Heading, Skeleton, Button, Badge } from '@radix-ui/themes';

import App from '@/Layouts/App.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PageHeader from '@/Components/PageHeader';
import { useCommandData, MONO, R, CommandCard, SectionLabel } from '@/Components/Dashboard/Command/kit.jsx';
import { ProjectHero, OperationsFeed, WorkforceTrend, TodayPanel } from '@/Components/Dashboard/Command/Widgets.jsx';

/* Static TMC / patrol snapshot shown on the command center (unchanged data). */
const TRAFFIC_SECTIONS = [
    { name: 'Ch 0-10 Joydevpur - Bhulta', status: 'FREE FLOW (78.5 km/h)', color: 'green', meta: '1,840 veh/h · 1 WIM Overload' },
    { name: 'Ch 10-20 Bhulta - Kanchan', status: 'MODERATE (68.2 km/h)', color: 'amber', meta: '2,420 veh/h · 4 WIM Overload' },
    { name: 'Ch 20-35 Kanchan - Debogram', status: 'FREE FLOW (74.0 km/h)', color: 'green', meta: '1,950 veh/h · 2 WIM Overload' },
    { name: 'Ch 35-48 Debogram - Madanpur', status: 'CONGESTED (52.0 km/h)', color: 'red', meta: '2,890 veh/h · 9 WIM Overload' },
];
const PATROL_INCIDENTS = [
    { title: 'INC-2026-001 · Stalled Truck', color: 'blue', meta: 'Ch 14+200 SB · Patrol Unit 2' },
    { title: 'INC-2026-002 · Debris on Road', color: 'amber', meta: 'Ch 28+500 NB · Patrol Unit 1' },
    { title: 'INC-2026-003 · Overload Alert', color: 'red', meta: 'Ch 39+800 SB · Weighbridge Unit 3' },
];

function greeting() {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

/* Status chips from the command-center payload only — a chip is dropped when
   its value is not in the payload (PageHeader skips null/undefined). */
function statusChips(data) {
    if (!data) return [];
    const series = data.workforce?.series || [];
    const lastDay = series.length ? series[series.length - 1].label : null;
    const today = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    const projectAccess = data.access?.project !== false;
    const ncrOpen = data.ncr?.open;
    return [
        // The service reports the latest attendance day up to today; say which day when it is not today.
        { value: lastDay ? (lastDay === today ? data.workforce.present_today : `${data.workforce.present_today} · ${lastDay}`) : null, label: 'Present', tone: 'success', title: lastDay ? `Employees present on ${lastDay}` : undefined },
        { value: data.today?.on_leave, label: 'On leave', tone: 'warning' },
        { value: data.workforce?.total, label: 'Staff', tone: 'default' },
        projectAccess ? { value: ncrOpen, label: 'Open NCRs', tone: ncrOpen > 0 ? 'danger' : 'success' } : null,
        projectAccess && data.project?.progress != null ? { value: `${data.project.progress}%`, label: 'Progress', tone: 'theme' } : null,
        data.generated_at ? { value: new Date(data.generated_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }), label: 'Updated', tone: 'default' } : null,
    ];
}

export default function Dashboard({ auth }) {
    const { data, isLoading, isError, refetch } = useCommandData();
    const firstName = auth?.user?.name?.split(' ')?.[0] ?? 'Operator';

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader
                upper
                title="Command"
                muted="Center"
                subtitle={`${greeting()}, ${firstName} — Expressway O&M & TMC Floor.`}
                chips={statusChips(data)}
            />

            {isError ? (
                <div className="dl-error" style={{ minHeight: 'auto' }}>
                    <div className="dl-error__frame">
                        <Heading size="4" mb="2">Command center unavailable</Heading>
                        <Text color="gray" size="2" as="p" mb="4">We couldn’t load the project data. Check your connection and try again.</Text>
                        <Button onClick={() => refetch()}>Retry</Button>
                    </div>
                </div>
            ) : isLoading ? (
                <LoadingState />
            ) : data?.access?.project === false ? (
                /* Department-scoped operators (no project / quality permission) get the
                   workforce picture of their own scope only — the server already limits it. */
                <div className="dl-page">
                    <SectionLabel>Your workforce</SectionLabel>
                    <div className="dl-row">
                        <div className="dl-col dl-col--8">
                            <ErrorBoundary><WorkforceTrend workforce={data.workforce} /></ErrorBoundary>
                        </div>
                        <div className="dl-col dl-col--4">
                            <ErrorBoundary><TodayPanel today={data.today} showProject={false} /></ErrorBoundary>
                        </div>
                    </div>
                </div>
            ) : (
                <div className="dl-page">
                    <div className="dl-row">
                        <div className="dl-col">
                            <ErrorBoundary><ProjectHero project={data.project} chainage={data.chainage} objections={data.objections} /></ErrorBoundary>
                        </div>
                    </div>

                    <SectionLabel>Operations &amp; Traffic Control (TMC / ITS)</SectionLabel>
                    <div className="dl-row">
                        <div className="dl-col dl-col--8">
                            <ErrorBoundary>
                                <CommandCard
                                    title="Expressway Traffic Flow & Density (Ch 0+000 - Ch 48+000)"
                                    sub="Live speed sensors, VMS broadcast panels & weigh-in-motion"
                                    right={
                                        <Button size="1" variant="outline" onClick={() => window.location.href = '/om/traffic-monitoring'}>
                                            View TMC Console
                                        </Button>
                                    }
                                    flush
                                >
                                    <div className="dl-tiles">
                                        {TRAFFIC_SECTIONS.map((sec) => (
                                            <div key={sec.name} className="dl-tile">
                                                <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-11))' }}>{sec.name}</Text>
                                                <Text size="3" weight="bold" color={sec.color} as="div" style={{ fontFamily: MONO, marginTop: 2, marginBottom: 2 }}>{sec.status}</Text>
                                                <Text size="1" style={{ color: 'var(--aero-color-faint, var(--gray-10))' }}>{sec.meta}</Text>
                                            </div>
                                        ))}
                                    </div>
                                </CommandCard>
                            </ErrorBoundary>
                        </div>
                        <div className="dl-col dl-col--4">
                            <ErrorBoundary>
                                <CommandCard
                                    title="Emergency Patrol"
                                    sub="3 active dispatches"
                                    right={<Badge color="amber" variant="soft">SLA 11.8m</Badge>}
                                    flush
                                >
                                    <ul className="dl-list">
                                        {PATROL_INCIDENTS.map((inc) => (
                                            <li key={inc.title} className="dl-list__row">
                                                <Text size="1" weight="bold" color={inc.color}>{inc.title}</Text>
                                                <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-11))', marginTop: 2 }} as="div">{inc.meta}</Text>
                                            </li>
                                        ))}
                                    </ul>
                                </CommandCard>
                            </ErrorBoundary>
                        </div>
                    </div>

                    <SectionLabel>Live Operations Activity</SectionLabel>
                    <div className="dl-row">
                        <div className="dl-col"><ErrorBoundary><OperationsFeed feed={data?.feed} /></ErrorBoundary></div>
                    </div>
                </div>
            )}

            <style dangerouslySetInnerHTML={{ __html: CC_CSS }} />
        </>
    );
}

function LoadingState() {
    return (
        <div className="dl-page" aria-busy="true" aria-label="Loading command center">
            <div className="dl-row">
                <div className="dl-col"><Skeleton style={{ height: 190, borderRadius: R(14) }} /></div>
            </div>
            <div className="dl-row">
                <div className="dl-col dl-col--8"><Skeleton style={{ height: 280, borderRadius: R(14) }} /></div>
                <div className="dl-col dl-col--4"><Skeleton style={{ height: 280, borderRadius: R(14) }} /></div>
            </div>
            <div className="dl-row">
                <div className="dl-col"><Skeleton style={{ height: 280, borderRadius: R(14) }} /></div>
            </div>
        </div>
    );
}

Dashboard.layout = (page) => <App>{page}</App>;

const CC_CSS = `
.cc-kpi { min-height: 105px; }
.cc-ribbon-wrap { position: relative; padding: 26px 16px 4px; }
.cc-ticks { position: absolute; left: 16px; right: 16px; top: 4px; height: 16px; }
.cc-tick { position: absolute; transform: translateX(-50%); font-family: ${MONO}; font-size: 9.5px; color: var(--gray-10); }
.cc-tick::after { content: ""; position: absolute; left: 50%; top: 14px; width: 1px; height: 6px; background: var(--gray-a7); }
.cc-road { position: relative; height: 44px; border-radius: var(--dl-panel-radius, 8px); overflow: hidden; display: flex; gap: 1px;
  box-shadow: inset 0 0 0 1px var(--gray-a4); background: var(--gray-a2); }
.cc-seg { flex: 1; height: 100%; }
.cc-obj { position: absolute; top: 50%; width: 10px; height: 10px; margin: -5px 0 0 -5px; border-radius: 50%;
  background: var(--amber-9); box-shadow: 0 0 0 3px var(--amber-a4); z-index: 2; }
.cc-sheen { position: absolute; top: 0; bottom: 0; width: 30%; z-index: 1; pointer-events: none;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.18), transparent);
  transform: translateX(-140%); animation: ccSheen 5s ease-in-out .5s infinite; }
@keyframes ccSheen { 0% { transform: translateX(-140%);} 55%,100% { transform: translateX(380%);} }
@media (prefers-reduced-motion: reduce) { .cc-sheen { animation: none; } }
`;
