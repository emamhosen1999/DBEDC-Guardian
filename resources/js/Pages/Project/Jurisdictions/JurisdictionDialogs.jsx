import React, { useEffect, useMemo, useState } from 'react';

import { Alert, Badge, Button, Checkbox, DateInput, FormField, Input, SelectInput, Textarea } from '@/Components/Cyber';
import { Avatar } from '@/Components/Cyber/Map/Avatar.jsx';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { formatChainage } from '@/Components/Cyber/Map/geo.js';
import { showToast } from '@/utils/toastUtils';

import EmployeePicker from './EmployeePicker.jsx';
import { ROLE_LABEL, errorsOf, formatDay, getJson, postJson, todayDhaka } from './jurisdictionUtils.js';

const TOKENS = ['blue', 'orange', 'green', 'vermillion', 'sky', 'purple', 'yellow'];

/** Effective date + reason. A planned (future) change needs nothing more; a back-dated one needs the permission and a reason. */
export function EffectiveFields({ value, onChange, abilities, errors, label = 'Effective from' }) {
    const today = todayDhaka();
    const past = !!value.effective_date && value.effective_date < today;
    return (
        <>
            <FormField label={label} htmlFor="jr-effective" error={errors.effective_date} hint="Today by default. A future date is a planned change and takes effect on that day. A past date corrects history.">
                {({ describedBy }) => <DateInput id="jr-effective" aria-describedby={describedBy} label={label} value={value.effective_date ?? ''} onChange={(v) => onChange({ ...value, effective_date: v })} />}
            </FormField>
            {past && !abilities?.correct_history && (
                <Alert tone="danger">Correcting history needs the &quot;Correct history&quot; permission. Choose today or a later date.</Alert>
            )}
            {past && abilities?.correct_history && (
                <FormField label="Reason for the correction" htmlFor="jr-reason" error={errors.reason} required>
                    {({ describedBy }) => <Textarea id="jr-reason" rows={2} aria-describedby={describedBy} invalid={!!errors.reason} value={value.reason ?? ''} onChange={(e) => onChange({ ...value, reason: e.target.value })} />}
                </FormField>
            )}
            {!past && (
                <FormField label="Reason (optional)" htmlFor="jr-reason">
                    <Input id="jr-reason" value={value.reason ?? ''} onChange={(e) => onChange({ ...value, reason: e.target.value })} />
                </FormField>
            )}
        </>
    );
}

const blocked = (state, abilities) => !!state.effective_date && state.effective_date < todayDhaka() && !abilities?.correct_history;

function useSubmit(action, onDone) {
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState(null);
    const submit = async () => {
        setBusy(true); setErrors({}); setMessage(null);
        try {
            const data = await action();
            onDone?.(data);
        } catch (e) {
            const err = errorsOf(e);
            setErrors(err.fields);
            setMessage(Object.keys(err.fields).length ? 'Please correct the highlighted fields.' : err.message);
            showToast.error(Object.keys(err.fields).length ? 'Please correct the highlighted fields.' : err.message);
        } finally { setBusy(false); }
    };
    return { busy, errors, message, submit };
}

export const KINDS = [
    { value: 'section', label: 'Section', hint: 'A chainage band. Daily works are attributed to sections.' },
    { value: 'facility', label: 'Facility', hint: 'A point or short range: interchange, toll plaza, bridge, depot, office, monitoring centre.' },
    { value: 'area', label: 'Area', hint: 'A polygon, from an existing geofence or uploaded GeoJSON.' },
];
export const SUBTYPES = [
    { value: 'interchange', label: 'Interchange' }, { value: 'toll_plaza', label: 'Toll plaza' }, { value: 'bridge', label: 'Bridge or structure' },
    { value: 'depot', label: 'Depot' }, { value: 'office', label: 'Office' }, { value: 'monitoring_centre', label: 'Monitoring centre' },
];
export const CARRIAGEWAYS = [{ value: '', label: 'Both carriageways' }, { value: 'northbound', label: 'Northbound' }, { value: 'southbound', label: 'Southbound' }];
export const kindLabel = (k) => KINDS.find((x) => x.value === k)?.label ?? k;

