import { Head, router } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import axios from 'axios';
import {
    Card, Flex, Box, Grid, Heading, Text, Badge, Button, IconButton, TextField, TextArea,
    Select, Dialog, Table, ScrollArea, Separator, Tooltip,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon, PlusIcon, Pencil1Icon, TrashIcon, Cross2Icon, DotsHorizontalIcon,
} from '@radix-ui/react-icons';
import App from '@/Layouts/App.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';

const MONO = "'Roboto Mono', ui-monospace, monospace";
const ROAD_KM = 48;

const SEV = {
    critical: { c: 'var(--tomato-9)', t: 'var(--tomato-11)', s: 'var(--tomato-a3)', label: 'Critical', color: 'tomato' },
    major:    { c: 'var(--amber-9)',  t: 'var(--amber-11)',  s: 'var(--amber-a3)',  label: 'Major', color: 'amber' },
    minor:    { c: 'var(--iris-9)',   t: 'var(--iris-11)',   s: 'var(--iris-a3)',   label: 'Minor', color: 'iris' },
};
const STATUS = {
    open:                { label: 'Open', color: 'tomato' },
    under_review:        { label: 'Under IE review', color: 'blue' },
    action_assigned:     { label: 'Action assigned', color: 'plum' },
    action_in_progress:  { label: 'In process', color: 'amber' },
    verified:            { label: 'IE-recommended (RHD consent)', color: 'jade' },
    closed:              { label: 'Closed', color: 'gray' },
};
const chLabel = (m) => (m == null ? null : `Ch ${Math.floor(m / 1000)}+${String(m % 1000).padStart(3, '0')}`);

