import React from 'react';

const NebulaRadio = ({ 
  label, 
  name, 
  value, 
  checked = false, 
  onChange, 
  disabled = false, 
  className = '',
  ...props 
}) => {
  return (
    <label className={`ng-radio-wrapper ${className}`}>
      <input
        type="radio"
        name={name}
        value={value}
        className="ng-radio"
        checked={checked}
        onChange={onChange}
        disabled={disabled}
        {...props}
      />
      {label && <span className="ng-radio-label">{label}</span>}
    </label>
  );
};

export default NebulaRadio;
