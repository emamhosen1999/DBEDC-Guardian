import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Badge, Separator } from '@radix-ui/themes';
import { ClockIcon, WrenchScrewdriverIcon, BoltIcon, ClipboardDocumentCheckIcon, DocumentMagnifyingGlassIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function AssetTimeline({ auth, asset, timeline }) {
    useOperationsRealtimeRefresh();

    const typeIcon = (type) => {
        switch (type) {
            case 'survey': return <DocumentMagnifyingGlassIcon className="h-5 w-5" style={{color: 'var(--cyan-9)'}} />;
            case 'defect': return <BoltIcon className="h-5 w-5" style={{color: 'var(--red-9)'}} />;
            case 'work_order': return <WrenchScrewdriverIcon className="h-5 w-5" style={{color: 'var(--blue-9)'}} />;
            case 'inspection': return <ClipboardDocumentCheckIcon className="h-5 w-5" style={{color: 'var(--green-9)'}} />;
            default: return <ClockIcon className="h-5 w-5" />;
        }
    };

    const typeColor = (type) => ({
        survey: 'cyan', defect: 'red', work_order: 'blue', inspection: 'green',
    }[type] || 'gray');

    const typeLabel = (type) => ({
        survey: 'Condition Survey', defect: 'Defect', work_order: 'Work Order', inspection: 'Inspection',
    }[type] || type);

    const timelineItems = timeline || [];

    return (
        <App>
            <Head title={`Asset Timeline — ${asset?.asset_code || 'Unknown'}`} />
            <Box p="5">
                {/* Asset Header */}
                <Panel mb="4">
                    <Flex justify="between" align="start">
                        <Box>
                            <Heading size="6" weight="bold">{asset?.name || 'Unknown Asset'}</Heading>
                            <Text size="2" color="gray" as="div">{asset?.asset_code} · {asset?.category?.replace(/_/g, ' ')}</Text>
                            <Text size="2" color="gray" as="div" mt="1">
                                Chainage: {asset?.start_chainage}{asset?.end_chainage ? ` → ${asset.end_chainage}` : ''} · {asset?.direction?.replace(/_/g, ' ')}
                            </Text>
                        </Box>
                        <Flex direction="column" align="end" gap="1">
                            <Badge color={asset?.operational_status === 'active' ? 'green' : asset?.operational_status === 'degraded' ? 'amber' : 'red'} size="2">
                                {asset?.operational_status?.replace(/_/g, ' ').toUpperCase()}
                            </Badge>
                            <Flex gap="2" align="center">
                                <Text size="2" color="gray">Condition:</Text>
                                <Badge color={asset?.condition_score >= 70 ? 'green' : asset?.condition_score >= 40 ? 'amber' : 'red'} variant="soft" size="2">
                                    {asset?.condition_score}/100 ({asset?.condition_grade})
                                </Badge>
                            </Flex>
                        </Flex>
                    </Flex>
                </Panel>

                {/* Timeline */}
                <Heading size="5" mb="3">Maintenance History Timeline</Heading>
                <Box style={{position: 'relative', paddingLeft: 32}}>
                    {/* Vertical line */}
                    <Box style={{position: 'absolute', left: 12, top: 0, bottom: 0, width: 2, backgroundColor: 'var(--gray-6)'}} />

                    {timelineItems.map((item, idx) => (
                        <Box key={`${item.type}-${item.id}`} mb="3" style={{position: 'relative'}}>
                            {/* Dot */}
                            <Box style={{
                                position: 'absolute', left: -26, top: 6,
                                width: 20, height: 20, borderRadius: '50%',
                                backgroundColor: 'var(--color-background)',
                                border: `2px solid var(--${typeColor(item.type)}-9)`,
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                zIndex: 1,
                            }}>
                                <Box style={{width: 8, height: 8, borderRadius: '50%', backgroundColor: `var(--${typeColor(item.type)}-9)`}} />
                            </Box>

                            <Panel>
                                <Flex justify="between" align="start">
                                    <Flex gap="2" align="center">
                                        {typeIcon(item.type)}
                                        <Box>
                                            <Badge color={typeColor(item.type)} size="1" variant="soft">{typeLabel(item.type)}</Badge>
                                            <Text size="2" weight="bold" as="div" mt="1">{item.title}</Text>
                                            {item.details && <Text size="1" color="gray" as="div" mt="1">{item.details}</Text>}
                                        </Box>
                                    </Flex>
                                    <Text size="1" color="gray">{item.date ? new Date(item.date).toLocaleDateString() : '—'}</Text>
                                </Flex>
                            </Panel>
                        </Box>
                    ))}

                    {timelineItems.length === 0 && (
                        <Panel>
                            <Text align="center" color="gray" size="2" style={{display: 'block', padding: 24}}>
                                No maintenance history recorded for this asset yet.
                            </Text>
                        </Panel>
                    )}
                </Box>
            </Box>
        </App>
    );
}
