/**
 * SearchableMultiSelect.jsx
 * A searchable multi-select in Cyber form controls: a field-like disclosure button that shows the picks as badges and
 * opens an inline panel (search, select-all / clear, a checkbox list). Inline rather than a floating popover so it
 * never clips inside a modal. Same props as before.
 */
import React, { useState, useMemo, useRef, useEffect, useId } from 'react';
import { Badge, Button, Checkbox, FormField, Icon, Input } from '@/Components/Cyber';

export default function SearchableMultiSelect({
    options = [],          // [{ value: string|number, label: string }]
    selected = [],         // array of values
    onChange,               // (newSelected: value[]) => void
    placeholder = 'Select…',
    label,
    maxDisplay = 3,        // max badges to show before "+N more"
    disabled = false,
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const inputRef = useRef(null);
    const panelId = useId();
    const triggerId = useId();

    useEffect(() => {
        if (open) {
            setSearch('');
            const t = setTimeout(() => inputRef.current?.focus(), 50);
            return () => clearTimeout(t);
        }
        return undefined;
    }, [open]);

    const filtered = useMemo(() => {
        if (!search.trim()) return options;
        const q = search.toLowerCase();
        return options.filter(o => o.label.toLowerCase().includes(q));
    }, [options, search]);

    const toggle = (value) => {
        const set = new Set(selected);
        set.has(value) ? set.delete(value) : set.add(value);
        onChange([...set]);
    };

    const selectAll = () => onChange(filtered.map(o => o.value));
    const clearAll = () => onChange([]);
    const allSelected = filtered.length > 0 && filtered.every(o => selected.includes(o.value));

    const selectedLabels = useMemo(() =>
        selected.map(v => options.find(o => o.value === v)?.label || v),
    [selected, options]);

    return (
        <FormField label={label} htmlFor={triggerId}>
            <button
                id={triggerId}
                type="button"
                className="cy-input cy-multiselect__trigger"
                disabled={disabled}
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen(o => !o)}
            >
                <span className="cy-multiselect__picks">
                    {selected.length === 0 ? (
                        <span className="cy-muted">{placeholder}</span>
                    ) : (
                        <>
                            {selectedLabels.slice(0, maxDisplay).map((l, i) => <Badge key={i} soft color="info">{l}</Badge>)}
                            {selected.length > maxDisplay && <Badge outline color="secondary">+{selected.length - maxDisplay} more</Badge>}
                        </>
                    )}
                </span>
                <Icon name="chevron-down" className={open ? 'cy-icon--flip' : ''} />
            </button>

            {open && (
                <div id={panelId} className="cy-multiselect__panel">
                    <div className="cy-multiselect__search">
                        <Input ref={inputRef} type="search" size="sm" placeholder="Search…" aria-label={`Search ${label || 'options'}`} value={search} onChange={e => setSearch(e.target.value)} />
                    </div>
                    <div className="cy-multiselect__bar">
                        <Button size="sm" color="theme" variant="outline" onClick={selectAll} disabled={allSelected}>Select all{search ? ' filtered' : ''}</Button>
                        <Button size="sm" color="danger" variant="outline" onClick={clearAll} disabled={selected.length === 0}>Clear</Button>
                        {selected.length > 0 && <span className="cy-multiselect__count" aria-live="polite">{selected.length} selected</span>}
                    </div>
                    <div className="cy-multiselect__list" role="group" aria-label={label || 'Options'}>
                        {filtered.length === 0 ? (
                            <span className="cy-muted cy-multiselect__none">No matches found</span>
                        ) : (
                            filtered.map(o => (
                                <Checkbox key={o.value} checked={selected.includes(o.value)} onCheckedChange={() => toggle(o.value)} label={o.label} />
                            ))
                        )}
                    </div>
                </div>
            )}
        </FormField>
    );
}
