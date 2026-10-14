import React, { useState } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Button, Card, Icon, Pagination } from '@/Components/Cyber';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import ShiftAssignmentForm from '@/Forms/ShiftAssignmentForm';
import ConfirmDialog from './ConfirmDialog';

export default function AssignmentManager({ employees = [], departments = [], designations = [] }) {
    const { auth } = usePage().props;
    const isGlobalUser = auth?.isSuperAdmin || auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Administrator') || auth?.roles?.includes('HR Manager') || auth?.permissions?.includes('attendance.settings');
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    /* Page and size live in the URL under an `asg_` prefix (this manager is
       mounted inside the Attendance settings, where every tab stays mounted). */
    const f = useQueryFilters({ mode: 'client', pageKey: 'asg_page', debounceKeys: [], defaults: { asg_page: 1, asg_per: 20 } });
    const page = f.values.asg_page;
    const perPage = f.values.asg_per;
    const setPage = f.setPage;
    const setPerPage = (v) => f.set('asg_per', v);

    const { data, isLoading } = useQuery({
        queryKey: ['shift-assignments'],
        queryFn: () => requestJson('get', '/attendance/shift-assignments'),
    });

    const assignments = data?.assignments || [];

    const refresh = () => qc.invalidateQueries({ queryKey: ['shift-assignments'] });

    const scopeLabel = (a) => {
        if (a.scope_type === 'org') return 'Organization';
        const list = a.scope_type === 'department'
            ? departments
            : a.scope_type === 'designation'
                ? designations
                : employees;
        const found = list.find(x => String(x.id) === String(a.scope_id));
        const name = found ? (found.name || found.title) : `#${a.scope_id}`;
        const label = a.scope_type.charAt(0).toUpperCase() + a.scope_type.slice(1);
        return `${label}: ${name}`;
    };

    const [deleting, setDeleting] = useState(null);
    const remove = async (id) => {
        setDeleting(null);
        try {
            await requestJson('delete', `/attendance/shift-assignments/${id}`);
            showToast.success('Assignment deleted.');
            refresh();
        } catch (e) {
            showToast.error(e?.message || 'Failed to delete assignment.');
        }
    };

    const pageRows = assignments.slice((page - 1) * perPage, page * perPage);
    const footer = assignments.length > perPage || page > 1 ? (
        <Pagination
            pagination={{ currentPage: page, perPage, total: assignments.length }}
            onPageChange={setPage}
            onRowsPerPageChange={setPerPage}
        />
    ) : null;

    return (
        <div className="dl-row">
            <div className="dl-col dl-col--12">
                <Card
                    id="attendance-assignments"
                    title="Shift assignments"
                    flush
                    footer={footer}
                    actions={<Button size="sm" onClick={() => { setEditing(null); setOpen(true); }}><Icon name="plus-lg" /> Assign shift</Button>}
                >
                    <div className="cy-dt" tabIndex={0} role="region" aria-label="Shift assignments, scrollable">
                        <table className="cy-table">
                            <thead>
                                <tr>
                                    <th scope="col">Scope</th>
                                    <th scope="col">Shift</th>
                                    <th scope="col">Effective</th>
                                    <th scope="col">Priority</th>
                                    {isGlobalUser && <th scope="col">Assigned by</th>}
                                    <th scope="col"><span className="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {isLoading ? (
                                    <tr><td colSpan={isGlobalUser ? 6 : 5} className="cy-cell--empty"><div className="cy-empty cy-empty--flat" role="status"><p className="cy-empty__text">Loading assignments…</p></div></td></tr>
                                ) : assignments.length === 0 ? (
                                    <tr><td colSpan={isGlobalUser ? 6 : 5} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><Icon name="calendar2-week" className="cy-empty__icon" /><p className="cy-empty__text">No assignments yet.</p></div></td></tr>
                                ) : pageRows.map(a => (
                                    <tr key={a.id}>
                                        <td>{scopeLabel(a)}</td>
                                        <td>
                                            {a.shift
                                                ? <Badge soft>{a.shift.code}</Badge>
                                                : a.rotation_pattern
                                                    ? <span className="cy-muted">Pattern: {a.rotation_pattern.name}</span>
                                                    : <span className="cy-muted">-</span>}
                                        </td>
                                        <td className="cy-muted cy-nowrap">{a.effective_from} → {a.effective_to || '∞'}</td>
                                        <td>{a.priority ?? 0}</td>
                                        {isGlobalUser && <td className="cy-muted">{a.assigner?.name || 'System'}</td>}
                                        <td>
                                            <span className="cy-actions">
                                                <button type="button" className="cy-iconbtn" onClick={() => { setEditing(a); setOpen(true); }} title="Edit / end-date" aria-label={`Edit assignment: ${scopeLabel(a)}`}><Icon name="pencil" /></button>
                                                <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={() => setDeleting(a)} title="Delete" aria-label={`Delete assignment: ${scopeLabel(a)}`}><Icon name="trash" /></button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            {deleting && <ConfirmDialog title="Delete assignment" message={`Delete this assignment (${scopeLabel(deleting)})?`} onClose={() => setDeleting(null)} onConfirm={() => remove(deleting.id)} />}
            <ShiftAssignmentForm
                open={open}
                onOpenChange={(v) => { setOpen(v); if (!v) setEditing(null); }}
                onSaved={refresh}
                assignment={editing}
                employees={employees}
                departments={departments}
                designations={designations}
            />
        </div>
    );
}