/** Create or edit a jurisdiction of any kind. A change of name, code, boundary or area is versioned: the old definition stays in its history. */
export function JurisdictionFormDialog({ jurisdiction, employees, abilities, structures = [], onClose, onSaved }) {
    const editing = !!jurisdiction;
    const [form, setForm] = useState(() => ({
        kind: jurisdiction?.kind ?? 'section', subtype: jurisdiction?.subtype ?? 'toll_plaza', carriageway: jurisdiction?.carriageway ?? '',
        code: jurisdiction?.code ?? '', name: jurisdiction?.name ?? '', start_m: jurisdiction?.start_m ?? '', end_m: jurisdiction?.end_m ?? '',
        description: jurisdiction?.description ?? '', colour_token: jurisdiction?.colour_chosen ? jurisdiction.colour_token : '', incharge: '', effective_date: todayDhaka(), reason: '',
        geofence_id: '', geojson: '',
    }));
    const [geofences, setGeofences] = useState(null);
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const chain = form.kind !== 'area';

    useEffect(() => {
        if (form.kind !== 'area') return undefined;
        let alive = true;
        getJson(route('jurisdictions.geofences')).then((d) => alive && setGeofences(d.geofences)).catch(() => alive && setGeofences([]));
        return () => { alive = false; };
    }, [form.kind]);

    const { busy, errors, message, submit } = useSubmit(async () => {
        const body = { ...form, start_m: chain && form.start_m !== '' ? Number(form.start_m) : undefined, end_m: chain && form.end_m !== '' ? Number(form.end_m) : undefined, colour_token: form.colour_token || null, carriageway: form.carriageway || null };
        if (!chain) { delete body.carriageway; }
        if (form.kind !== 'facility') delete body.subtype;
        if (form.kind !== 'area') { delete body.geofence_id; delete body.geojson; }
        if (editing) { body.id = jurisdiction.id; delete body.incharge; delete body.kind; }
        Object.keys(body).forEach((k) => { if (body[k] === '' || body[k] === undefined) delete body[k]; });
        return postJson(editing ? route('updateJurisdiction') : route('addJurisdiction'), body);
    }, (data) => { showToast.success(data.message); onSaved(data); onClose(); });
    const bounds = [Number(form.start_m), Number(form.end_m)];
    const preview = chain && form.start_m !== '' && form.end_m !== '' && Number.isFinite(bounds[0]) && Number.isFinite(bounds[1]) ? `${formatChainage(bounds[0])} to ${formatChainage(bounds[1])}` : null;
    const boundaryChanged = editing && (jurisdiction.code !== form.code || jurisdiction.name !== form.name || jurisdiction.start_m !== (form.start_m === '' ? null : Number(form.start_m)) || jurisdiction.end_m !== (form.end_m === '' ? null : Number(form.end_m)) || (jurisdiction.subtype ?? '') !== (form.kind === 'facility' ? form.subtype : '') || (jurisdiction.carriageway ?? '') !== form.carriageway || !!form.geofence_id || !!form.geojson);
    const kind = KINDS.find((k) => k.value === form.kind);

    const onFile = (file) => { if (!file) return; const r = new FileReader(); r.onload = () => set('geojson', String(r.result)); r.readAsText(file); };

    return (
        <Dialog title={editing ? `Edit ${jurisdiction.code}` : 'Add jurisdiction'} onClose={onClose} className="cy-modal cy-modal--wide">
            <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
                <div className="cy-modal__body cy-formgrid">
                    {message && <Alert tone="danger">{message}</Alert>}
                    {!editing && (
                        <FormField label="Kind" htmlFor="jr-kind" hint={kind?.hint} error={errors.kind}>
                            <SelectInput id="jr-kind" value={form.kind} onChange={(v) => set('kind', v)} options={KINDS} />
                        </FormField>
                    )}
                    <FormField label="Code" htmlFor="jr-code" error={errors.code} hint="Short and unique, for example PH-1" required>
                        {({ describedBy }) => <Input id="jr-code" aria-describedby={describedBy} invalid={!!errors.code} value={form.code} onChange={(e) => set('code', e.target.value.toUpperCase())} />}
                    </FormField>
                    <FormField label="Name" htmlFor="jr-name" error={errors.name} required>
                        {({ describedBy }) => <Input id="jr-name" aria-describedby={describedBy} invalid={!!errors.name} value={form.name} onChange={(e) => set('name', e.target.value)} />}
                    </FormField>
                    {form.kind === 'facility' && (
                        <FormField label="Facility type" htmlFor="jr-subtype" error={errors.subtype} required>
                            <SelectInput id="jr-subtype" invalid={!!errors.subtype} value={form.subtype} onChange={(v) => set('subtype', v)} options={SUBTYPES} />
                        </FormField>
                    )}
                    {form.kind === 'facility' && structures.length > 0 && (
                        <FormField label="Place it at a known structure" htmlFor="jr-structure" hint="Fills the chainage from the corridor's structures and toll plazas.">
                            <SelectInput id="jr-structure" value="" onChange={(v) => { const st = structures.find((x) => x.id === v); if (st) { set('start_m', st.chainage_m); set('end_m', st.chainage_m); if (!form.name) set('name', st.title); } }}
                                options={[{ value: '', label: 'Choose a structure' }, ...structures.map((st) => ({ value: st.id, label: `${st.title} (${formatChainage(st.chainage_m)})` }))]} />
                        </FormField>
                    )}
                    {chain && (
                        <>
                            <FormField label="Start (metres from K0+000)" htmlFor="jr-start" error={errors.start_m} hint={preview ? `Reads as ${preview}` : 'For example 28000 is K28+000'} required>
                                {({ describedBy }) => <Input id="jr-start" type="number" min="0" max="48999" aria-describedby={describedBy} invalid={!!errors.start_m} value={form.start_m} onChange={(e) => set('start_m', e.target.value)} />}
                            </FormField>
                            <FormField label="End (metres from K0+000)" htmlFor="jr-end" error={errors.end_m} hint={form.kind === 'facility' ? 'Same as the start for a point' : undefined} required>
                                {({ describedBy }) => <Input id="jr-end" type="number" min="0" max="48999" aria-describedby={describedBy} invalid={!!errors.end_m} value={form.end_m} onChange={(e) => set('end_m', e.target.value)} />}
                            </FormField>
                            <FormField label="Carriageway" htmlFor="jr-cw" error={errors.carriageway}>
                                <SelectInput id="jr-cw" value={form.carriageway} onChange={(v) => set('carriageway', v)} options={CARRIAGEWAYS} />
                            </FormField>
                        </>
                    )}
                    {!chain && (
                        <>
                            {errors.geometry && <Alert tone="danger">{errors.geometry}</Alert>}
                            <FormField label="Take the polygon from a geofence" htmlFor="jr-geofence" hint={geofences && geofences.length === 0 ? 'No attendance geofence is defined yet: upload GeoJSON instead.' : 'The attendance geofences'}>
                                <SelectInput id="jr-geofence" value={form.geofence_id} onChange={(v) => set('geofence_id', v)} options={[{ value: '', label: geofences ? 'None' : 'Loading…' }, ...(geofences ?? []).map((g) => ({ value: g.id, label: `${g.name} (${g.vertices} corners)` }))]} />
                            </FormField>
                            <FormField label="or upload GeoJSON" htmlFor="jr-geojson" hint="A closed Polygon inside Bangladesh; it is simplified for display. No drawing yet.">
                                <input id="jr-geojson" type="file" accept=".json,.geojson,application/geo+json,application/json" className="cy-input" onChange={(e) => onFile(e.target.files?.[0])} />
                            </FormField>
                            {form.geojson && <p className="cy-formtext" role="status">GeoJSON file read ({Math.ceil(form.geojson.length / 1024)} KB).</p>}
                        </>
                    )}
                    {!editing && <EmployeePicker employees={employees} label="In-charge" value={form.incharge} onChange={(v) => set('incharge', v)} error={errors.incharge} id="jr-incharge" />}
                    <FormField label="Map colour" htmlFor="jr-colour" hint="Colour-blind-safe palette. Automatic by position when empty.">
                        <SelectInput id="jr-colour" value={form.colour_token} onChange={(v) => set('colour_token', v)} options={[{ value: '', label: 'Automatic' }, ...TOKENS.map((t) => ({ value: t, label: t[0].toUpperCase() + t.slice(1) }))]} />
                    </FormField>
                    <FormField label="Description" htmlFor="jr-desc" error={errors.description}>
                        <Textarea id="jr-desc" rows={2} value={form.description} onChange={(e) => set('description', e.target.value)} />
                    </FormField>
                    {(!editing || boundaryChanged) && <EffectiveFields value={form} onChange={setForm} abilities={abilities} errors={errors} label={editing ? 'New definition effective from' : 'Jurisdiction effective from'} />}
                    {editing && boundaryChanged && <Alert tone="info">The current definition ({jurisdiction.code} {jurisdiction.name}{jurisdiction.start_m !== null ? `, ${formatChainage(jurisdiction.start_m)} to ${formatChainage(jurisdiction.end_m)}` : ''}) is kept in the history{jurisdiction.kind === 'section' ? ' and works are re-attributed from the effective date' : ''}.</Alert>}
                    {!editing && form.kind === 'section' && errors.start_m && <Alert tone="warning">If a neighbouring section is in the way, shrink it first: open the neighbour, choose Edit and move its boundary from the same date.</Alert>}
                </div>
                <div className="cy-modal__foot">
                    <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={busy} disabled={blocked(form, abilities)}>{editing ? 'Save changes' : 'Add jurisdiction'}</Button>
                </div>
            </form>
        </Dialog>
    );
}

