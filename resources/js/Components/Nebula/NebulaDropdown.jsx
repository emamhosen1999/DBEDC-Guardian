import React, { useState, useRef, useEffect } from 'react';

const NebulaDropdown = ({ 
  trigger, 
  items = [], 
  className = '', 
  ...props 
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen]);

  return (
    <div className={`ng-dropdown ${className}`} ref={dropdownRef} {...props}>
      <div onClick={() => setIsOpen(!isOpen)}>
        {trigger}
      </div>
      {isOpen && (
        <div className="dropdown-menu glass">
          {items.map((item, index) => (
            <button
              key={index}
              className={`dropdown-item ${item.active ? 'active' : ''}`}
              onClick={() => {
                item.onClick && item.onClick();
                setIsOpen(false);
              }}
            >
              {item.label}
              {item.icon && <span>{item.icon}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

export default NebulaDropdown;
