/**
 * LeaveSettingsPanel.jsx
 * Leave types CRUD, built only from the Cyber library (cards, form controls, table, dialog).
 * - Optimistic updates: deletions and edits update the table at once; if the API fails the change is rolled back.
 * - Each type has an explicit document rule: optional / required beyond N days / always required.
 */
import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Button, Card, FormField, Icon, Input, SelectInput, Switch } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { showToast } from '@/utils/toastUtils';
import { router } from '@inertiajs/react';
import axios from 'axios';

/* ── Confirm dialog ───────────────────────── */
const ConfirmModal = ({ open, onClose, onConfirm, loading, candidate }) => {
    if (!open) return null;
    return (
        <Dialog title="Delete leave type" onClose={() => { if (!loading) onClose(); }} className="cy-modal cy-modal--narrow">
            <div className="cy-modal__section">
                <p>Are you sure you want to delete <b>"{candidate?.type}"</b>? This permanently removes the leave type and its policies. This action cannot be undone.</p>
            </div>
            <div className="cy-modal__foot">
                <Button color="default" variant="outline" size="sm" onClick={onClose} disabled={loading}>Cancel</Button>
                <Button color="danger" size="sm" onClick={onConfirm} loading={loading}>Delete leave type</Button>
            </div>
        </Dialog>
    );
};

/* ════════════════════════════════════════════════════════════
   MAIN COMPONENT
   ═══════════════════════════════════════════════════════════ */
const EMPTY_FORM = {
    type:               '',
    days:               '',
    eligibility:        '',
    carry_forward:      false,
    earned_leave:       false,
    requires_approval:  true,
    auto_approve:       false,
    special_conditions: '',
    // Phase 3 — accrual policy
    accrual_method:     'annual_upfront',
    accrual_rate:       '',
    probation_months:   0,
    prorate_on_join:    true,
    carry_forward_cap:  '',
    carry_expiry_months:'',
    is_encashable:      false,
    allow_negative:     false,
    // Policy hardening — eligibility, documents, comp-off
    eligible_gender:    'all',
    min_service_months: '',
    max_encash_days:    '',
    requires_attachment_days: '',
    attachment_rule:    'optional',
    is_comp_off:        false,
};