/** Split one section into two at a chainage from a date; the team is copied to both. */
export function SplitDialog({ jurisdiction: j, employees, abilities, onClose, onDone }) {
    const mid = Math.round((j.start_m + j.end_m) / 2 / 100) * 100;
    const [form, setForm] = useState({ at_m: mid, effective_date: todayDhaka(), reason: '', first: { code: `${j.code}A`, name: `${j.name} A`, incharge: '' }, second: { code: `${j.code}B`, name: `${j.name} B`, incharge: '' } });
    const part = (k, f, v) => setForm((s) => ({ ...s, [k]: { ...s[k], [f]: v } }));
    const { busy, errors, message, submit } = useSubmit(() => postJson(route('jurisdictions.split', j.id), {
        at_m: Number(form.at_m), effective_date: form.effective_date, reason: form.reason || undefined,
        first: { ...form.first, incharge: form.first.incharge || undefined }, second: { ...form.second, incharge: form.second.incharge || undefined },
    }), (d) => { showToast.success(d.message); onDone(d); onClose(); });
    const e = (k) => errors[k];
    return (
        <Dialog title={`Split ${j.code} ${j.name}`} onClose={onClose} className="cy-modal cy-modal--wide">
            <div className="cy-modal__body cy-formgrid">
                {message && <Alert tone="danger">{message}</Alert>}
                <Alert tone="info">{j.code} is retired the day before the effective date and two new sections open. Works dated from then on are re-attributed; earlier works keep {j.code}. The team is copied to both and the in-charge stays, unless you choose another below.</Alert>
                <FormField label={`Split at (metres, between ${formatChainage(j.start_m + 2)} and ${formatChainage(j.end_m)})`} htmlFor="jr-split-at" error={e('at_m')} hint={`The first part ends at ${formatChainage(Number(form.at_m) - 1)}, the second starts at ${formatChainage(Number(form.at_m))}`} required>
                    <Input id="jr-split-at" type="number" value={form.at_m} invalid={!!e('at_m')} onChange={(ev) => setForm((s) => ({ ...s, at_m: ev.target.value }))} />
                </FormField>
                {['first', 'second'].map((k) => (
                    <fieldset key={k} className="jr-detail__section">
                        <legend className="jr-detail__title">{k === 'first' ? 'First part' : 'Second part'}</legend>
                        <FormField label="Code" htmlFor={`jr-${k}-code`} error={e(`${k}.code`)} required><Input id={`jr-${k}-code`} invalid={!!e(`${k}.code`)} value={form[k].code} onChange={(ev) => part(k, 'code', ev.target.value.toUpperCase())} /></FormField>
                        <FormField label="Name" htmlFor={`jr-${k}-name`} error={e(`${k}.name`)} required><Input id={`jr-${k}-name`} invalid={!!e(`${k}.name`)} value={form[k].name} onChange={(ev) => part(k, 'name', ev.target.value)} /></FormField>
                        <FormField label="In-charge" htmlFor={`jr-${k}-ic`} hint="Leave empty to keep the current in-charge"><SelectInput id={`jr-${k}-ic`} value={form[k].incharge} onChange={(v) => part(k, 'incharge', v)} options={[{ value: '', label: j.in_charge ? `Keep ${j.in_charge.name}` : 'Keep current' }, ...employees.map((x) => ({ value: String(x.employee_id), label: `${x.name} (${x.employee_id})` }))]} /></FormField>
                    </fieldset>
                ))}
                <EffectiveFields value={form} onChange={(v) => setForm((s) => ({ ...s, ...v }))} abilities={abilities} errors={errors} label="Split effective from" />
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={blocked(form, abilities)} onClick={submit}>Split section</Button>
            </div>
        </Dialog>
    );
}

