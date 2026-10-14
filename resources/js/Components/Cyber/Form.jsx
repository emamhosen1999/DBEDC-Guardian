import React, { forwardRef, useId } from 'react';
import Icon from './Icon.jsx';

/**
 * Cyber form controls (form_elements.html): .form-label, .form-control, .form-select, .form-check, .form-switch,
 * textarea, .alert and a segmented button group. Every control keeps a real label / accessible name.
 */

/** <FormField label hint error required htmlFor> wraps one control: label above, hint / error below. */
export function FormField({ label, htmlFor, hint, error, required = false, className = '', children }) {
    const hintId = useId();
    return (
        <div className={['cy-formfield', className].filter(Boolean).join(' ')}>
            {label && <label className="cy-label" htmlFor={htmlFor}>{label}{required && <span aria-hidden="true"> *</span>}</label>}
            {typeof children === 'function' ? children({ describedBy: hint || error ? hintId : undefined }) : children}
            {error ? <p id={hintId} className="cy-formtext cy-formtext--error" role="alert">{error}</p>
                : hint ? <p id={hintId} className="cy-formtext">{hint}</p> : null}
        </div>
    );
}

/** Text-like input (.form-control). size="sm" is .form-control-sm. */
export const Input = forwardRef(function Input({ size, invalid = false, className = '', type = 'text', ...props }, ref) {
    return <input ref={ref} type={type} aria-invalid={invalid || undefined} className={['cy-input', size === 'sm' ? 'cy-input--sm' : '', invalid ? 'is-invalid' : '', className].filter(Boolean).join(' ')} {...props} />;
});

/** textarea.form-control */
export const Textarea = forwardRef(function Textarea({ invalid = false, rows = 3, className = '', ...props }, ref) {
    return <textarea ref={ref} rows={rows} aria-invalid={invalid || undefined} className={['cy-input', 'cy-textarea', invalid ? 'is-invalid' : '', className].filter(Boolean).join(' ')} {...props} />;
});

/** Native select in .form-select. options = [{ value, label, disabled? }]; onChange receives the value. */
export const SelectInput = forwardRef(function SelectInput({ options, onChange, size, invalid = false, className = '', ...props }, ref) {
    return (
        <select ref={ref} aria-invalid={invalid || undefined} className={['cy-select', 'cy-select--block', size === 'sm' ? 'cy-select--sm' : '', invalid ? 'is-invalid' : '', className].filter(Boolean).join(' ')} onChange={(e) => onChange?.(e.target.value)} {...props}>
            {options.map((o) => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>)}
        </select>
    );
});

/** .form-check checkbox with its label. `onCheckedChange(boolean)`. Indeterminate is set on the DOM node. */
export const Checkbox = forwardRef(function Checkbox({ label, checked, onCheckedChange, indeterminate = false, className = '', id, children, ...props }, ref) {
    const auto = useId();
    const inputId = id ?? auto;
    return (
        <div className={['cy-check', className].filter(Boolean).join(' ')}>
            <input
                ref={(el) => { if (el) el.indeterminate = indeterminate; if (typeof ref === 'function') ref(el); else if (ref) ref.current = el; }}
                id={inputId}
                type="checkbox"
                className="cy-check__input"
                checked={checked}
                onChange={(e) => onCheckedChange?.(e.target.checked)}
                {...props}
            />
            {(label || children) && <label className="cy-check__label" htmlFor={inputId}>{label ?? children}</label>}
        </div>
    );
});

/** .form-switch. `onCheckedChange(boolean)`. role="switch" keeps the state announced as on / off. */
export const Switch = forwardRef(function Switch({ label, checked, onCheckedChange, className = '', id, ...props }, ref) {
    const auto = useId();
    const inputId = id ?? auto;
    return (
        <div className={['cy-check', 'cy-check--switch', className].filter(Boolean).join(' ')}>
            <input ref={ref} id={inputId} type="checkbox" role="switch" className="cy-check__input" checked={checked} onChange={(e) => onCheckedChange?.(e.target.checked)} {...props} />
            {label && <label className="cy-check__label" htmlFor={inputId}>{label}</label>}
        </div>
    );
});

/** .alert. tone: info | danger | warning | success. `onDismiss` adds a close control (alerts, not cards, may be dismissed). */
export function Alert({ tone = 'info', title, onDismiss, children, className = '', role }) {
    return (
        <div className={['cy-alert', className].filter(Boolean).join(' ')} data-tone={tone} role={role ?? (tone === 'danger' ? 'alert' : 'status')}>
            <div className="cy-alert__body">{title && <strong>{title}</strong>}{children}</div>
            {onDismiss && <button type="button" className="cy-alert__close" onClick={onDismiss} aria-label="Dismiss"><Icon name="x-lg" /></button>}
        </div>
    );
}

/** Segmented choice (a .btn-group of outline buttons). options = [{ value, label, icon }]; the selected one is aria-pressed. */
export function Segmented({ options, value, onChange, label, className = '' }) {
    return (
        <div className={['cy-seg', className].filter(Boolean).join(' ')} role="group" aria-label={label}>
            {options.map((o) => (
                <button key={o.value} type="button" className="cy-btn cy-btn--outline-default cy-btn--sm" aria-pressed={o.value === value} onClick={() => onChange(o.value)}>
                    {o.icon && <Icon name={o.icon} />}{o.label}
                </button>
            ))}
        </div>
    );
}

/**
 * Date or time field with the value contract of the old picker: `mode` date -> 'YYYY-MM-DD', time -> 'HH:mm'.
 * onChange(value) receives '' when cleared. Native pickers keep the keyboard and screen-reader behaviour.
 */
export const DateInput = forwardRef(function DateInput({ mode = 'date', value, onChange, label, min, max, size, ...props }, ref) {
    return (
        <Input ref={ref} type={mode === 'time' ? 'time' : 'date'} size={size} value={value ?? ''} min={min} max={max} aria-label={label} onChange={(e) => onChange?.(e.target.value)} {...props} />
    );
});

/** Two date fields forming a range; onChange({ start, end }). */
export function DateRange({ value, onChange, startLabel = 'From', endLabel = 'To', min, max, size }) {
    const start = value?.start ?? '';
    const end = value?.end ?? '';
    return (
        <div className="cy-daterange">
            <DateInput label={startLabel} value={start} max={end || max} min={min} size={size} onChange={(v) => onChange?.({ start: v, end })} />
            <span className="cy-daterange__sep" aria-hidden="true">to</span>
            <DateInput label={endLabel} value={end} min={start || min} max={max} size={size} onChange={(v) => onChange?.({ start, end: v })} />
        </div>
    );
}
