import React from 'react';

const NebulaSidebar = ({ 
  links = [], 
  activeLink = null, 
  onLinkClick, 
  className = '', 
  ...props 
}) => {
  return (
    <div className={`sidebar-shell glass ${className}`} {...props}>
      <div className="sidebar-links">
        {links.map((link, index) => (
          <button
            key={index}
            className={`side-link ${activeLink === link.id ? 'active' : ''}`}
            onClick={() => onLinkClick && onLinkClick(link.id)}
          >
            {link.icon && <span className="side-icon">{link.icon}</span>}
            {link.label}
          </button>
        ))}
      </div>
    </div>
  );
};

export default NebulaSidebar;