/** Merge two adjacent sections into one from a date (the reverse of a split). */
export function MergeDialog({ jurisdiction: j, sections, employees, abilities, onClose, onDone }) {
    const neighbours = sections.filter((s) => s.id !== j.id && s.carriageway === j.carriageway && (s.end_m + 1 === j.start_m || j.end_m + 1 === s.start_m));
    const [form, setForm] = useState({ with: neighbours[0]?.id ?? '', code: '', name: '', incharge: j.in_charge?.employee_id ?? '', effective_date: todayDhaka(), reason: '' });
    const { busy, errors, message, submit } = useSubmit(() => postJson(route('jurisdictions.merge', j.id), { ...form, with: Number(form.with) }), (d) => { showToast.success(d.message); onDone(d); onClose(); });
    return (
        <Dialog title={`Merge ${j.code} ${j.name}`} onClose={onClose} className="cy-modal cy-modal--wide">
            <div className="cy-modal__body cy-formgrid">
                {message && <Alert tone="danger">{message}</Alert>}
                {neighbours.length === 0 && <Alert tone="warning">No adjacent section on the same carriageway: there is nothing to merge with.</Alert>}
                <FormField label="Merge with" htmlFor="jr-merge-with" error={errors.with} required>
                    <SelectInput id="jr-merge-with" value={String(form.with)} onChange={(v) => setForm((s) => ({ ...s, with: v }))} options={neighbours.map((s) => ({ value: String(s.id), label: `${s.code} ${s.name} (${formatChainage(s.start_m)} to ${formatChainage(s.end_m)})` }))} />
                </FormField>
                <FormField label="Code of the merged section" htmlFor="jr-merge-code" error={errors.code} required><Input id="jr-merge-code" invalid={!!errors.code} value={form.code} onChange={(e) => setForm((s) => ({ ...s, code: e.target.value.toUpperCase() }))} /></FormField>
                <FormField label="Name of the merged section" htmlFor="jr-merge-name" error={errors.name} required><Input id="jr-merge-name" invalid={!!errors.name} value={form.name} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} /></FormField>
                <EmployeePicker employees={employees} label="In-charge of the merged section" value={String(form.incharge)} onChange={(v) => setForm((s) => ({ ...s, incharge: v }))} error={errors.incharge} id="jr-merge-ic" />
                <EffectiveFields value={form} onChange={setForm} abilities={abilities} errors={errors} label="Merge effective from" />
                <Alert tone="info">Both sections are retired the day before; the merged section opens with the union of their teams. Works from the date on are re-attributed to it.</Alert>
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={blocked(form, abilities) || !form.with} onClick={submit}>Merge sections</Button>
            </div>
        </Dialog>
    );
}

