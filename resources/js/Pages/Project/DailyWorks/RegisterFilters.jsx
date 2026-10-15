import React from 'react';

import { Badge, Button, Checkbox, DateInput, FormField, Icon, Input, SelectInput } from '@/Components/Cyber';

import { SIDES, STATUSES, TYPES, csv, formatDay, statusMeta } from './dwUtils.js';

const toggle = (list, value) => (list.includes(value) ? list.filter((x) => x !== value) : [...list, value]);

/** Everything beyond search: status, type, side, people, jurisdiction, chainage range, dates, objections and data quality. */
export function FilterPanel({ values, set, setMany, jurisdictions, people, canSeeTrashed, onClose }) {
    const status = csv(values.dw_status);
    const type = csv(values.dw_type);
    const side = csv(values.dw_side);
    const personOptions = [{ value: '', label: 'Anyone' }, ...people.map((p) => ({ value: p.employee_id, label: `${p.name} (${p.employee_id})` }))];
    return (
        <section className="dw-filters" aria-label="Filters">
            <div className="dw-filters__grid">
                <fieldset className="dw-fieldset"><legend>Status</legend>
                    {STATUSES.map((s) => <Checkbox key={s.value} label={s.label} checked={status.includes(s.value)} onCheckedChange={() => set('dw_status', toggle(status, s.value).join(','))} />)}
                </fieldset>
                <fieldset className="dw-fieldset"><legend>Type</legend>
                    {TYPES.map((t) => <Checkbox key={t} label={t} checked={type.includes(t)} onCheckedChange={() => set('dw_type', toggle(type, t).join(','))} />)}
                </fieldset>
                <fieldset className="dw-fieldset"><legend>Road side</legend>
                    {SIDES.map((t) => <Checkbox key={t} label={t} checked={side.includes(t)} onCheckedChange={() => set('dw_side', toggle(side, t).join(','))} />)}
                </fieldset>
                <div className="dw-filters__col">
                    <FormField label="Jurisdiction" htmlFor="dw-f-jur">
                        <SelectInput id="dw-f-jur" size="sm" value={values.dw_jur} onChange={(v) => set('dw_jur', v)} options={[{ value: '', label: 'All jurisdictions' }, ...jurisdictions.map((j) => ({ value: String(j.id), label: `${j.code} ${j.name ?? ''}`.trim() }))]} />
                    </FormField>
                    <FormField label="In-charge" htmlFor="dw-f-inc"><SelectInput id="dw-f-inc" size="sm" value={values.dw_inc} onChange={(v) => set('dw_inc', v)} options={personOptions} /></FormField>
                    <FormField label="Assigned to" htmlFor="dw-f-asg"><SelectInput id="dw-f-asg" size="sm" value={values.dw_asg} onChange={(v) => set('dw_asg', v)} options={personOptions} /></FormField>
                </div>
                <div className="dw-filters__col">
                    <FormField label="Date from" htmlFor="dw-f-from"><DateInput id="dw-f-from" size="sm" label="Date from" value={values.dw_from} onChange={(v) => set('dw_from', v)} /></FormField>
                    <FormField label="Date to" htmlFor="dw-f-to"><DateInput id="dw-f-to" size="sm" label="Date to" value={values.dw_to} onChange={(v) => set('dw_to', v)} /></FormField>
                    <div className="dw-filters__pair">
                        <FormField label="Chainage from" htmlFor="dw-f-cf" hint="e.g. K5+900"><Input id="dw-f-cf" size="sm" value={values.dw_cf} onChange={(e) => set('dw_cf', e.target.value)} /></FormField>
                        <FormField label="Chainage to" htmlFor="dw-f-ct"><Input id="dw-f-ct" size="sm" value={values.dw_ct} onChange={(e) => set('dw_ct', e.target.value)} /></FormField>
                    </div>
                </div>
                <div className="dw-filters__col">
                    <FormField label="Objections" htmlFor="dw-f-obj">
                        <SelectInput id="dw-f-obj" size="sm" value={values.dw_obj} onChange={(v) => set('dw_obj', v)} options={[{ value: '', label: 'Any' }, { value: 'active', label: 'With an active objection' }, { value: 'any', label: 'With any objection' }, { value: 'none', label: 'Without objections' }]} />
                    </FormField>
                    <FormField label="Data quality" htmlFor="dw-f-q" hint="Real rows whose location could not be read or placed. Fix the location to clear them.">
                        <SelectInput id="dw-f-q" size="sm" value={values.dw_quality} onChange={(v) => set('dw_quality', v)} options={[{ value: '', label: 'All records' }, { value: 'unplaced', label: 'Location not readable' }, { value: 'unattributed', label: 'Not attributed to a jurisdiction' }]} />
                    </FormField>
                    {canSeeTrashed && <Checkbox label="Show deleted works" checked={!!values.dw_trash} onCheckedChange={(on) => set('dw_trash', on ? '1' : '')} />}
                </div>
            </div>
            <div className="dw-filters__foot">
                <Button size="sm" variant="outline" color="secondary" onClick={() => setMany({ dw_status: '', dw_type: '', dw_side: '', dw_inc: '', dw_asg: '', dw_jur: '', dw_from: '', dw_to: '', dw_cf: '', dw_ct: '', dw_obj: '', dw_quality: '', dw_trash: '' })}>Clear filters</Button>
                <Button size="sm" onClick={onClose}>Done</Button>
            </div>
        </section>
    );
}

