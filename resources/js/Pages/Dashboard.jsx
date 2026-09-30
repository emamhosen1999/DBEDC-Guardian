import { Panel } from '@/Components/ui/Panel';
import { Head } from '@inertiajs/react';
import React from 'react';
import { Box, Flex, Text, Heading, Skeleton, Button, Grid, Badge } from '@radix-ui/themes';

import App from '@/Layouts/App.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { useCommandData, MONO } from '@/Components/Dashboard/Command/kit.jsx';
import { ProjectHero, OperationsFeed, WorkforceTrend, TodayPanel } from '@/Components/Dashboard/Command/Widgets.jsx';
import { SectionLabel } from '@/Components/Dashboard/Command/kit.jsx';

function greeting() {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard({ auth }) {
    const { data, isLoading, isError, refetch } = useCommandData();

    return (
        <>
            <Head title="Dashboard" />
            <Box p={{ initial: '3', sm: '4', md: '5' }}>
                <Flex align="center" justify="between" mb="3" wrap="wrap" gap="2">
                    <Text size="2" color="gray">
                        {greeting()}, <Text as="span" weight="bold" style={{ color: 'var(--gray-12)' }}>{auth?.user?.name?.split(' ')?.[0] ?? 'Operator'}</Text> — Expressway O&M &amp; TMC Floor.
                    </Text>
                    <Text size="1" color="gray" style={{ fontFamily: MONO }}>
                        {data?.generated_at ? `updated ${new Date(data.generated_at).toLocaleTimeString('en-GB')}` : ''}
                    </Text>
                </Flex>

                {isError ? (
                    <Panel style={{ padding: 32, textAlign: 'center' }}>
                        <Heading size="4" mb="2">Command center unavailable</Heading>
                        <Text color="gray" size="2" as="p" mb="4">We couldn’t load the project data. Check your connection and try again.</Text>
                        <Button onClick={() => refetch()}>Retry</Button>
                    </Panel>
                ) : isLoading ? (
                    <LoadingState />
                ) : data?.access?.project === false ? (
                    /* Department-scoped operators (no project / quality permission) get the
                       workforce picture of their own scope only — the server already limits it. */
                    <Box className="cc-grid">
                        <SectionLabel>Your workforce</SectionLabel>
                        <Box className="cc-span-8">
                            <ErrorBoundary><WorkforceTrend workforce={data.workforce} /></ErrorBoundary>
                        </Box>
                        <Box className="cc-span-4">
                            <ErrorBoundary><TodayPanel today={data.today} showProject={false} /></ErrorBoundary>
                        </Box>
                    </Box>
                ) : (
                    <Box className="cc-grid">
                        <ErrorBoundary><ProjectHero project={data.project} chainage={data.chainage} objections={data.objections} /></ErrorBoundary>

                        <SectionLabel>Operations &amp; Traffic Control (TMC / ITS)</SectionLabel>
                        <Box className="cc-span-8">
                            <ErrorBoundary>
                                <Panel tinted style={{ padding: 20, height: '100%', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                    <Flex align="center" justify="between" mb="3">
                                        <Box>
                                            <Heading size="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>Expressway Traffic Flow &amp; Density (Ch 0+000 - Ch 48+000)</Heading>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Live speed sensors, VMS broadcast panels &amp; weigh-in-motion</Text>
                                        </Box>
                                        <Button size="1" variant="soft" color="blue" onClick={() => window.location.href = '/om/traffic-monitoring'} style={{ borderRadius: 10 }}>
                                            View TMC Console
                                        </Button>
                                    </Flex>
                                    <Grid columns={{ initial: '1', sm: '2' }} gap="3">
                                        <Box style={{ padding: 14, borderRadius: 12, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Ch 0-10 Joydevpur - Bhulta</Text>
                                            <Text size="3" weight="bold" color="green" as="div" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, marginTop: 2, marginBottom: 2 }}>FREE FLOW (78.5 km/h)</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-faint, var(--gray-8))' }}>1,840 veh/h · 1 WIM Overload</Text>
                                        </Box>
                                        <Box style={{ padding: 14, borderRadius: 12, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Ch 10-20 Bhulta - Kanchan</Text>
                                            <Text size="3" weight="bold" color="amber" as="div" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, marginTop: 2, marginBottom: 2 }}>MODERATE (68.2 km/h)</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-faint, var(--gray-8))' }}>2,420 veh/h · 4 WIM Overload</Text>
                                        </Box>
                                        <Box style={{ padding: 14, borderRadius: 12, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Ch 20-35 Kanchan - Debogram</Text>
                                            <Text size="3" weight="bold" color="green" as="div" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, marginTop: 2, marginBottom: 2 }}>FREE FLOW (74.0 km/h)</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-faint, var(--gray-8))' }}>1,950 veh/h · 2 WIM Overload</Text>
                                        </Box>
                                        <Box style={{ padding: 14, borderRadius: 12, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>Ch 35-48 Debogram - Madanpur</Text>
                                            <Text size="3" weight="bold" color="red" as="div" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, marginTop: 2, marginBottom: 2 }}>CONGESTED (52.0 km/h)</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-faint, var(--gray-8))' }}>2,890 veh/h · 9 WIM Overload</Text>
                                        </Box>
                                    </Grid>
                                </Panel>
                            </ErrorBoundary>
                        </Box>
                        <Box className="cc-span-4">
                            <ErrorBoundary>
                                <Panel tinted style={{ padding: 20, height: '100%', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                    <Flex align="center" justify="between" mb="3">
                                        <Box>
                                            <Heading size="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>Emergency Patrol</Heading>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>3 active dispatches</Text>
                                        </Box>
                                        <Badge color="amber" variant="soft" style={{ borderRadius: 999 }}>SLA 11.8m</Badge>
                                    </Flex>
                                    <Flex direction="column" gap="2">
                                        <Box style={{ padding: 12, borderRadius: 10, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" weight="bold" color="blue">INC-2026-001 · Stalled Truck</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-8))', marginTop: 2 }} as="div">Ch 14+200 SB · Patrol Unit 2</Text>
                                        </Box>
                                        <Box style={{ padding: 12, borderRadius: 10, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" weight="bold" color="amber">INC-2026-002 · Debris on Road</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-8))', marginTop: 2 }} as="div">Ch 28+500 NB · Patrol Unit 1</Text>
                                        </Box>
                                        <Box style={{ padding: 12, borderRadius: 10, background: 'var(--color-background)', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                            <Text size="1" weight="bold" color="red">INC-2026-003 · Overload Alert</Text>
                                            <Text size="1" style={{ color: 'var(--aero-color-subtle, var(--gray-8))', marginTop: 2 }} as="div">Ch 39+800 SB · Weighbridge Unit 3</Text>
                                        </Box>
                                    </Flex>
                                </Panel>
                            </ErrorBoundary>
                        </Box>

                        <SectionLabel>Live Operations Activity</SectionLabel>
                        <Box className="cc-span-12"><ErrorBoundary><OperationsFeed feed={data?.feed} /></ErrorBoundary></Box>
                    </Box>
                )}
            </Box>

            <style dangerouslySetInnerHTML={{ __html: CC_CSS }} />
        </>
    );
}

function LoadingState() {
    return (
        <Box className="cc-grid">
            <Skeleton className="cc-span-12" style={{ height: 190, borderRadius: 14 }} />
            <Box className="cc-span-12">
                <Flex gap="3" wrap="wrap">
                    {Array.from({ length: 6 }).map((_, i) => (
                        <Skeleton key={i} style={{ height: 118, flex: '1 1 150px', borderRadius: 14 }} />
                    ))}
                </Flex>
            </Box>
            {[8, 4, 12].map((s, i) => (
                <Skeleton key={i} className={`cc-span-${s}`} style={{ height: 280, borderRadius: 14 }} />
            ))}
        </Box>
    );
}

Dashboard.layout = (page) => <App>{page}</App>;

const CC_CSS = `
.cc-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 14px; align-items: start; }
.cc-span-12 { grid-column: span 12; }
.cc-span-8 { grid-column: span 8; }
.cc-span-7 { grid-column: span 7; }
.cc-span-5 { grid-column: span 5; }
.cc-span-4 { grid-column: span 4; }
@media (max-width: 1100px) {
  .cc-span-8, .cc-span-7, .cc-span-5 { grid-column: span 12; }
  .cc-span-4 { grid-column: span 6; }
}
@media (max-width: 680px) {
  .cc-span-4 { grid-column: span 12; }
}
.cc-card { transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease; }
.cc-card:hover { border-color: var(--accent-a7); }
.cc-kpi { min-height: 118px; }
.cc-hero { padding: 20px; }
.cc-ribbon-wrap { position: relative; padding: 26px 2px 4px; }
.cc-ticks { position: absolute; left: 2px; right: 2px; top: 4px; height: 16px; }
.cc-tick { position: absolute; transform: translateX(-50%); font-family: ${MONO}; font-size: 9.5px; color: var(--gray-10); }
.cc-tick::after { content: ""; position: absolute; left: 50%; top: 14px; width: 1px; height: 6px; background: var(--gray-a7); }
.cc-road { position: relative; height: 44px; border-radius: 8px; overflow: hidden; display: flex; gap: 1px;
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