/** Retire = remove with history kept. An open-team and open-works warning comes first. */
export function RetireDialog({ jurisdiction: j, abilities, onClose, onDone }) {
    const [form, setForm] = useState({ effective_date: todayDhaka(), reason: '', confirm_team: false, confirm_open_works: false });
    const { busy, errors, message, submit } = useSubmit(() => postJson(route('jurisdictions.retire', j.id), form), (d) => { showToast.success(d.message); onDone(d); onClose(); });
    const people = (n) => 1 + (n.children ?? []).reduce((a, c) => a + people(c), 0);
    const team = j.team.reduce((a, n) => a + people(n), 0) - (j.in_charge ? 1 : 0);
    return (
        <Dialog title={`Retire ${j.code} ${j.name}`} onClose={onClose}>
            <div className="cy-modal__body cy-formgrid">
                {message && <Alert tone="danger">{message}</Alert>}
                <Alert tone="warning">The jurisdiction closes the day before the effective date. Its history, works and handovers stay, it disappears from today&apos;s view and remains visible as of earlier dates. You can restore it later.</Alert>
                {team > 0 && <Checkbox label={`The ${team} team members leave the team on that date`} checked={form.confirm_team} onCheckedChange={(v) => setForm((s) => ({ ...s, confirm_team: v }))} />}
                {errors.confirm_team && <Alert tone="danger">{errors.confirm_team}</Alert>}
                {j.kind === 'section' && <Checkbox label="Open works stay recorded under it (hand them over first if they should move)" checked={form.confirm_open_works} onCheckedChange={(v) => setForm((s) => ({ ...s, confirm_open_works: v }))} />}
                {errors.confirm_open_works && <Alert tone="danger">{errors.confirm_open_works}</Alert>}
                <EffectiveFields value={form} onChange={(v) => setForm((s) => ({ ...s, ...v }))} abilities={abilities} errors={errors} label="Retired from" />
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Keep it</Button>
                <Button color="danger" loading={busy} disabled={blocked(form, abilities)} onClick={submit}>Retire jurisdiction</Button>
            </div>
        </Dialog>
    );
}