/** One removable chip per active filter, so the view is always readable at a glance. */
export function FilterChips({ values, setMany, jurisdictions, people }) {
    const chips = [];
    const add = (key, text, clear = '') => chips.push({ key, text, clear });
    csv(values.dw_status).forEach((s) => add(`s${s}`, `Status: ${statusMeta(s).label}`));
    csv(values.dw_type).forEach((s) => add(`t${s}`, `Type: ${s}`));
    csv(values.dw_side).forEach((s) => add(`d${s}`, `Side: ${s}`));
    if (values.dw_jur) add('jur', `Jurisdiction: ${jurisdictions.find((j) => String(j.id) === String(values.dw_jur))?.code ?? values.dw_jur}`);
    if (values.dw_inc) add('inc', `In-charge: ${people.find((p) => p.employee_id === values.dw_inc)?.name ?? values.dw_inc}`);
    if (values.dw_asg) add('asg', `Assigned: ${people.find((p) => p.employee_id === values.dw_asg)?.name ?? values.dw_asg}`);
    if (values.dw_from) add('from', `From ${formatDay(values.dw_from)}`);
    if (values.dw_to) add('to', `To ${formatDay(values.dw_to)}`);
    if (values.dw_cf) add('cf', `Chainage from ${values.dw_cf}`);
    if (values.dw_ct) add('ct', `Chainage to ${values.dw_ct}`);
    if (values.dw_obj) add('obj', `Objections: ${values.dw_obj}`);
    if (values.dw_quality) add('q', values.dw_quality === 'unplaced' ? 'Location not readable' : 'Not attributed');
    if (values.dw_trash) add('trash', 'Deleted works');
    if (!chips.length) return null;
    const removers = {
        s: (c) => ({ dw_status: csv(values.dw_status).filter((x) => `s${x}` !== c.key).join(',') }),
        t: (c) => ({ dw_type: csv(values.dw_type).filter((x) => `t${x}` !== c.key).join(',') }),
        d: (c) => ({ dw_side: csv(values.dw_side).filter((x) => `d${x}` !== c.key).join(',') }),
    };
    const single = { jur: 'dw_jur', inc: 'dw_inc', asg: 'dw_asg', from: 'dw_from', to: 'dw_to', cf: 'dw_cf', ct: 'dw_ct', obj: 'dw_obj', q: 'dw_quality', trash: 'dw_trash' };
    return (
        <ul className="dw-chips" aria-label="Active filters">
            {chips.map((c) => (
                <li key={c.key}>
                    <Badge soft color="theme">
                        {c.text}
                        <button type="button" className="dw-chip__x" aria-label={`Remove filter ${c.text}`} onClick={() => setMany(single[c.key] ? { [single[c.key]]: '' } : removers[c.key[0]](c))}><Icon name="x-lg" /></button>
                    </Badge>
                </li>
            ))}
        </ul>
    );
}
