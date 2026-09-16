import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Separator, Card, Grid } from '@radix-ui/themes';
import { DocumentTextIcon, PrinterIcon, ArrowDownTrayIcon, CalendarIcon, CheckCircleIcon, ExclamationTriangleIcon, ClockIcon } from '@heroicons/react/24/outline';
import * as XLSX from 'xlsx';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useQueryFilters } from '@/Hooks/useQueryFilters';

export default function DailyMaintenanceReport({ auth, summary, defects, date }) {
    /* The report date is what the server is asked for, so it lives in the URL.
       The default is left empty rather than "today" so that a date the user
       picked always stays visible in the link they copy. */
    const f = useQueryFilters({
        defaults: { date: '' },
        debounceKeys: [],
    });
    const selectedDate = f.values.date || date || new Date().toISOString().substring(0, 10);
    const handleDateChange = (newDate) => f.set('date', newDate);

    const handlePrint = () => {
        window.print();
    };

    const handleExportExcel = () => {
        const rows = (defects || []).map((d, index) => ({
            'SL #': index + 1,
            'Defect Number': d.defect_number || 'N/A',
            'Location (Chainage)': d.chainage || 'N/A',
            'Carriageway Direction': d.direction ? d.direction.toUpperCase() : 'N/A',
            'Defect Category': (d.category || d.distress_type || 'General').replace(/_/g, ' ').toUpperCase(),
            'Distress Type / Title': d.title || d.distress_type || 'N/A',
            'Severity': (d.severity || 'Medium').toUpperCase(),
            'Status': (d.status || 'Reported').replace(/_/g, ' ').toUpperCase(),
            'SLA Target (Hours)': d.sla_hours ?? 24,
            'Reported By': d.reporter?.name || 'Patrol Unit',
            'Reported At': d.reported_at || d.created_at || 'N/A',
            'Contractor Assigned': d.work_orders?.[0]?.contractor_name || 'In-House Crew',
            'Remarks / QC Notes': d.description || '',
        }));

        const summaryRows = [
            { 'KPI Metric': 'Daily Report Date', 'Value': selectedDate },
            { 'KPI Metric': 'Inspection Team', 'Value': summary?.inspection_team || 'SE: Prodip - Habib' },
            { 'KPI Metric': 'Weather Condition', 'Value': summary?.weather || 'Fair' },
            { 'KPI Metric': 'Observed Traffic Flow', 'Value': summary?.traffic_condition || 'Normal Corridor Flow' },
            { 'KPI Metric': 'Overall Pavement Condition', 'Value': summary?.overall_pavement_condition || 'Fair to Good' },
            { 'KPI Metric': 'Cumulative Open Defects', 'Value': summary?.cumulative_open_defects ?? 0 },
            { 'KPI Metric': 'New Defects Today', 'Value': summary?.new_defects_today ?? 0 },
            { 'KPI Metric': 'Defects Repaired Today', 'Value': summary?.repaired_today ?? 0 },
            { 'KPI Metric': 'Critical Safety Hazards', 'Value': summary?.open_by_severity?.critical ?? 0 },
            { 'KPI Metric': 'Major Safety Hazards', 'Value': summary?.open_by_severity?.major ?? 0 },
            { 'KPI Metric': 'Moderate Defects', 'Value': summary?.open_by_severity?.moderate ?? 0 },
            { 'KPI Metric': 'Minor Defects', 'Value': summary?.open_by_severity?.minor ?? 0 },
            { 'KPI Metric': 'Executive Submission Time', 'Value': summary?.reported_to_ceo || '09:15 AM' },
            { 'KPI Metric': 'Report Prepared By', 'Value': summary?.report_prepared_by || 'QC Department' },
        ];

        const workbook = XLSX.utils.book_new();

        const defectSheet = XLSX.utils.json_to_sheet(rows.length > 0 ? rows : [{ 'Status': 'No defects recorded for this date' }]);
        const summarySheet = XLSX.utils.json_to_sheet(summaryRows);

        defectSheet['!cols'] = [
            { wch: 6 }, { wch: 18 }, { wch: 22 }, { wch: 22 }, { wch: 18 },
            { wch: 28 }, { wch: 12 }, { wch: 16 }, { wch: 18 }, { wch: 22 },
            { wch: 20 }, { wch: 22 }, { wch: 35 }
        ];
        summarySheet['!cols'] = [{ wch: 30 }, { wch: 35 }];

        XLSX.utils.book_append_sheet(workbook, defectSheet, 'Defect Register');
        XLSX.utils.book_append_sheet(workbook, summarySheet, 'Executive Summary');

        XLSX.writeFile(workbook, `Daily_Maintenance_Report_N105_${selectedDate}.xlsx`);
    };

    const statItems = [
        { key: 'cumulative', title: 'Cumulative Open Defects', value: summary?.cumulative_open_defects ?? 0, color: 'amber', icon: <ClockIcon /> },
        { key: 'new_today', title: 'New Defects Today', value: summary?.new_defects_today ?? 0, color: 'red', icon: <ExclamationTriangleIcon /> },
        { key: 'repaired_today', title: 'Repaired Today', value: summary?.repaired_today ?? 0, color: 'green', icon: <CheckCircleIcon /> },
        { key: 'critical', title: 'Critical Safety Hazards', value: summary?.open_by_severity?.critical ?? 0, color: 'red' },
    ];

    const getSeverityBadge = (sev) => {
        const colors = { critical: 'red', high: 'orange', medium: 'amber', low: 'blue' };
        return <Badge color={colors[sev] || 'gray'} variant="soft" style={{ borderRadius: 999 }}>{(sev || 'medium').toUpperCase()}</Badge>;
    };

    const getStatusBadge = (st) => {
        const colors = { reported: 'amber', investigating: 'blue', work_order_created: 'indigo', in_repair: 'purple', rectified: 'green', verified_closed: 'green' };
        return <Badge color={colors[st] || 'gray'} variant="surface" style={{ borderRadius: 999 }}>{(st || 'open').replace(/_/g, ' ').toUpperCase()}</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="Daily Road Maintenance Monitoring Report (CEO Format)" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <DocumentTextIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Daily Road Maintenance Monitoring Report
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Section: K-4+000 to K-22+000, N-105 Dhaka Bypass Expressway • Standard QC Morning Briefing for CEO
                                        </Text>
                                    </Box>
                                </Flex>

                                <Flex gap="2" align="center">
                                    <Flex align="center" gap="2" style={{ background: 'var(--aero-surface, var(--gray-a2))', padding: '4px 10px', borderRadius: 10, border: '1px solid var(--dl-border-color, rgba(0,0,0,0.08))' }}>
                                        <CalendarIcon width={16} height={16} color="gray" />
                                        <input
                                            type="date"
                                            value={selectedDate}
                                            onChange={(e) => handleDateChange(e.target.value)}
                                            style={{ background: 'transparent', border: 'none', outline: 'none', color: 'inherit', fontFamily: 'inherit', fontSize: 13 }}
                                        />
                                    </Flex>
                                    <Button color="gray" variant="soft" onClick={handlePrint} style={{ borderRadius: 10 }}>
                                        <PrinterIcon width={16} height={16} /> Print / Export PDF
                                    </Button>
                                    <Button color="green" variant="soft" onClick={handleExportExcel} style={{ borderRadius: 10 }}>
                                        <ArrowDownTrayIcon width={16} height={16} /> Export Excel (.xlsx)
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* Executive KPI Cards */}
                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '4' }} mb="4" />

                        {/* Inspection Metadata Dossier */}
                        <Box p="4" mb="4" style={{ borderRadius: 14, background: 'var(--aero-surface, var(--gray-a2))', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                            <Grid columns={{ initial: '1', sm: '3' }} gap="4">
                                <Box>
                                    <Text size="1" color="gray" weight="bold">INSPECTION TEAM</Text>
                                    <Text size="2" weight="medium">{summary?.inspection_team || 'SE: Prodip - Habib'}</Text>
                                    <Text size="1" color="gray" mt="2" weight="bold">WEATHER OBSERVATION</Text>
                                    <Text size="2" weight="medium">{summary?.weather || 'Fair'}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray" weight="bold">TRAFFIC CONDITION OBSERVED</Text>
                                    <Text size="2" weight="medium">{summary?.traffic_condition || 'Normal Corridor Flow'}</Text>
                                    <Text size="1" color="gray" mt="2" weight="bold">OVERALL PAVEMENT CONDITION</Text>
                                    <Text size="2" weight="medium">{summary?.overall_pavement_condition || 'Fair to Good'}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray" weight="bold">EXECUTIVE SUBMISSION TIME</Text>
                                    <Badge color="green" variant="soft">{summary?.reported_to_ceo || '09:15 AM'}</Badge>
                                    <Text size="1" color="gray" mt="2" weight="bold">PREPARED BY</Text>
                                    <Text size="2" weight="medium">{summary?.report_prepared_by || 'QC Department'}</Text>
                                </Box>
                            </Grid>
                        </Box>

                        {/* Severity Breakdown Bar */}
                        <Box p="3" mb="4" style={{ borderRadius: 12, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Flex justify="between" align="center" wrap="wrap" gap="3">
                                <Text size="2" weight="bold">Open Defects by Severity:</Text>
                                <Flex gap="3" align="center" wrap="wrap">
                                    <Flex gap="1" align="center">
                                        <Badge color="red" variant="solid">{summary?.open_by_severity?.critical ?? 0}</Badge>
                                        <Text size="2">Critical - Safety Hazard</Text>
                                    </Flex>
                                    <Flex gap="1" align="center">
                                        <Badge color="orange" variant="solid">{summary?.open_by_severity?.major ?? 0}</Badge>
                                        <Text size="2">Major</Text>
                                    </Flex>
                                    <Flex gap="1" align="center">
                                        <Badge color="amber" variant="solid">{summary?.open_by_severity?.moderate ?? 0}</Badge>
                                        <Text size="2">Moderate</Text>
                                    </Flex>
                                    <Flex gap="1" align="center">
                                        <Badge color="blue" variant="solid">{summary?.open_by_severity?.minor ?? 0}</Badge>
                                        <Text size="2">Minor</Text>
                                    </Flex>
                                </Flex>
                            </Flex>
                        </Box>

                        {/* Defect Register Table */}
                        <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Table.Root size="2" style={{ minWidth: 960, width: '100%' }}>
                                <Table.Header style={{
                                    position: 'sticky',
                                    top: 0,
                                    zIndex: 2,
                                    background: 'var(--aero-surface, var(--color-background))',
                                    backdropFilter: 'blur(8px)',
                                    boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                }}>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ minWidth: 130 }}>SL / ID</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 260 }}>Defect Category & Extent</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 150 }}>Chainage & Carriageway</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>Severity</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Target Repair</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Responsible Party</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>Status</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {defects.length === 0 ? (
                                        <Table.Row>
                                            <Table.Cell colSpan={7} style={{ textAlign: 'center', padding: '32px 0' }}>
                                                <Text size="2" color="gray">No defect observations logged for this report date.</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ) : (
                                        defects.map((d, index) => {
                                            const isOverdue = d.sla_due_at && new Date(d.sla_due_at) < new Date() && !['rectified', 'verified_closed'].includes(d.status);

                                            return (
                                                <Table.Row key={d.id} align="center">
                                                    <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                                        {d.excel_sl ? `#${d.excel_sl} (${d.defect_number})` : d.defect_number}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text weight="bold" style={{ display: 'block' }}>{d.title}</Text>
                                                        <Text size="1" color="gray">{d.description}</Text>
                                                        {d.photo_reference && (
                                                            <Badge size="1" color="indigo" variant="soft" mt="1">{d.photo_reference}</Badge>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text size="2" weight="medium">{d.chainage}</Text>
                                                        <Text size="1" color="gray">{d.location_carriageway || d.direction}</Text>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        {getSeverityBadge(d.severity)}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        {isOverdue ? (
                                                            <Badge color="red" variant="solid">OVERDUE</Badge>
                                                        ) : (
                                                            <Box>
                                                                <Text size="2" color="gray">{d.sla_hours}h SLA</Text>
                                                                {d.target_repair_date && (
                                                                    <Text size="1" color="gray">Due: {d.target_repair_date.substring(0, 10)}</Text>
                                                                )}
                                                            </Box>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text size="2">{d.responsible_party || 'O&M Contractor'}</Text>
                                                        {d.recommended_action && (
                                                            <Text size="1" color="gray" style={{ display: 'block' }}>{d.recommended_action}</Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        {getStatusBadge(d.status)}
                                                    </Table.Cell>
                                                </Table.Row>
                                            );
                                        })
                                    )}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
}
