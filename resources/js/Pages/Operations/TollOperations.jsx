import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, TextField, Dialog, Select, Separator, Tabs } from '@radix-ui/themes';
import {
    CurrencyDollarIcon,
    PlusIcon,
    DocumentCheckIcon,
    ShieldCheckIcon,
    BuildingLibraryIcon,
    ExclamationTriangleIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function TollOperations({ auth, summary, tollRecords, shiftAudits, exemptions, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.toll.manage') || auth?.roles?.includes('Super Administrator');
    const [openAuditModal, setOpenAuditModal] = useState(false);
    const [plazaName, setPlazaName] = useState('Main Toll Plaza (Ch 0+000)');
    const [shiftDate, setShiftDate] = useState(new Date().toISOString().split('T')[0]);
    const [shiftType, setShiftType] = useState('morning');
    const [sysTotal, setSysTotal] = useState('485200');
    const [cashDeclared, setCashDeclared] = useState('104800');
    const [etcRevenue, setEtcRevenue] = useState('380400');
    const [bankDepositRef, setBankDepositRef] = useState('BRAC-DEP-20260902-881');
    const [auditorNotes, setAuditorNotes] = useState('100% reconciliation matched. No revenue leakage.');

    const records = tollRecords?.data || [];
    const audits = shiftAudits?.data || [];
    const exempts = exemptions?.data || [];

    const handleAuditSubmit = (e) => {
        e.preventDefault();
        router.post('/om/toll-operations/audit', {
            plaza_name: plazaName,
            shift_date: shiftDate,
            shift_type: shiftType,
            system_calculated_total: Number(sysTotal),
            cash_declared_by_collectors: Number(cashDeclared),
            etc_automatic_revenue: Number(etcRevenue),
            bank_deposit_reference: bankDepositRef,
            auditor_notes: auditorNotes,
        }, {
            onSuccess: () => {
                setOpenAuditModal(false);
            }
        });
    };

    const statItems = [
        { key: 'revenue', title: "Today's Toll Revenue", value: `৳ ${Number(summary?.total_revenue_today || 485200).toLocaleString()}`, color: 'green', icon: <CurrencyDollarIcon /> },
        { key: 'etc', title: 'Electronic Toll (ETC) %', value: `${summary?.etc_percentage || 78.4}%`, color: 'blue' },
        { key: 'cash', title: 'Manual Cash %', value: `${summary?.cash_percentage || 21.6}%`, color: 'amber' },
        { key: 'discrepancies', title: 'Flagged Shift Audits', value: summary?.discrepancy_audits_count || 0, color: 'red', icon: <ExclamationTriangleIcon /> },
    ];

    return (
        <App auth={auth}>
            <Head title="Toll Operations & Revenue Reconciliation" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--green-a3)', borderRadius: 12, border: '1px solid var(--green-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <CurrencyDollarIcon style={{ width: 22, height: 22, color: 'var(--green-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Toll Operations & Shift Revenue Audit
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Cashier Declarations, Electronic ETC Automatic Deductions, Shift Variance & Exemption Registry
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && <Button color="green" onClick={() => setOpenAuditModal(true)} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                    <PlusIcon width={16} height={16} /> Submit Shift Audit
                                </Button>}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '4' }} mb="4" />

                        {/* Shift Reconciliation & Exemptions Tabs */}
                        <Tabs.Root defaultValue="audits">
                            <Tabs.List mb="3">
                                <Tabs.Trigger value="audits">Shift Reconciliation Audits ({audits.length})</Tabs.Trigger>
                                <Tabs.Trigger value="exemptions">Exemptions Registry ({exempts.length})</Tabs.Trigger>
                                <Tabs.Trigger value="transactions">Live Transaction Feed ({records.length})</Tabs.Trigger>
                            </Tabs.List>

                            {/* Shift Reconciliation Audits */}
                            <Tabs.Content value="audits">
                                <Box style={{ overflowX: 'auto', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                                    <Table.Root size="2" style={{ minWidth: 920, width: '100%' }}>
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Audit Code</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Shift Date & Type</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>System Revenue</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Declared Cash</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>ETC Revenue</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Variance</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Deposit Ref</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {audits.map((aud) => (
                                                <Table.Row key={aud.id} align="center">
                                                    <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>{aud.audit_code}</Table.Cell>
                                                    <Table.Cell>
                                                        <Text size="2" weight="bold">{aud.shift_date}</Text>
                                                        <Text size="1" color="gray">({aud.shift_type?.toUpperCase()})</Text>
                                                    </Table.Cell>
                                                    <Table.Cell style={{ fontVariantNumeric: 'tabular-nums' }}>৳{Number(aud.system_calculated_total).toLocaleString()}</Table.Cell>
                                                    <Table.Cell style={{ fontVariantNumeric: 'tabular-nums' }}>৳{Number(aud.cash_declared_by_collectors).toLocaleString()}</Table.Cell>
                                                    <Table.Cell style={{ fontVariantNumeric: 'tabular-nums' }}>৳{Number(aud.etc_automatic_revenue).toLocaleString()}</Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={Number(aud.variance_amount) === 0 ? 'green' : 'red'} variant="soft">
                                                            {Number(aud.variance_amount) === 0 ? 'MATCHED (৳0)' : `৳${aud.variance_amount}`}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={aud.audit_status === 'verified_matched' ? 'green' : 'amber'} variant="surface">
                                                            {aud.audit_status?.replace(/_/g, ' ').toUpperCase()}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell style={{ textAlign: 'right' }}>
                                                        <Text size="1" style={{ fontFamily: 'monospace' }}>{aud.bank_deposit_reference || 'N/A'}</Text>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </Tabs.Content>

                            {/* Exemptions Registry */}
                            <Tabs.Content value="exemptions">
                                <Box style={{ overflowX: 'auto', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                                    <Table.Root size="2" style={{ minWidth: 840, width: '100%' }}>
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Vehicle Reg #</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Plaza / Lane</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Authorization Document</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Passed Time</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {exempts.map((ex) => (
                                                <Table.Row key={ex.id} align="center">
                                                    <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>{ex.vehicle_reg_number}</Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color="blue" variant="soft">{ex.exemption_category?.replace(/_/g, ' ').toUpperCase()}</Badge>
                                                    </Table.Cell>
                                                    <Table.Cell><Text size="2">{ex.plaza_name} ({ex.lane_id})</Text></Table.Cell>
                                                    <Table.Cell><Text size="2">{ex.authorizing_document_ref || 'Official Pass'}</Text></Table.Cell>
                                                    <Table.Cell style={{ textAlign: 'right' }}><Text size="2">{ex.passed_at}</Text></Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </Tabs.Content>

                            {/* Live Transaction Feed */}
                            <Tabs.Content value="transactions">
                                <Box style={{ overflowX: 'auto', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                                    <Table.Root size="2" style={{ minWidth: 720, width: '100%' }}>
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Lane ID</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Vehicle Class</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Payment Mode</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Amount</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Timestamp</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {records.map((r) => (
                                                <Table.Row key={r.id} align="center">
                                                    <Table.Cell style={{ fontFamily: 'monospace' }}>{r.lane_id}</Table.Cell>
                                                    <Table.Cell><Text size="2" weight="bold">{r.vehicle_class}</Text></Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={r.payment_method === 'etc' ? 'green' : 'blue'} variant="soft">
                                                            {r.payment_method?.toUpperCase()}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>৳{Number(r.amount).toLocaleString()}</Table.Cell>
                                                    <Table.Cell style={{ textAlign: 'right' }}><Text size="2">{r.transacted_at}</Text></Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Panel>
                </Box>
            </Flex>

            {/* Shift Audit Modal */}
            <Dialog.Root open={canManage && openAuditModal} onOpenChange={setOpenAuditModal}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Submit Toll Plaza Shift Audit</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Reconcile cashier physical cash collected vs ETC automated transactions.
                    </Dialog.Description>
                    <form onSubmit={handleAuditSubmit}>
                        <Flex direction="column" gap="3">
                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Shift Date</Text>
                                    <TextField.Root type="date" value={shiftDate} onChange={(e) => setShiftDate(e.target.value)} required />
                                </label>
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Shift Type</Text>
                                    <Select.Root value={shiftType} onValueChange={setShiftType}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="morning">Morning (06:00 - 14:00)</Select.Item>
                                            <Select.Item value="evening">Evening (14:00 - 22:00)</Select.Item>
                                            <Select.Item value="night">Night (22:00 - 06:00)</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Declared Cash (৳)</Text>
                                    <TextField.Root type="number" value={cashDeclared} onChange={(e) => setCashDeclared(e.target.value)} required />
                                </label>
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">ETC Automatic Total (৳)</Text>
                                    <TextField.Root type="number" value={etcRevenue} onChange={(e) => setEtcRevenue(e.target.value)} required />
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">System Theoretical Calculation (৳)</Text>
                                <TextField.Root type="number" value={sysTotal} onChange={(e) => setSysTotal(e.target.value)} required />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Bank Armored Cash Deposit Reference</Text>
                                <TextField.Root placeholder="e.g. BRAC-DEP-20260902-881" value={bankDepositRef} onChange={(e) => setBankDepositRef(e.target.value)} />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Auditor Verification Remarks</Text>
                                <TextField.Root value={auditorNotes} onChange={(e) => setAuditorNotes(e.target.value)} />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenAuditModal(false)}>Cancel</Button>
                                <Button type="submit" color="green">Submit & Sign Audit</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