/** Restore a retired or deleted jurisdiction from a date. */
export function RestoreDialog({ archived: a, employees, abilities, onClose, onDone }) {
    const [form, setForm] = useState({ effective_date: todayDhaka(), reason: '', incharge: '' });
    const { busy, errors, message, submit } = useSubmit(() => postJson(route('jurisdictions.restore', a.id), { ...form, incharge: form.incharge || undefined }), (d) => { showToast.success(d.message); onDone(d); onClose(); });
    return (
        <Dialog title={`Restore ${a.code} ${a.name}`} onClose={onClose}>
            <div className="cy-modal__body cy-formgrid">
                {message && <Alert tone="danger">{message}</Alert>}
                <p className="cy-formtext">{a.state === 'deleted' ? 'It was deleted and comes back as it was.' : `It was retired${a.retired_on ? ` after ${a.retired_on}` : ''}. A new definition opens from the effective date with the last boundaries.`}</p>
                {a.state !== 'deleted' && <EmployeePicker employees={employees} label="In-charge" value={form.incharge} onChange={(v) => setForm((s) => ({ ...s, incharge: v }))} error={errors.incharge} id="jr-restore-ic" />}
                {a.state !== 'deleted' && <p className="cy-formtext">Leave the choice empty to bring back the last in-charge.</p>}
                <EffectiveFields value={form} onChange={(v) => setForm((s) => ({ ...s, ...v }))} abilities={abilities} errors={errors} label="Restored from" />
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={blocked(form, abilities)} onClick={submit}>Restore</Button>
            </div>
        </Dialog>
    );
}

/** Delete is for a jurisdiction created by mistake (no works, handovers or history); the server refuses otherwise and says to retire it. */
export function DeleteJurisdictionDialog({ jurisdiction, onClose, onDeleted }) {
    const { busy, message, submit } = useSubmit(() => postJson(route('deleteJurisdiction'), { id: jurisdiction.id }), (data) => { showToast.success(data.message); onDeleted(data); onClose(); });
    return (
        <Dialog title={`Delete ${jurisdiction.code} ${jurisdiction.name}`} onClose={onClose}>
            <div className="cy-modal__body cy-formgrid">
                {message && <Alert tone="danger">{message}</Alert>}
                <Alert tone="warning">Delete is only for a jurisdiction added by mistake: one with no attributed works, no handovers and no changes. Anything with history must be retired instead, which keeps the history. A deleted jurisdiction can be restored.</Alert>
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Keep it</Button>
                <Button color="danger" loading={busy} onClick={submit}>Delete jurisdiction</Button>
            </div>
        </Dialog>
    );
}

