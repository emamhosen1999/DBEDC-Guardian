import { Head } from '@inertiajs/react';
import React from 'react';
import { Box, Flex, Text, Heading, Skeleton, Button, Card } from '@radix-ui/themes';

import App from '@/Layouts/App.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { useCommandData, MONO } from '@/Components/Dashboard/Command/kit.jsx';
import {
    ProjectHero, KpiBand, RfiThroughput, QualitySignal, DisciplineMix, NcrPanel, SiPanel,
    ObjectionHotspots, BudgetBurndown, WorkforceTrend, WorkPackages, OperationsFeed, TodayPanel,
} from '@/Components/Dashboard/Command/Widgets.jsx';
import { SectionLabel } from '@/Components/Dashboard/Command/kit.jsx';

function greeting() {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard({ auth }) {
    const { data, isLoading, isError, refetch } = useCommandData();
    const perms = auth?.permissions ?? [];
    const hasRfi = perms.includes('daily-works.view') || perms.includes('daily-works.own.view');

    return (
        <>
            <Head title="Dashboard" />
            <Box p={{ initial: '3', sm: '4', md: '5' }}>
                <Flex align="center" justify="between" mb="3" wrap="wrap" gap="2">
                    <Text size="2" color="gray">
                        {greeting()}, <Text as="span" weight="bold" style={{ color: 'var(--gray-12)' }}>{auth?.user?.name?.split(' ')?.[0] ?? 'Engineer'}</Text> — here is the project floor.
                    </Text>
                    <Text size="1" color="gray" style={{ fontFamily: MONO }}>
                        {data?.generated_at ? `updated ${new Date(data.generated_at).toLocaleTimeString('en-GB')}` : ''}
                    </Text>
                </Flex>

                {isError ? (
                    <Card style={{ padding: 32, textAlign: 'center' }}>
                        <Heading size="4" mb="2">Command center unavailable</Heading>
                        <Text color="gray" size="2" as="p" mb="4">We couldn’t load the project data. Check your connection and try again.</Text>
                        <Button onClick={() => refetch()}>Retry</Button>
                    </Card>
                ) : isLoading ? (
                    <LoadingState />
                ) : (
                    <Box className="cc-grid">
                        <ErrorBoundary><ProjectHero project={data.project} chainage={data.chainage} objections={data.objections} /></ErrorBoundary>

                        <Box className="cc-span-12"><ErrorBoundary><KpiBand kpis={data.kpis} quality={data.quality} /></ErrorBoundary></Box>

                        {hasRfi && (data.throughput?.length > 0 || data.quality) && (
                            <SectionLabel>Inspection &amp; Quality Flow</SectionLabel>
                        )}
                        {hasRfi && data.throughput?.length > 0 && (
                            <Box className="cc-span-8"><ErrorBoundary><RfiThroughput data={data.throughput} /></ErrorBoundary></Box>
                        )}
                        {hasRfi && (
                            <Box className="cc-span-4"><ErrorBoundary><QualitySignal quality={data.quality} /></ErrorBoundary></Box>
                        )}
                        {hasRfi && data.disciplines?.length > 0 && (
                            <Box className="cc-span-4"><ErrorBoundary><DisciplineMix data={data.disciplines} /></ErrorBoundary></Box>
                        )}
                        <Box className="cc-span-4"><ErrorBoundary><NcrPanel ncr={data.ncr} /></ErrorBoundary></Box>
                        <Box className="cc-span-4"><ErrorBoundary><SiPanel si={data.si} /></ErrorBoundary></Box>

                        <Box className="cc-span-12"><ErrorBoundary><ObjectionHotspots objections={data.objections} /></ErrorBoundary></Box>

                        <SectionLabel>Programme &amp; Commercials</SectionLabel>
                        <Box className="cc-span-7"><ErrorBoundary><BudgetBurndown budget={data.budget} /></ErrorBoundary></Box>
                        <Box className="cc-span-5"><ErrorBoundary><WorkforceTrend workforce={data.workforce} /></ErrorBoundary></Box>
                        <Box className="cc-span-8"><ErrorBoundary><WorkPackages milestones={data.milestones} /></ErrorBoundary></Box>
                        <Box className="cc-span-4"><ErrorBoundary><TodayPanel today={data.today} project={data.project} /></ErrorBoundary></Box>

                        <SectionLabel>Live Site Activity</SectionLabel>
                        <Box className="cc-span-12"><ErrorBoundary><OperationsFeed feed={data.feed} /></ErrorBoundary></Box>
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
            {[8, 4, 4, 4, 4, 12, 7, 5].map((s, i) => (
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
