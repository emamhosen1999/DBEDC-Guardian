import React, { useState } from 'react';
import { Alert, Badge, Button, Card, DateRange, Icon } from '@/Components/Cyber';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import PolicyForm from '@/Forms/PolicyForm';

const statusColor = { draft: 'secondary', active: 'success', archived: 'warning' };

const scopeLabel = (p, employees, departments, designations) => {
    if (p.scope_type === 'org') return 'Organization';
    const items = p.scope_type === 'user' ? employees : p.scope_type === 'department' ? departments : designations;
    const target = items.find(item => String(item.id) === String(p.scope_id));
    const label = p.scope_type.charAt(0).toUpperCase() + p.scope_type.slice(1);
    return `${label}: ${target?.name || target?.title || p.scope_id}`;
};

export default function PoliciesManager({ employees = [], departments = [], designations = [] }) {
    const qc = useQueryClient();
    const [formOpen, setFormOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState(null);
    const [simRange, setSimRange] = useState({ from: '', to: '' });
    const [simPolicy, setSimPolicy] = useState(null);
    const [simResult, setSimResult] = useState(null);
    const [simError, setSimError] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['policies'],
        queryFn: () => requestJson('get', '/attendance/policies'),
    });

    const policies = data?.policies || [];

    const refresh = () => qc.invalidateQueries({ queryKey: ['policies'] });

    const activate = useMutation({
        mutationFn: (id) => requestJson('post', `/attendance/policies/${id}/activate`),
        onSuccess: () => {
            showToast.success('Policy activated.');
            refresh();
        },
        onError: (e) => showToast.error(e?.message || 'Failed to activate policy.'),
    });

    const simulate = useMutation({
        mutationFn: (payload) => requestJson('post', '/attendance/policies/simulate', { data: payload }),
        onSuccess: (res) => { setSimResult(res); setSimError(''); },
        onError: (e) => { setSimError(e?.message || 'Failed to simulate policy.'); setSimResult(null); },
    });

    const openNew = () => { setEditingPolicy(null); setFormOpen(true); };
    const openEdit = (p) => { setEditingPolicy(p); setFormOpen(true); };

    const runSimulation = (p) => {
        if (!simRange.from || !simRange.to) {
            showToast.error('Pick a from/to date range first.');
            return;
        }
        setSimPolicy(p.id);
        setSimResult(null);
        simulate.mutate({
            from: simRange.from,
            to: simRange.to,
            name: p.name,
            scope_type: p.scope_type,
            scope_id: p.scope_id,
            effective_from: p.effective_from,
            effective_to: p.effective_to,
            punch_strictness: p.punch_strictness,
            outside_window_minutes: p.outside_window_minutes,
            grace_tiers: p.grace_tiers,
            rounding: p.rounding,
        });
    };

    return (
        <div className="dl-row">
            <div className="dl-col dl-col--12">
                <Card
                    id="attendance-policies"
                    title="Attendance policies"
                    flush
                    actions={<Button size="sm" onClick={openNew}><Icon name="plus-lg" /> New policy</Button>}
                >
                    <div className="cy-toolbar" role="group" aria-label="Preview range">
                        <div className="cy-toolbar__group">
                            <DateRange
                                size="sm"
                                startLabel="Preview from"
                                endLabel="Preview to"
                                value={{ start: simRange.from, end: simRange.to }}
                                onChange={({ start, end }) => setSimRange({ from: start, to: end })}
                            />
                        </div>
                        <div className="cy-toolbar__group cy-toolbar__group--end"><span className="cy-toolbar__note">Pick a date range, then use "Preview impact" on a policy row below.</span></div>
                    </div>

                    <div className="cy-dt" tabIndex={0} role="region" aria-label="Attendance policies, scrollable">
                        <table className="cy-table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th><th scope="col">Scope</th><th scope="col">Strictness</th><th scope="col">Status</th>
                                    <th scope="col">Version</th><th scope="col">Effective</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {isLoading && (
                                    <tr><td colSpan={7} className="cy-cell--empty"><div className="cy-empty cy-empty--flat" role="status"><p className="cy-empty__text">Loading policies…</p></div></td></tr>
                                )}
                                {!isLoading && policies.length === 0 && (
                                    <tr><td colSpan={7} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><Icon name="shield-check" className="cy-empty__icon" /><p className="cy-empty__text">No policies yet.</p></div></td></tr>
                                )}
                                {!isLoading && policies.map(p => (
                                    <React.Fragment key={p.id}>
                                        <tr>
                                            <td>{p.name}</td>
                                            <td className="cy-muted">{scopeLabel(p, employees, departments, designations)}</td>
                                            <td><Badge soft color="secondary">{p.punch_strictness}</Badge></td>
                                            <td><Badge soft color={statusColor[p.status] || 'secondary'}>{p.status}</Badge></td>
                                            <td>v{p.version}</td>
                                            <td className="cy-muted cy-nowrap">{p.effective_from} → {p.effective_to || '∞'}</td>
                                            <td>
                                                <span className="cy-actions">
                                                    {p.status === 'draft' && (
                                                        <Button size="sm" color="default" variant="outline" onClick={() => openEdit(p)} aria-label={`Edit policy ${p.name}`}>Edit</Button>
                                                    )}
                                                    {p.status !== 'active' && (
                                                        <Button size="sm" color="success" loading={activate.isPending} onClick={() => activate.mutate(p.id)} aria-label={`Activate policy ${p.name}`}>Activate</Button>
                                                    )}
                                                    <Button size="sm" color="theme" variant="outline" loading={simulate.isPending && simPolicy === p.id} onClick={() => runSimulation(p)} aria-label={`Preview impact of policy ${p.name}`}>Preview impact</Button>
                                                </span>
                                            </td>
                                        </tr>
                                        {simPolicy === p.id && (simResult || simError) && (
                                            <tr>
                                                <td colSpan={7} className="cy-simrow">
                                                    {simError ? (
                                                        <Alert tone="danger">{simError}</Alert>
                                                    ) : (
                                                        <div role="status">
                                                            <b>{simResult.changed}/{simResult.days} day-statuses would change</b>
                                                            {simResult.samples?.length > 0 && (
                                                                <table className="cy-table cy-table--inner">
                                                                    <thead>
                                                                        <tr><th scope="col">User</th><th scope="col">Date</th><th scope="col">Before</th><th scope="col">After</th></tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        {simResult.samples.slice(0, 5).map((s, i) => (
                                                                            <tr key={i}>
                                                                                <td>#{s.user_id}</td>
                                                                                <td>{s.date}</td>
                                                                                <td><Badge soft color="secondary">{s.before_status}</Badge></td>
                                                                                <td><Badge soft color="theme">{s.after_status}</Badge></td>
                                                                            </tr>
                                                                        ))}
                                                                    </tbody>
                                                                </table>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </React.Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            <PolicyForm
                open={formOpen}
                onOpenChange={setFormOpen}
                onSaved={refresh}
                policy={editingPolicy}
                employees={employees}
                departments={departments}
                designations={designations}
            />
        </div>
    );
}