/** Team management: add a member, change the in-charge (handover), change a role, end a membership, accept suggestions. */
export function TeamDialog({ mode, jurisdiction, member, employees, abilities, onClose, onChanged }) {
    const [form, setForm] = useState({ employee_id: member?.employee_id ?? '', role: mode === 'role' ? (member?.role === 'member' ? 'deputy_in_charge' : 'member') : 'member', previous_becomes: '', effective_date: todayDhaka(), reason: '', note: '' });
    const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
    const [suggestions, setSuggestions] = useState(null);
    const onTeam = useMemo(() => jurisdiction.team.flatMap(function walk(n) { return [n.employee_id, ...(n.children ?? []).flatMap(walk)]; }), [jurisdiction.team]);

    useEffect(() => {
        if (mode !== 'add' && mode !== 'in-charge') return undefined;
        let alive = true;
        getJson(route('jurisdictions.team.suggestions', jurisdiction.id)).then((d) => { if (alive) setSuggestions(d.suggestions); }).catch(() => { if (alive) setSuggestions([]); });
        return () => { alive = false; };
    }, [mode, jurisdiction.id]);

    const url = { add: route('jurisdictions.team.add', jurisdiction.id), 'in-charge': route('jurisdictions.team.in-charge', jurisdiction.id) }[mode]
        ?? (mode === 'role' ? route('jurisdictions.team.role', [jurisdiction.id, member?.member_id]) : route('jurisdictions.team.remove', [jurisdiction.id, member?.member_id]));
    const { busy, errors, message, submit } = useSubmit(() => postJson(url, {
        employee_id: form.employee_id || undefined, role: mode === 'add' || mode === 'role' ? form.role : undefined,
        previous_becomes: mode === 'in-charge' ? (form.previous_becomes || undefined) : undefined,
        effective_date: form.effective_date, reason: form.reason || undefined, note: mode === 'in-charge' ? (form.note || undefined) : undefined,
    }), (data) => { showToast.success(data.message); onChanged(data); onClose(); });

    const title = { add: 'Add team member', 'in-charge': 'Change in-charge', role: `Change role of ${member?.name ?? ''}`, remove: `Remove ${member?.name ?? ''} from the team` }[mode];
    const current = jurisdiction.in_charge;

    return (
        <Dialog title={`${title}: ${jurisdiction.code}`} onClose={onClose} className="cy-modal cy-modal--wide">
            <div className="cy-modal__body cy-formgrid">
                {message && <Alert tone="danger">{message}</Alert>}
                {(mode === 'add' || mode === 'in-charge') && (
                    <EmployeePicker employees={employees} label={mode === 'add' ? 'Employee' : 'New in-charge'} value={form.employee_id} onChange={(v) => set('employee_id', v)}
                        error={errors.employee_id} exclude={mode === 'add' ? onTeam : (current ? [current.employee_id] : [])} suggestedIds={(suggestions ?? []).map((s) => String(s.employee_id))} id="jr-team-employee" />
                )}
                {(mode === 'add' || mode === 'in-charge') && suggestions && suggestions.length > 0 && (
                    <div>
                        <h4 className="jr-detail__title">Suggested from the last 90 days of work here</h4>
                        <ul className="jr-suggest">
                            {suggestions.slice(0, 6).map((s) => (
                                <li key={s.employee_id}>
                                    <Avatar name={s.name} photo={s.photo} />
                                    <span className="jr-person__text"><strong>{s.name ?? s.employee_id}</strong><small>{s.designation ?? 'Employee'}, {s.works} works ({s.as_incharge} as in-charge, {s.as_assigned} assigned)</small></span>
                                    <Button size="sm" variant="outline" color="secondary" className="jr-person__works" onClick={() => set('employee_id', String(s.employee_id))}>Choose</Button>
                                </li>
                            ))}
                        </ul>
                        <p className="cy-formtext">Suggestions are only a guide; nothing is added until you save.</p>
                    </div>
                )}
                {mode === 'add' && (
                    <FormField label="Role" htmlFor="jr-role"><SelectInput id="jr-role" value={form.role} onChange={(v) => set('role', v)} options={[{ value: 'member', label: 'Member' }, { value: 'deputy_in_charge', label: 'Deputy in-charge' }]} /></FormField>
                )}
                {mode === 'role' && (
                    <FormField label="New role" htmlFor="jr-role"><SelectInput id="jr-role" value={form.role} onChange={(v) => set('role', v)} options={[{ value: 'member', label: 'Member' }, { value: 'deputy_in_charge', label: 'Deputy in-charge' }]} /></FormField>
                )}
                {mode === 'in-charge' && current && (
                    <FormField label={`${current.name} (current in-charge) will`} htmlFor="jr-prev">
                        <SelectInput id="jr-prev" value={form.previous_becomes} onChange={(v) => set('previous_becomes', v)} options={[{ value: '', label: 'Leave the team' }, { value: 'deputy_in_charge', label: 'Stay as deputy in-charge' }, { value: 'member', label: 'Stay as member' }]} />
                    </FormField>
                )}
                {mode === 'in-charge' && (
                    <FormField label="Handover note" htmlFor="jr-note" hint="Recorded with the handover, with a snapshot of the open works at this moment.">
                        <Textarea id="jr-note" rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} />
                    </FormField>
                )}
                <EffectiveFields value={form} onChange={setForm} abilities={abilities} errors={errors} label={mode === 'remove' ? 'Leaves the team on' : 'Effective from'} />
                {mode === 'in-charge' && <Alert tone="info">Open works stay recorded under the outgoing in-charge. After saving you can preview and move them to the new in-charge as a separate, audited step.</Alert>}
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={blocked(form, abilities) || ((mode === 'add' || mode === 'in-charge') && !form.employee_id)} color={mode === 'remove' ? 'danger' : 'theme'} onClick={submit}>
                    {{ add: 'Add to team', 'in-charge': 'Record handover', role: 'Change role', remove: 'Remove from team' }[mode]}
                </Button>
            </div>
        </Dialog>
    );
}

