import React from 'react';

const NebulaLabel = ({ children, className = '', ...props }) => {
  return (
    <label className={`ng-label ${className}`} {...props}>
      {children}
    </label>
  );
};

export default NebulaLabel;
