import React from 'react';

const HoloText = ({ 
  children, 
  className = '', 
  ...props 
}) => {
  return (
    <span className={`holo-text ${className}`} {...props}>
      {children}
    </span>
  );
};

export default HoloText;