/** Explicit, previewed move of the open works recorded under the outgoing in-charge to the incoming one. */
export function TransferDialog({ jurisdiction, handoverId, onClose, onDone }) {
    const [state, setState] = useState({ loading: true, works: [], handover: null, error: null });
    const [picked, setPicked] = useState(new Set());
    useEffect(() => {
        let alive = true;
        getJson(route('jurisdictions.handover.preview', [jurisdiction.id, handoverId]))
            .then((d) => { if (!alive) return; setState({ loading: false, works: d.works, handover: d.handover, error: null }); setPicked(new Set(d.works.map((w) => w.id))); })
            .catch((e) => alive && setState({ loading: false, works: [], handover: null, error: errorsOf(e).message }));
        return () => { alive = false; };
    }, [jurisdiction.id, handoverId]);
    const { busy, message, submit } = useSubmit(() => postJson(route('jurisdictions.handover.transfer', [jurisdiction.id, handoverId]), { work_ids: [...picked] }), (d) => { showToast.success(d.message); onDone(d); onClose(); });
    const toggle = (id, on) => setPicked((cur) => { const n = new Set(cur); on ? n.add(id) : n.delete(id); return n; });
    const h = state.handover;

    return (
        <Dialog title={`Move open works: ${jurisdiction.code}`} onClose={onClose} className="cy-modal cy-modal--wide">
            <div className="cy-modal__body cy-formgrid">
                {state.error && <Alert tone="danger">{state.error}</Alert>}
                {message && <Alert tone="danger">{message}</Alert>}
                {state.loading && <p role="status">Loading the open works…</p>}
                {h && <Alert tone="info">Open works recorded under {h.outgoing?.name ?? 'the outgoing in-charge'} are moved to {h.incoming?.name}. Completed works never change. This is audited and the previous in-charge of every work is kept.</Alert>}
                {h && state.works.length === 0 && <p className="jr-empty">No open work is still recorded under the outgoing in-charge.</p>}
                {state.works.length > 0 && (
                    <div className="jr-preview" role="group" aria-label="Works to move">
                        <table className="cy-table">
                            <thead><tr><th><Checkbox label="All" checked={picked.size === state.works.length} onCheckedChange={(on) => setPicked(on ? new Set(state.works.map((w) => w.id)) : new Set())} /></th><th>RFI</th><th>Date</th><th>Type</th><th>Location</th><th>Status</th></tr></thead>
                            <tbody>
                                {state.works.map((w) => (
                                    <tr key={w.id}>
                                        <td><Checkbox label={`Move ${w.number}`} checked={picked.has(w.id)} onCheckedChange={(on) => toggle(w.id, on)} className="visually-hidden-label" /></td>
                                        <td>{w.number}</td><td>{w.date}</td><td>{w.type}</td><td>{w.location}</td><td><Badge soft color="secondary">{w.status}</Badge></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
            <div className="cy-modal__foot">
                <Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button>
                <Button loading={busy} disabled={picked.size === 0} onClick={submit}>Move {picked.size} works</Button>
            </div>
        </Dialog>
    );
}
