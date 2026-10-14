import React, { useState } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Button, Card, Icon, Pagination, Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import ShiftForm from '@/Forms/ShiftForm';
import RotationPatternForm from '@/Forms/RotationPatternForm';
import AssignmentManager from '@/Pages/Attendance/Components/AssignmentManager';
import ConfirmDialog from '@/Pages/Attendance/Components/ConfirmDialog';

const TABS_ID = 'attendance-shifts';

export default function ShiftsSettings() {
    const { auth, employees = [], departments = [], designations = [] } = usePage().props;
    /* Creator names are for company-wide viewers only: the server withholds them from everyone else. */
    const isGlobalUser = Boolean(auth?.scope?.global);
    /* Shift DEFINITIONS and rotation PATTERNS are company-wide configuration (attendance.settings).
       Assigning shifts to employees is per-employee work (attendance.roster.manage) and lives in the
       Assignments sub-tab, so a roster manager sees the catalogue read-only. */
    const canConfigure = Boolean(auth?.isSuperAdmin || auth?.permissions?.includes('attendance.settings'));
    /* A department admin (attendance.roster.manage) may create templates OWNED by his department: the
       server decides per template which rows are his to edit (`can_manage`). */
    const canAdd = canConfigure || Boolean(auth?.permissions?.includes('attendance.roster.manage'));
    const canEditRow = (row) => (typeof row?.can_manage === 'boolean' ? row.can_manage : canConfigure);
    const showActions = canAdd;
    const qc = useQueryClient();
    /* Sub-tab and both lists' pages live in the URL under an `s_` prefix
       (every Attendance tab stays mounted). */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 's_page',
        debounceKeys: [],
        defaults: { s_sub: 'shifts', s_page: 1, s_per: 20, s_ppage: 1, s_pper: 20 },
    });
    const activeSubTab = ['patterns', 'assignments'].includes(f.values.s_sub) ? f.values.s_sub : 'shifts';
    const setActiveSubTab = (v) => f.commit({ s_sub: v }, { resetPage: false });
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [patternOpen, setPatternOpen] = useState(false);
    const [editingPattern, setEditingPattern] = useState(null);

    const shiftsPage = f.values.s_page;
    const shiftsPerPage = f.values.s_per;
    const setShiftsPage = f.setPage;
    const setShiftsPerPage = (v) => f.set('s_per', v);
    const patternsPage = f.values.s_ppage;
    const patternsPerPage = f.values.s_pper;
    const setPatternsPage = (p) => f.commit({ s_ppage: p }, { resetPage: false, replace: false });
    const setPatternsPerPage = (v) => f.commit({ s_pper: v, s_ppage: 1 }, { resetPage: false });

    const { data, isLoading } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
    });

    const shifts = data?.shifts || [];

    const { data: patternsData, isLoading: patternsLoading } = useQuery({
        queryKey: ['rotation-patterns'],
        queryFn: () => requestJson('get', '/attendance/rotation-patterns'),
    });

    const patterns = patternsData?.patterns || [];

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ['shifts'] });
        qc.invalidateQueries({ queryKey: ['rotation-patterns'] });
    };

    /* What is awaiting delete confirmation: { kind: 'shift' | 'pattern', row } */
    const [deleting, setDeleting] = useState(null);
    const remove = async (s) => {
        setDeleting(null);
        try {
            await requestJson('delete', `/attendance/shifts/${s.id}`);
            showToast.success('Shift deleted.');
            refresh();
        } catch (err) {
            showToast.error(err?.message || 'Failed to delete shift.');
        }
    };

    const removePattern = async (p) => {
        setDeleting(null);
        try {
            await requestJson('delete', `/attendance/rotation-patterns/${p.id}`);
            showToast.success('Rotation pattern deleted.');
            refresh();
        } catch (err) {
            showToast.error(err?.message || 'Failed to delete rotation pattern.');
        }
    };

    const formatSequence = (definition) => {
        if (!definition || !Array.isArray(definition)) return null;
        return definition.map((day, idx) => {
            const isLast = idx === definition.length - 1;
            if (day === null || day === undefined) {
                return (
                    <span key={idx} className="cy-seq">
                        <Badge soft color="secondary">Off</Badge>
                        {!isLast && <span className="cy-seq__arrow" aria-hidden="true">→</span>}
                    </span>
                );
            }
            const shiftObj = shifts.find(s => s.id === day);
            return (
                <span key={idx} className="cy-seq">
                    <Badge style={{ background: shiftObj?.color || undefined, borderColor: shiftObj?.color || undefined, color: shiftObj?.color ? '#fff' : undefined }}>
                        {shiftObj ? shiftObj.code : `Shift #${day}`}
                    </Badge>
                    {!isLast && <span className="cy-seq__arrow" aria-hidden="true">→</span>}
                </span>
            );
        });
    };

    const paginatedShifts = shifts.slice((shiftsPage - 1) * shiftsPerPage, shiftsPage * shiftsPerPage);
    const paginatedPatterns = patterns.slice((patternsPage - 1) * patternsPerPage, patternsPage * patternsPerPage);

    const subTabs = [
        { key: 'shifts', label: 'Shifts' },
        { key: 'patterns', label: 'Rotation Patterns' },
        { key: 'assignments', label: 'Shift Assignments' },
    ];
    const panel = (key) => ({ role: 'tabpanel', id: panelId(TABS_ID, key), 'aria-labelledby': tabId(TABS_ID, key) });
    const emptyRow = (cols, icon, text, hint) => (
        <tr><td colSpan={cols} className="cy-cell--empty">
            <div className="cy-empty cy-empty--flat"><Icon name={icon} className="cy-empty__icon" /><p className="cy-empty__text">{text}</p>{hint && <p className="cy-empty__text">{hint}</p>}</div>
        </td></tr>
    );
    const rowActions = (row, label, onEdit, onDelete) => canEditRow(row) && (
        <span className="cy-actions">
            <button type="button" className="cy-iconbtn" onClick={onEdit} aria-label={`Edit ${label}`} title="Edit"><Icon name="pencil" /></button>
            <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={onDelete} aria-label={`Delete ${label}`} title="Delete"><Icon name="trash" /></button>
        </span>
    );
    const shiftCols = 5 + (isGlobalUser ? 1 : 0) + (showActions ? 1 : 0);
    const patternCols = 6 + (isGlobalUser ? 1 : 0) + (showActions ? 1 : 0);

    return (
        <>
            <Tabs tabs={subTabs} value={activeSubTab} onChange={setActiveSubTab} idPrefix={TABS_ID} label="Shift management sections" className="cy-tabs--scroll" />

            {activeSubTab === 'shifts' && (
                <div className="dl-page" {...panel('shifts')}>
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <Card
                                id="attendance-shifts-list"
                                title="Manage shifts"
                                flush
                                actions={canAdd ? <Button size="sm" onClick={() => { setEditing(null); setOpen(true); }}><Icon name="plus-lg" /> Add shift</Button> : null}
                                footer={shifts.length > shiftsPerPage || shiftsPage > 1 ? <Pagination pagination={{ currentPage: shiftsPage, perPage: shiftsPerPage, total: shifts.length }} onPageChange={setShiftsPage} onRowsPerPageChange={setShiftsPerPage} /> : null}
                            >
                                <div className="cy-dt" tabIndex={0} role="region" aria-label="Shifts, scrollable">
                                    <table className="cy-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Name</th><th scope="col">Code</th><th scope="col">Window</th><th scope="col">Owner</th><th scope="col">Status</th>
                                                {isGlobalUser && <th scope="col">Created by</th>}
                                                {showActions && <th scope="col"><span className="visually-hidden">Actions</span></th>}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {isLoading ? emptyRow(shiftCols, 'clock', 'Loading shifts…')
                                                : shifts.length === 0 ? emptyRow(shiftCols, 'clock', 'No shifts yet.', canAdd ? 'Click Add shift above to create one.' : null)
                                                    : paginatedShifts.map(s => (
                                                        <tr key={s.id}>
                                                            <td><b>{s.name}</b></td>
                                                            <td><Badge style={{ background: s.color || undefined, borderColor: s.color || undefined, color: s.color ? '#fff' : undefined }}>{s.code}</Badge></td>
                                                            <td className="cy-muted cy-nowrap">{s.start_time}–{s.end_time}{s.crosses_midnight ? ' (+1)' : ''}</td>
                                                            <td><Badge soft color={s.department ? 'info' : 'secondary'}>{s.department?.name || 'Company-wide'}</Badge></td>
                                                            <td><Badge soft color={s.is_active ? 'success' : 'secondary'}>{s.is_active ? 'Active' : 'Inactive'}</Badge></td>
                                                            {isGlobalUser && <td className="cy-muted">{s.creator?.name || 'System'}</td>}
                                                            {showActions && <td>{rowActions(s, `shift ${s.name}`, () => { setEditing(s); setOpen(true); }, () => setDeleting({ kind: 'shift', row: s }))}</td>}
                                                        </tr>
                                                    ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                        </div>
                    </div>
                </div>
            )}

            {activeSubTab === 'patterns' && (
                <div className="dl-page" {...panel('patterns')}>
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <Card
                                id="attendance-patterns-list"
                                title="Rotation patterns"
                                flush
                                actions={canAdd ? <Button size="sm" onClick={() => { setEditingPattern(null); setPatternOpen(true); }}><Icon name="plus-lg" /> Add pattern</Button> : null}
                                footer={patterns.length > patternsPerPage || patternsPage > 1 ? <Pagination pagination={{ currentPage: patternsPage, perPage: patternsPerPage, total: patterns.length }} onPageChange={setPatternsPage} onRowsPerPageChange={setPatternsPerPage} /> : null}
                            >
                                <div className="cy-dt" tabIndex={0} role="region" aria-label="Rotation patterns, scrollable">
                                    <table className="cy-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Name</th><th scope="col">Code</th><th scope="col">Cycle length</th><th scope="col">Sequence preview</th><th scope="col">Owner</th><th scope="col">Status</th>
                                                {isGlobalUser && <th scope="col">Created by</th>}
                                                {showActions && <th scope="col"><span className="visually-hidden">Actions</span></th>}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {patternsLoading ? emptyRow(patternCols, 'arrow-repeat', 'Loading rotation patterns…')
                                                : patterns.length === 0 ? emptyRow(patternCols, 'arrow-repeat', 'No rotation patterns yet.', canAdd ? 'Click Add pattern above to create one.' : null)
                                                    : paginatedPatterns.map(p => (
                                                        <tr key={p.id}>
                                                            <td><b>{p.name}</b></td>
                                                            <td><Badge soft color="info">{p.code}</Badge></td>
                                                            <td className="cy-nowrap">{p.cycle_length_days} days</td>
                                                            <td><span className="cy-seqrow">{formatSequence(p.definition)}</span></td>
                                                            <td><Badge soft color={p.department ? 'info' : 'secondary'}>{p.department?.name || 'Company-wide'}</Badge></td>
                                                            <td><Badge soft color={p.is_active ? 'success' : 'secondary'}>{p.is_active ? 'Active' : 'Inactive'}</Badge></td>
                                                            {isGlobalUser && <td className="cy-muted">{p.creator?.name || 'System'}</td>}
                                                            {showActions && <td>{rowActions(p, `pattern ${p.name}`, () => { setEditingPattern(p); setPatternOpen(true); }, () => setDeleting({ kind: 'pattern', row: p }))}</td>}
                                                        </tr>
                                                    ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                        </div>
                    </div>
                </div>
            )}

            {activeSubTab === 'assignments' && (
                <div className="dl-page" {...panel('assignments')}>
                    <AssignmentManager employees={employees} departments={departments} designations={designations} />
                </div>
            )}

            {deleting && (
                <ConfirmDialog
                    title={deleting.kind === 'shift' ? 'Delete shift' : 'Delete rotation pattern'}
                    message={deleting.kind === 'shift' ? `Delete shift "${deleting.row.name}"? This cannot be undone.` : `Delete rotation pattern "${deleting.row.name}"? This cannot be undone.`}
                    onClose={() => setDeleting(null)}
                    onConfirm={() => (deleting.kind === 'shift' ? remove(deleting.row) : removePattern(deleting.row))}
                />
            )}
            <ShiftForm open={open} onOpenChange={setOpen} initial={editing} onSaved={refresh} departments={departments} />
            <RotationPatternForm open={patternOpen} onOpenChange={setPatternOpen} initial={editingPattern} onSaved={refresh} departments={departments} />
        </>
    );
}
