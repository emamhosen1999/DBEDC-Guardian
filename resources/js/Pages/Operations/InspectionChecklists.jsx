import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, Dialog, Select, TextArea, Separator } from '@radix-ui/themes';
import { ClipboardDocumentCheckIcon, CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon, EyeIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function InspectionChecklists({ auth, inspections, stats, templates, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [reviewModal, setReviewModal] = useState(null);
    const [reviewNotes, setReviewNotes] = useState('');

    const inspectionList = inspections?.data || [];

    const handleReview = (id) => {
        router.post(`/om/inspections/${id}/review`, { notes: reviewNotes }, {
            onSuccess: () => { setReviewModal(null); setReviewNotes(''); },
            onError: showOperationMutationErrors,
        });
    };

    const resultColor = (r) => ({ pass: 'green', needs_attention: 'amber', fail: 'orange', critical: 'red' }[r] || 'gray');
    const resultIcon = (r) => {
        if (r === 'pass') return <CheckCircleIcon className="inline h-4 w-4" />;
        if (r === 'fail' || r === 'critical') return <XCircleIcon className="inline h-4 w-4" />;
        return <ExclamationTriangleIcon className="inline h-4 w-4" />;
    };

    return (
        <App auth={auth}>
            <Head title="Inspection Checklists & Condition Surveys" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ClipboardDocumentCheckIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Inspection Checklists & Condition Surveys
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Digital asset inspections with scoring, auto-defect triggers, and joint QC verification
                                        </Text>
                                    </Box>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                {/* KPI Cards */}
                <StatsCards stats={[
                    { label: 'Total Inspections', value: stats?.total_inspections ?? 0, icon: ClipboardDocumentCheckIcon, color: 'blue' },
                    { label: 'This Month', value: stats?.inspections_this_month ?? 0, icon: ClipboardDocumentCheckIcon, color: 'indigo' },
                    { label: 'Pass Rate', value: `${stats?.pass_rate ?? 0}%`, icon: CheckCircleIcon, color: 'green' },
                    { label: 'Critical Findings', value: stats?.critical_findings ?? 0, icon: XCircleIcon, color: stats?.critical_findings > 0 ? 'red' : 'green' },
                    { label: 'Pending Review', value: stats?.pending_review ?? 0, icon: EyeIcon, color: 'amber' },
                    { label: 'Avg Score', value: stats?.average_score ?? 0, icon: ClipboardDocumentCheckIcon, color: 'cyan' },
                ]} />

                {/* Templates Overview */}
                {templates && templates.length > 0 && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">Active Inspection Templates</Heading>
                        <Flex gap="3" wrap="wrap">
                            {templates.map(t => (
                                <Box key={t.id} style={{border: '1px solid var(--gray-6)', borderRadius: 8, padding: 12, minWidth: 200}}>
                                    <Text weight="bold" size="2">{t.template_code}</Text>
                                    <Text size="2" as="div">{t.name}</Text>
                                    <Badge size="1" variant="soft" mt="1">{t.asset_category?.replace(/_/g, ' ')}</Badge>
                                    <Text size="1" color="gray" as="div" mt="1">Pass: ≥{t.pass_threshold}% | Max: {t.max_score}</Text>
                                </Box>
                            ))}
                        </Flex>
                    </Panel>
                )}

                {/* Inspection Records Table */}
                <Panel mt="4">
                    <Heading size="4" mb="3">Inspection Records</Heading>
                    <Table.Root>
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Inspection #</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Template</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Chainage</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Score</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Result</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Inspector</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                {canManage && <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {inspectionList.map(insp => (
                                <Table.Row key={insp.id}>
                                    <Table.Cell><Text weight="bold" size="2" color="blue">{insp.inspection_number}</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{insp.inspection_date ? new Date(insp.inspection_date).toLocaleDateString() : '—'}</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{insp.template?.template_code || '—'}</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{insp.chainage || '—'}</Text></Table.Cell>
                                    <Table.Cell><Text weight="bold" size="2">{insp.total_score}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Badge color={resultColor(insp.result)} size="1">
                                            {resultIcon(insp.result)} {insp.result?.replace(/_/g, ' ').toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell><Text size="2">{insp.inspector?.name || '—'}</Text></Table.Cell>
                                    <Table.Cell><Badge variant="outline" size="1">{insp.status?.toUpperCase()}</Badge></Table.Cell>
                                    {canManage && (
                                        <Table.Cell>
                                            {insp.status === 'submitted' && (
                                                <Button size="1" variant="soft" color="blue" onClick={() => setReviewModal(insp)}>
                                                    Review
                                                </Button>
                                            )}
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            ))}
                            {inspectionList.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={9}>
                                        <Text align="center" color="gray" size="2" style={{display:'block', padding:'24px'}}>
                                            No inspections recorded yet. Field teams can submit inspections via the mobile app.
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>

                        {/* Review Modal */}
                        <Dialog.Root open={!!reviewModal} onOpenChange={(open) => !open && setReviewModal(null)}>
                            <Dialog.Content maxWidth="450px">
                                <Dialog.Title>Review Inspection {reviewModal?.inspection_number}</Dialog.Title>
                                <Dialog.Description size="2" color="gray" mb="3">
                                    Score: {reviewModal?.total_score} | Result: {reviewModal?.result?.replace(/_/g, ' ')}
                                </Dialog.Description>
                                <TextArea placeholder="Review notes (optional)" value={reviewNotes} onChange={e => setReviewNotes(e.target.value)} rows={3} />
                                <Flex justify="end" gap="2" mt="3">
                                    <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                                    <Button color="blue" onClick={() => handleReview(reviewModal?.id)}>Mark Reviewed</Button>
                                </Flex>
                            </Dialog.Content>
                        </Dialog.Root>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
}
