import React from 'react';

const Eyebrow = ({ 
  children, 
  className = '', 
  ...props 
}) => {
  return (
    <div className={`eyebrow ${className}`} {...props}>
      {children}
    </div>
  );
};

export default Eyebrow;