export default function NcrRegister({ ncrs = [], stats = {}, options = {}, can = {} }) {
    const [q, setQ] = useState('');
    const [fStatus, setFStatus] = useState('all');
    const [fSev, setFSev] = useState('all');
    const [detail, setDetail] = useState(null);
    const [editing, setEditing] = useState(null); // null | {} (new) | ncr (edit)
    const [busy, setBusy] = useState(false);

    const filtered = useMemo(() => ncrs.filter((n) => {
        if (fStatus === 'open' && !n.is_open) return false;
        if (fStatus !== 'all' && fStatus !== 'open' && n.status !== fStatus) return false;
        if (fSev !== 'all' && n.severity !== fSev) return false;
        if (q) {
            const hay = `${n.ncr_number} ${n.title} ${n.description} ${n.department ?? ''}`.toLowerCase();
            if (!hay.includes(q.toLowerCase())) return false;
        }
        return true;
    }), [ncrs, q, fStatus, fSev]);

    const refresh = () => router.reload({ only: ['ncrs', 'stats'] });

    const doTransition = async (ncr, status) => {
        setBusy(true);
        try {
            const { data } = await axios.patch(route('quality.ncr.transition', ncr.id), { status });
            setDetail(data.ncr); refresh();
        } finally { setBusy(false); }
    };
    const doDelete = async (ncr) => {
        if (!confirm(`Delete ${ncr.ncr_number}? This cannot be undone.`)) return;
        setBusy(true);
        try { await axios.delete(route('quality.ncr.destroy', ncr.id)); setDetail(null); refresh(); }
        finally { setBusy(false); }
    };

    return (
        <>
            <Head title="NCR Register" />
            <Box p={{ initial: '3', sm: '4', md: '5' }}>
                <Flex align="center" justify="between" wrap="wrap" gap="3" mb="4">
                    <Box>
                        <Text size="1" style={{ fontFamily: MONO, letterSpacing: '0.14em', textTransform: 'uppercase', color: 'var(--gray-11)' }}>
                            Quality · Independent Engineer register
                        </Text>
                        <Heading size="6" style={{ letterSpacing: '-0.02em' }}>Non-Conformance Reports</Heading>
                    </Box>
                    {can.create && (
                        <Button size="3" onClick={() => setEditing({})}><PlusIcon /> New NCR</Button>
                    )}
                </Flex>

                {/* KPI band */}
                <Grid columns={{ initial: '2', sm: '3', md: '6' }} gap="3" mb="4">
                    <Kpi label="Issued" value={stats.issued} tone="gray" />
                    <Kpi label="Open" value={stats.open} tone="tomato" />
                    <Kpi label="In process" value={stats.in_process} tone="amber" />
                    <Kpi label="Under IE review" value={stats.under_review} tone="blue" />
                    <Kpi label="RHD consent" value={stats.consent} tone="jade" />
                    <Kpi label="Closed" value={stats.closed} tone="gray" />
                </Grid>

                <Grid columns={{ initial: '1', md: '3' }} gap="3" mb="4">
                    <SeverityCard sev={stats.severity} />
                    <Box style={{ gridColumn: 'span 2' }}><ChainageMap ncrs={ncrs} onPick={(n) => setDetail(n)} /></Box>
                </Grid>

                {/* Filters */}
                <Flex align="center" gap="2" wrap="wrap" mb="3">
                    <TextField.Root placeholder="Search ref, title, chainage…" value={q} onChange={(e) => setQ(e.target.value)} style={{ minWidth: 240, flex: 1 }}>
                        <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    </TextField.Root>
                    <SelectFilter value={fStatus} onChange={setFStatus} placeholder="Status"
                        items={[['all', 'All statuses'], ['open', 'Open (all)'], ...Object.entries(STATUS).map(([k, v]) => [k, v.label])]} />
                    <SelectFilter value={fSev} onChange={setFSev} placeholder="Severity"
                        items={[['all', 'All severities'], ...Object.keys(SEV).map((k) => [k, SEV[k].label])]} />
                    <Text size="1" color="gray" style={{ fontFamily: MONO }}>{filtered.length} of {ncrs.length}</Text>
                </Flex>

                {/* Register table */}
                <Card>
                    <ScrollArea>
                        <Table.Root variant="ghost" size="1">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Ref</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Non-conformity</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Severity</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Chainage</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Detected</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {filtered.map((n) => (
                                    <Table.Row key={n.id} style={{ cursor: 'pointer' }} onClick={() => setDetail(n)}>
                                        <Table.Cell><Text style={{ fontFamily: MONO }} weight="medium">{n.ncr_number}</Text></Table.Cell>
                                        <Table.Cell><Text style={{ display: 'block', maxWidth: 380, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{n.title}</Text></Table.Cell>
                                        <Table.Cell><Badge color={SEV[n.severity]?.color} variant="soft">{SEV[n.severity]?.label}</Badge></Table.Cell>
                                        <Table.Cell><Badge color={STATUS[n.status]?.color} variant="soft" highContrast>{STATUS[n.status]?.label}</Badge></Table.Cell>
                                        <Table.Cell><Text style={{ fontFamily: MONO }} color="gray">{chLabel(n.chainage_m) ?? '—'}</Text></Table.Cell>
                                        <Table.Cell><Text style={{ fontFamily: MONO }} color="gray">{n.detected_date ?? '—'}</Text></Table.Cell>
                                        <Table.Cell onClick={(e) => e.stopPropagation()}>
                                            <Flex gap="1">
                                                {can.update && <Tooltip content="Edit"><IconButton size="1" variant="ghost" onClick={() => setEditing(n)}><Pencil1Icon /></IconButton></Tooltip>}
                                                {can.delete && <Tooltip content="Delete"><IconButton size="1" variant="ghost" color="red" onClick={() => doDelete(n)}><TrashIcon /></IconButton></Tooltip>}
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                                {filtered.length === 0 && (
                                    <Table.Row><Table.Cell colSpan={7}><Text color="gray" align="center" style={{ display: 'block', padding: 24 }}>No NCRs match the filters.</Text></Table.Cell></Table.Row>
                                )}
                            </Table.Body>
                        </Table.Root>
                    </ScrollArea>
                </Card>
            </Box>

            {/* Detail drawer */}
            <Dialog.Root open={!!detail} onOpenChange={(o) => !o && setDetail(null)}>
                <Dialog.Content maxWidth="620px">
                    {detail && <DetailView ncr={detail} can={can} busy={busy}
                        onTransition={doTransition} onEdit={() => { setEditing(detail); setDetail(null); }} onDelete={() => doDelete(detail)} onClose={() => setDetail(null)} />}
                </Dialog.Content>
            </Dialog.Root>

            {/* Create / edit modal */}
            <NcrForm editing={editing} options={options} onClose={() => setEditing(null)} onSaved={(ncr) => { setEditing(null); if (detail) setDetail(ncr); refresh(); }} />
        </>
    );
}

/* ── KPI + severity ─────────────────────────────────────────────── */
function Kpi({ label, value, tone }) {
    return (
        <Card>
            <Flex direction="column" gap="1">
                <Text size="1" style={{ fontFamily: MONO, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--gray-11)' }}>{label}</Text>
                <Text style={{ fontFamily: MONO, fontWeight: 700, fontSize: 26, letterSpacing: '-0.03em', color: `var(--${tone}-11)` }}>{value ?? 0}</Text>
            </Flex>
        </Card>
    );
}
function SeverityCard({ sev = {} }) {
    const rows = [['critical', sev.critical], ['major', sev.major], ['minor', sev.minor]];
    return (
        <Card>
            <Text size="2" weight="medium" mb="2" style={{ display: 'block' }}>Open by severity</Text>
            <Flex direction="column" gap="2">
                {rows.map(([k, v]) => (
                    <Flex key={k} align="center" gap="3">
                        <Box style={{ width: 10, height: 10, borderRadius: 3, background: SEV[k].c }} />
                        <Text size="2" style={{ flex: 1 }}>{SEV[k].label}</Text>
                        <Text style={{ fontFamily: MONO, fontWeight: 700 }}>{v ?? 0}</Text>
                    </Flex>
                ))}
            </Flex>
        </Card>
    );
}

/* ── chainage map ───────────────────────────────────────────────── */
function ChainageMap({ ncrs, onPick }) {
    const pts = ncrs.filter((n) => n.chainage_m != null && n.is_open);
    const W = 680, H = 92, y = 52;
    return (
        <Card style={{ height: '100%' }}>
            <Text size="2" weight="medium" style={{ display: 'block' }}>Open NCRs along the road</Text>
            <Text size="1" color="gray" style={{ fontFamily: MONO }}>Ch 0+000 → 48+000</Text>
            <Box style={{ overflowX: 'auto', marginTop: 6 }}>
                <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ minWidth: 520 }}>
                    <line x1="12" y1={y} x2={W - 12} y2={y} stroke="var(--gray-a6)" strokeWidth="2" strokeLinecap="round" />
                    {Array.from({ length: 7 }, (_, i) => i * 8).map((km) => {
                        const x = 12 + (km / ROAD_KM) * (W - 24);
                        return (<g key={km}>
                            <line x1={x} y1={y - 4} x2={x} y2={y + 4} stroke="var(--gray-a7)" />
                            <text x={x} y={y + 18} textAnchor="middle" fill="var(--gray-10)" fontSize="10" fontFamily="monospace">{km}</text>
                        </g>);
                    })}
                    {pts.map((n) => {
                        const x = 12 + (n.chainage_m / (ROAD_KM * 1000)) * (W - 24);
                        return (
                            <circle key={n.id} cx={x} cy={y} r="6" fill={SEV[n.severity]?.s} stroke={SEV[n.severity]?.c} strokeWidth="2"
                                style={{ cursor: 'pointer' }} onClick={() => onPick(n)}>
                                <title>{`${n.ncr_number} · ${chLabel(n.chainage_m)} · ${SEV[n.severity]?.label}`}</title>
                            </circle>
                        );
                    })}
                </svg>
            </Box>
        </Card>
    );
}

/* ── detail drawer ──────────────────────────────────────────────── */
function DetailView({ ncr, can, busy, onTransition, onEdit, onDelete, onClose }) {
    const next = {
        open: [['action_in_progress', 'Mark in process'], ['closed', 'Close']],
        under_review: [['verified', 'IE-recommend (consent)'], ['closed', 'Close']],
        action_assigned: [['action_in_progress', 'Mark in process']],
        action_in_progress: [['under_review', 'Send to IE review'], ['closed', 'Close']],
        verified: [['closed', 'Close']],
        closed: [['open', 'Re-open']],
    }[ncr.status] || [];
    return (
        <Flex direction="column" gap="3">
            <Flex align="start" justify="between" gap="3">
                <Box>
                    <Text style={{ fontFamily: MONO }} color="gray" size="2">{ncr.ncr_number}</Text>
                    <Dialog.Title style={{ margin: 0 }}>{ncr.title}</Dialog.Title>
                    <Flex gap="2" mt="2">
                        <Badge color={SEV[ncr.severity]?.color} variant="soft">{SEV[ncr.severity]?.label}</Badge>
                        <Badge color={STATUS[ncr.status]?.color} variant="soft" highContrast>{STATUS[ncr.status]?.label}</Badge>
                        {ncr.chainage_m != null && <Badge color="gray" variant="soft" style={{ fontFamily: MONO }}>{chLabel(ncr.chainage_m)}</Badge>}
                    </Flex>
                </Box>
                <Dialog.Close><IconButton variant="ghost" color="gray"><Cross2Icon /></IconButton></Dialog.Close>
            </Flex>
            <Separator size="4" />
            <Field label="Description" value={ncr.description} />
            {ncr.corrective_action && <Field label="Corrective action / IE remarks" value={ncr.corrective_action} />}
            {ncr.root_cause_analysis && <Field label="Root cause" value={ncr.root_cause_analysis} />}
            {ncr.preventive_action && <Field label="Preventive action" value={ncr.preventive_action} />}
            <Grid columns="2" gap="3">
                <Field label="Discipline" value={ncr.department ?? '—'} />
                <Field label="Detected" value={ncr.detected_date ?? '—'} mono />
                <Field label="Assigned to" value={ncr.assignee ?? '—'} />
                <Field label="Reported by" value={ncr.reporter ?? '—'} />
            </Grid>
            {can.update && next.length > 0 && (
                <>
                    <Separator size="4" />
                    <Text size="1" style={{ fontFamily: MONO, textTransform: 'uppercase', letterSpacing: '0.08em', color: 'var(--gray-11)' }}>Workflow</Text>
                    <Flex gap="2" wrap="wrap">
                        {next.map(([st, lbl]) => (
                            <Button key={st} variant="soft" disabled={busy} color={STATUS[st]?.color} onClick={() => onTransition(ncr, st)}>{lbl}</Button>
                        ))}
                    </Flex>
                </>
            )}
            <Separator size="4" />
            <Flex gap="2" justify="end">
                {can.update && <Button variant="soft" onClick={onEdit}><Pencil1Icon /> Edit</Button>}
                {can.delete && <Button variant="soft" color="red" disabled={busy} onClick={onDelete}><TrashIcon /> Delete</Button>}
            </Flex>
        </Flex>
    );
}
const Field = ({ label, value, mono }) => (
    <Box>
        <Text size="1" style={{ fontFamily: MONO, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--gray-11)', display: 'block', marginBottom: 2 }}>{label}</Text>
        <Text size="2" style={{ whiteSpace: 'pre-wrap', fontFamily: mono ? MONO : undefined }}>{value}</Text>
    </Box>
);

/* ── create / edit form ─────────────────────────────────────────── */
function NcrForm({ editing, options, onClose, onSaved }) {
    const isNew = editing && !editing.id;
    const [form, setForm] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    React.useEffect(() => {
        if (!editing) { setForm(null); return; }
        setErrors({});
        setForm({
            ncr_number: editing.ncr_number ?? '',
            title: editing.title ?? '',
            description: editing.description ?? '',
            severity: editing.severity ?? 'major',
            status: editing.status ?? 'open',
            department_id: editing.department_id ? String(editing.department_id) : '',
            assigned_to: editing.assigned_to ? String(editing.assigned_to) : '',
            detected_date: editing.detected_date ?? new Date().toISOString().slice(0, 10),
            corrective_action: editing.corrective_action ?? '',
            root_cause_analysis: editing.root_cause_analysis ?? '',
            preventive_action: editing.preventive_action ?? '',
        });
    }, [editing]);

    if (!editing || !form) return null;
    const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v?.target ? v.target.value : v }));

    const submit = async () => {
        setSaving(true); setErrors({});
        const payload = { ...form, department_id: form.department_id || null, assigned_to: form.assigned_to || null };
        try {
            const { data } = isNew
                ? await axios.post(route('quality.ncr.store'), payload)
                : await axios.put(route('quality.ncr.update', editing.id), payload);
            onSaved(data.ncr);
        } catch (e) {
            if (e.response?.status === 422) setErrors(e.response.data.errors || {});
            else alert('Save failed.');
        } finally { setSaving(false); }
    };

    return (
        <Dialog.Root open onOpenChange={(o) => !o && onClose()}>
            <Dialog.Content maxWidth="600px">
                <Dialog.Title>{isNew ? 'New NCR' : `Edit ${editing.ncr_number}`}</Dialog.Title>
                <Flex direction="column" gap="3" mt="3">
                    <FormRow label="Title" error={errors.title}>
                        <TextField.Root value={form.title} onChange={set('title')} placeholder="Short non-conformity title" />
                    </FormRow>
                    <FormRow label="Description" error={errors.description}>
                        <TextArea value={form.description} onChange={set('description')} rows={3} placeholder="Full description of the non-conformance" />
                    </FormRow>
                    <Grid columns="3" gap="3">
                        <FormRow label="Severity" error={errors.severity}>
                            <FormSelect value={form.severity} onChange={set('severity')} items={Object.keys(SEV).map((k) => [k, SEV[k].label])} />
                        </FormRow>
                        <FormRow label="Status" error={errors.status}>
                            <FormSelect value={form.status} onChange={set('status')} items={Object.entries(STATUS).map(([k, v]) => [k, v.label])} />
                        </FormRow>
                        <FormRow label="Detected" error={errors.detected_date}>
                            <TextField.Root type="date" value={form.detected_date} onChange={set('detected_date')} />
                        </FormRow>
                    </Grid>
                    <Grid columns="2" gap="3">
                        <FormRow label="Discipline" error={errors.department_id}>
                            <FormSelect value={form.department_id} onChange={set('department_id')} placeholder="—"
                                items={[['', '— none —'], ...(options.departments || []).map((d) => [String(d.id), d.name])]} />
                        </FormRow>
                        <FormRow label="Assigned to" error={errors.assigned_to}>
                            <FormSelect value={form.assigned_to} onChange={set('assigned_to')} placeholder="—"
                                items={[['', '— unassigned —'], ...(options.users || []).map((u) => [String(u.id), u.name])]} />
                        </FormRow>
                    </Grid>
                    <FormRow label="Corrective action / IE remarks" error={errors.corrective_action}>
                        <TextArea value={form.corrective_action} onChange={set('corrective_action')} rows={2} />
                    </FormRow>
                    {!isNew && (
                        <FormRow label="NCR reference" error={errors.ncr_number}>
                            <TextField.Root value={form.ncr_number} onChange={set('ncr_number')} style={{ fontFamily: MONO }} />
                        </FormRow>
                    )}
                </Flex>
                <Flex gap="3" mt="4" justify="end">
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button disabled={saving} onClick={submit}>{saving ? 'Saving…' : (isNew ? 'Create NCR' : 'Save changes')}</Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}

/* ── small form helpers ─────────────────────────────────────────── */
const FormRow = ({ label, error, children }) => (
    <Box>
        <Text size="1" style={{ fontFamily: MONO, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--gray-11)', display: 'block', marginBottom: 4 }}>{label}</Text>
        {children}
        {error && <Text size="1" color="red" style={{ display: 'block', marginTop: 2 }}>{Array.isArray(error) ? error[0] : error}</Text>}
    </Box>
);
const NONE = '__none__';
function FormSelect({ value, onChange, items, placeholder }) {
    return (
        <Select.Root value={value === '' || value == null ? NONE : String(value)} onValueChange={(v) => onChange(v === NONE ? '' : v)}>
            <Select.Trigger placeholder={placeholder} style={{ width: '100%' }} />
            <Select.Content>
                {items.map(([v, l]) => <Select.Item key={v || NONE} value={v === '' ? NONE : String(v)}>{l}</Select.Item>)}
            </Select.Content>
        </Select.Root>
    );
}
function SelectFilter({ value, onChange, items, placeholder }) {
    return (
        <Select.Root value={value} onValueChange={onChange}>
            <Select.Trigger placeholder={placeholder} variant="soft" />
            <Select.Content>
                {items.map(([v, l]) => <Select.Item key={v} value={v}>{l}</Select.Item>)}
            </Select.Content>
        </Select.Root>
    );
}

NcrRegister.layout = (page) => <App><ErrorBoundary>{page}</ErrorBoundary></App>;
