import React from 'react';
import Icon from './Icon.jsx';

/**
 * Cyber toolbar row (a .card-body toolbar): filters on the left, tools on the right. Children are
 * <Toolbar.Group> (and <Toolbar.Group end>). Used inside a flush Card, which draws the line under it.
 */
export function Toolbar({ children, label, className = '' }) {
    return <div className={`cy-toolbar ${className}`.trim()} role="toolbar" aria-label={label}>{children}</div>;
}

export function ToolbarGroup({ children, end = false }) {
    return <div className={end ? 'cy-toolbar__group cy-toolbar__group--end' : 'cy-toolbar__group'}>{children}</div>;
}

/** Text / month / date field with a leading Bootstrap glyph. `label` is the accessible name (no visible label, as Cyber's toolbars). */
export function Field({ icon, label, type = 'text', value, onChange, className = '', ...props }) {
    return (
        <label className="cy-tool">
            <span className="visually-hidden">{label}</span>
            {icon && <Icon name={icon} />}
            <input className={`cy-input cy-input--sm ${className}`.trim()} type={type} value={value} onChange={onChange} placeholder={type === 'search' || type === 'text' ? label : undefined} {...props} />
        </label>
    );
}

/** Native select in Cyber's .form-select look. options = [{ value, label }]. `label` is its accessible name. */
export function Select({ label, value, onChange, options, className = '', ...props }) {
    return (
        <select className={`cy-select cy-select--sm ${className}`.trim()} aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} {...props}>
            {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
    );
}

/** Square icon-only button (refresh, previous / next month). `label` is required: it is the only name. */
export function IconButton({ icon, label, onClick, disabled, spin = false, ...props }) {
    return (
        <button type="button" className="cy-btn cy-btn--outline-default cy-btn--icon" aria-label={label} title={label} onClick={onClick} disabled={disabled} {...props}>
            <Icon name={icon} className={spin ? 'cy-spin' : ''} />
        </button>
    );
}
