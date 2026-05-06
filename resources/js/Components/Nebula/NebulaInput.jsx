import React from 'react';

const NebulaInput = ({ 
  label, 
  type = 'text', 
  placeholder = '', 
  value = '', 
  onChange, 
  className = '', 
  disabled = false,
  error = null,
  ...props 
}) => {
  return (
    <div className="ng-input-wrapper">
      {label && <label className="ng-label">{label}</label>}
      <input
        type={type}
        className={`ng-input ${error ? 'ng-input-error' : ''} ${className}`}
        placeholder={placeholder}
        value={value}
        onChange={onChange}
        disabled={disabled}
        {...props}
      />
      {error && <div className="ng-input-error-text">{error}</div>}
    </div>
  );
};

const NebulaTextarea = ({ 
  label, 
  placeholder = '', 
  value = '', 
  onChange, 
  className = '', 
  disabled = false,
  rows = 4,
  ...props 
}) => {
  return (
    <div className="ng-input-wrapper">
      {label && <label className="ng-label">{label}</label>}
      <textarea
        className={`ng-textarea ${className}`}
        placeholder={placeholder}
        value={value}
        onChange={onChange}
        disabled={disabled}
        rows={rows}
        {...props}
      />
    </div>
  );
};

const NebulaSelect = ({ 
  label, 
  options = [], 
  value = '', 
  onChange, 
  className = '', 
  disabled = false,
  placeholder = 'Select...',
  ...props 
}) => {
  return (
    <div className="ng-input-wrapper">
      {label && <label className="ng-label">{label}</label>}
      <select
        className={`ng-select ${className}`}
        value={value}
        onChange={onChange}
        disabled={disabled}
        {...props}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
};

export { NebulaInput, NebulaTextarea, NebulaSelect };