export default function LeaveSettingsPanel({
    leaveTypes: initialTypes = [],
    isMobile = false,
    isActive = false,
    onSetHeaderActions,
}) {
    const [leaveTypes,    setLeaveTypes]    = useState(initialTypes);
    const [form,          setForm]          = useState(EMPTY_FORM);
    const [isEditing,     setIsEditing]     = useState(false);
    const [loading,       setLoading]       = useState(false);
    const [deleteLoading, setDeleteLoading] = useState(false);
    const [deleteModal,   setDeleteModal]   = useState({ open: false, candidate: null });
    /* The leave-type search lives in the URL under an `ls_` prefix. */
    const f = useQueryFilters({ mode: 'client', debounceKeys: ['ls_q'], defaults: { ls_q: '' } });
    const search = f.values.ls_q;
    const [isLoading,     setIsLoading]     = useState(false);

    useEffect(() => { setLeaveTypes(initialTypes); }, [initialTypes]);

    const refetch = useCallback(() => {
        setIsLoading(true);
        router.reload({
            only: ['leaveTypes'],
            onFinish: () => setIsLoading(false),
        });
    }, []);

    /* Header Actions */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <Button size="sm" color="default" variant="outline" onClick={() => refetch()} disabled={isLoading}>
                <Icon name="arrow-clockwise" /> {!isMobile && (isLoading ? 'Loading…' : 'Refresh')}
            </Button>
        );
        return () => onSetHeaderActions?.(null);
    }, [isActive, onSetHeaderActions, isMobile, isLoading, refetch]);

    /* Derived State */
    const isFormValid = useMemo(() =>
        form.type.trim() && form.days && parseInt(form.days) > 0,
    [form.type, form.days]);

    const filtered = useMemo(() => {
        if (!search) return leaveTypes;
        const q = search.toLowerCase();
        return leaveTypes.filter(lt =>
            lt.type.toLowerCase().includes(q) ||
            (lt.eligibility || '').toLowerCase().includes(q)
        );
    }, [leaveTypes, search]);

    /* Handlers */
    const setField = (key, val) => setForm(p => ({ ...p, [key]: val }));

    const resetForm = useCallback(() => {
        setForm(EMPTY_FORM);
        setIsEditing(false);
    }, []);

    const handleEdit = useCallback((lt) => {
        setForm({
            ...lt,
            carry_forward:     Boolean(lt.carry_forward),
            earned_leave:      Boolean(lt.earned_leave),
            requires_approval: Boolean(lt.requires_approval ?? true),
            auto_approve:      Boolean(lt.auto_approve),
            // Phase 3 — accrual policy
            accrual_method:    lt.accrual_method || (lt.earned_leave ? 'monthly' : 'annual_upfront'),
            accrual_rate:      lt.accrual_rate ?? '',
            probation_months:  lt.probation_months ?? 0,
            prorate_on_join:   Boolean(lt.prorate_on_join ?? true),
            carry_forward_cap: lt.carry_forward_cap ?? '',
            carry_expiry_months: lt.carry_expiry_months ?? '',
            is_encashable:     Boolean(lt.is_encashable),
            allow_negative:    Boolean(lt.allow_negative),
            eligible_gender:   lt.eligible_gender || 'all',
            min_service_months: lt.min_service_months ?? '',
            max_encash_days:   lt.max_encash_days ?? '',
            requires_attachment_days: lt.requires_attachment_days ?? '',
            attachment_rule:   lt.attachment_rule || (lt.requires_attachment_days != null ? 'beyond_days' : 'optional'),
            is_comp_off:       Boolean(lt.is_comp_off),
        });
        setIsEditing(true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    /* Optimistic Submit */
    const handleSubmit = async () => {
        if (!isFormValid) { showToast.error('Leave type name and days are required.'); return; }
        setLoading(true);

        // 'all' is a UI-only sentinel — the backend expects null for no gender restriction.
        const payload = { ...form, eligible_gender: form.eligible_gender === 'all' ? null : form.eligible_gender };

        if (isEditing) {
            const previousState = [...leaveTypes];
            // Optimistic update
            setLeaveTypes(p => p.map(lt => lt.id === form.id ? { ...form } : lt));
            resetForm();

            try {
                await axios.put(route('update-leave-type', { id: form.id }), payload);
                showToast.success('Leave type updated successfully.');
            } catch (e) {
                setLeaveTypes(previousState); // Rollback
                showToast.error(e.response?.data?.message || 'Failed to update. Rolling back.');
            } finally {
                setLoading(false);
            }
        } else {
            // Creation requires DB ID, cannot be fully optimistic
            try {
                const r = await axios.post(route('add-leave-type'), payload);
                setLeaveTypes(p => [...p, { ...form, id: r.data.id }]);
                resetForm();
                showToast.success('Leave type added successfully.');
            } catch (e) {
                showToast.error(e.response?.data?.message || 'Failed to add leave type.');
            } finally {
                setLoading(false);
            }
        }
    };

    /* Optimistic Delete */
    const confirmDelete = async () => {
        const candidate = deleteModal.candidate;
        if (!candidate) return;
        
        setDeleteLoading(true);
        const previousState = [...leaveTypes];
        
        // Optimistic delete
        setLeaveTypes(p => p.filter(lt => lt.id !== candidate.id));
        setDeleteModal({ open: false, candidate: null });

        try {
            await axios.delete(route('delete-leave-type', { id: candidate.id }));
            showToast.success('Leave type deleted.');
        } catch (e) {
            setLeaveTypes(previousState); // Rollback
            showToast.error(e.response?.data?.message || 'Failed to delete. Rolling back.');
        } finally {
            setDeleteLoading(false);
        }
    };

    const num = (key, props = {}) => ({ type: 'number', value: form[key], onChange: (e) => setField(key, e.target.value), ...props });
    const ACCRUAL = [{ value: 'annual_upfront', label: 'Annual (upfront)' }, { value: 'monthly', label: 'Monthly accrual' }, { value: 'none', label: 'None' }];
    const GENDER = [{ value: 'all', label: 'All employees' }, { value: 'male', label: 'Male only' }, { value: 'female', label: 'Female only' }];
    const DOC_RULE = [{ value: 'optional', label: 'Optional' }, { value: 'beyond_days', label: 'Required beyond N days' }, { value: 'always', label: 'Always required' }];
    const docRuleLabel = (lt) => {
        const rule = lt.attachment_rule || (lt.requires_attachment_days != null ? 'beyond_days' : 'optional');
        return rule === 'always' ? 'Always required' : rule === 'beyond_days' ? `Required over ${lt.requires_attachment_days} day(s)` : 'Optional';
    };

    /* ── Render ─────────────────────────────────────────────── */
    return (
        <div className="dl-page">
            <div className="dl-row">
                <div className="dl-col dl-col--12">
                    <Card id="leave-settings:form" title={isEditing ? 'Edit leave type' : 'Add new leave type'} flush>
                        <div className="cy-formsection"><div className="cy-formgrid">
                            <FormField label="Leave type" htmlFor="lt-type" required><Input id="lt-type" placeholder="e.g. Annual Leave, Sick Leave" value={form.type} onChange={(e) => setField('type', e.target.value)} /></FormField>
                            <FormField label="Number of days" htmlFor="lt-days" required><Input id="lt-days" {...num('days', { min: 0, placeholder: 'e.g. 21' })} /></FormField>
                            <FormField label="Eligibility criteria" htmlFor="lt-elig"><Input id="lt-elig" placeholder="e.g. After 1 year of service" value={form.eligibility} onChange={(e) => setField('eligibility', e.target.value)} /></FormField>
                            <FormField label="Special conditions" htmlFor="lt-special"><Input id="lt-special" placeholder="e.g. Medical certificate required" value={form.special_conditions} onChange={(e) => setField('special_conditions', e.target.value)} /></FormField>
                        </div></div>

                        <div className="cy-formsection"><h4 className="cy-formhead">Policy rules</h4>
                        <div className="cy-formgrid">
                            <Switch label="Carry forward (unused days to next year)" checked={form.carry_forward} onCheckedChange={(v) => setField('carry_forward', v)} />
                            <Switch label="Earned leave (accumulated over time)" checked={form.earned_leave} onCheckedChange={(v) => setField('earned_leave', v)} />
                            <Switch label="Approval required" checked={form.requires_approval} onCheckedChange={(v) => { setField('requires_approval', v); if (!v) setField('auto_approve', false); }} />
                            <Switch label="Auto approve (skip the approval workflow)" checked={form.auto_approve} disabled={!form.requires_approval} onCheckedChange={(v) => setField('auto_approve', v)} />
                        </div></div>

                        <div className="cy-formsection"><h4 className="cy-formhead">Accrual policy</h4>
                        <div className="cy-formgrid">
                            <FormField label="Accrual method" htmlFor="lt-accrual"><SelectInput id="lt-accrual" value={form.accrual_method} onChange={(v) => setField('accrual_method', v)} options={ACCRUAL} /></FormField>
                            <FormField label="Accrual rate (days/yr)" htmlFor="lt-rate"><Input id="lt-rate" {...num('accrual_rate', { min: 0, step: 0.5, placeholder: 'defaults to days' })} /></FormField>
                            <FormField label="Probation (months)" htmlFor="lt-prob"><Input id="lt-prob" {...num('probation_months', { min: 0, placeholder: '0' })} /></FormField>
                            <FormField label="Carry-forward cap (days)" htmlFor="lt-cap"><Input id="lt-cap" {...num('carry_forward_cap', { min: 0, step: 0.5, placeholder: 'blank = no carry' })} /></FormField>
                            <FormField label="Carry expiry (months)" htmlFor="lt-cexp"><Input id="lt-cexp" {...num('carry_expiry_months', { min: 0, placeholder: 'blank = never' })} /></FormField>
                        </div>
                        <div className="cy-formgrid">
                            <Switch label="Prorate on join" checked={form.prorate_on_join} onCheckedChange={(v) => setField('prorate_on_join', v)} />
                            <Switch label="Encashable" checked={form.is_encashable} onCheckedChange={(v) => setField('is_encashable', v)} />
                            <Switch label="Allow negative balance" checked={form.allow_negative} onCheckedChange={(v) => setField('allow_negative', v)} />
                        </div></div>

                        <div className="cy-formsection"><h4 className="cy-formhead">Eligibility and documents</h4>
                        <div className="cy-formgrid">
                            <FormField label="Eligible gender" htmlFor="lt-gender"><SelectInput id="lt-gender" value={form.eligible_gender} onChange={(v) => setField('eligible_gender', v)} options={GENDER} /></FormField>
                            <FormField label="Min service (months)" htmlFor="lt-minsvc"><Input id="lt-minsvc" {...num('min_service_months', { min: 0, placeholder: 'blank = none' })} /></FormField>
                            <FormField label="Encash cap (days/yr)" htmlFor="lt-encash"><Input id="lt-encash" {...num('max_encash_days', { min: 0, step: 0.5, placeholder: 'blank = no cap' })} /></FormField>
                            <FormField label="Supporting document" htmlFor="lt-docrule"><SelectInput id="lt-docrule" value={form.attachment_rule} onChange={(v) => setField('attachment_rule', v)} options={DOC_RULE} /></FormField>
                            <FormField label="Required over (days)" htmlFor="lt-docdays"><Input id="lt-docdays" {...num('requires_attachment_days', { min: 0, step: 0.5, placeholder: 'e.g. 2', disabled: form.attachment_rule !== 'beyond_days' })} /></FormField>
                        </div>
                        <div className="cy-formgrid">
                            <Switch label="Comp-off bank (receives comp-off grants for off-day / holiday work)" checked={form.is_comp_off} onCheckedChange={(v) => setField('is_comp_off', v)} />
                        </div></div>

                        <div className="cy-formsection cy-formfoot">
                            {isEditing && <Button color="default" variant="outline" size="sm" onClick={resetForm} disabled={loading}>Cancel</Button>}
                            <Button size="sm" onClick={handleSubmit} disabled={!isFormValid} loading={loading}>{isEditing ? 'Update leave type' : 'Add leave type'}</Button>
                        </div>
                    </Card>
                </div>
            </div>

            <div className="dl-row">
                <div className="dl-col dl-col--12">
                    <Card id="leave-settings:list" title="Leave types overview" flush
                        actions={<Badge soft color="theme">{leaveTypes.length}</Badge>}>
                        <div className="cy-toolbar" role="toolbar" aria-label="Leave type filters">
                            <div className="cy-toolbar__group">
                                <label className="cy-tool">
                                    <span className="visually-hidden">Search leave types</span>
                                    <Icon name="search" />
                                    <input className="cy-input cy-input--sm" type="search" placeholder="Search leave types…" value={f.draft.ls_q} onChange={(e) => f.setDraft('ls_q', e.target.value)} />
                                </label>
                            </div>
                        </div>
                        <div className="cy-dt" tabIndex={0} role="region" aria-label="Leave types, scrollable">
                            <table className="cy-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Leave type</th><th scope="col">Days</th><th scope="col">Eligibility</th><th scope="col">Policies</th>
                                        <th scope="col">Document</th><th scope="col">Special conditions</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filtered.length === 0 ? (
                                        <tr><td colSpan={7} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><p className="cy-empty__text">{search ? 'No matches found.' : 'No leave types configured yet.'}</p></div></td></tr>
                                    ) : filtered.map((lt) => (
                                        <tr key={lt.id}>
                                            <td><b>{lt.type}</b></td>
                                            <td className="cy-nowrap">{lt.days} days</td>
                                            <td className="cy-wrap">{lt.eligibility || 'None'}</td>
                                            <td>
                                                <span className="cy-actions">
                                                    <Badge soft color={lt.carry_forward ? 'success' : 'secondary'}>Carry fwd</Badge>
                                                    <Badge soft color={lt.earned_leave ? 'success' : 'secondary'}>Earned</Badge>
                                                    <Badge soft color={(lt.requires_approval ?? true) ? 'success' : 'secondary'}>Approval</Badge>
                                                    {lt.auto_approve && <Badge soft color="theme">Auto</Badge>}
                                                </span>
                                            </td>
                                            <td className="cy-wrap">{docRuleLabel(lt)}</td>
                                            <td className="cy-wrap">{lt.special_conditions || 'None'}</td>
                                            <td>
                                                <span className="cy-actions">
                                                    <Button size="sm" color="default" variant="outline" aria-label={`Edit ${lt.type}`} onClick={() => handleEdit(lt)}><Icon name="pencil" /> Edit</Button>
                                                    <Button size="sm" color="danger" variant="outline" aria-label={`Delete ${lt.type}`} onClick={() => setDeleteModal({ open: true, candidate: lt })}><Icon name="trash" /> Delete</Button>
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                </div>
            </div>

            <ConfirmModal
                open={deleteModal.open}
                onClose={() => { if (!deleteLoading) setDeleteModal({ open: false, candidate: null }); }}
                onConfirm={confirmDelete}
                loading={deleteLoading}
                candidate={deleteModal.candidate}
            />
        </div>
    );
}
