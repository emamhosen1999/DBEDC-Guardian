import React from 'react';

const NebulaNavbar = ({ 
  logo = null, 
  links = [], 
  actions = [], 
  className = '', 
  ...props 
}) => {
  return (
    <nav className={`nav-shell glass ${className}`} {...props}>
      <div className="nav-brand">
        {logo}
      </div>
      <div className="nav-links">
        {links.map((link, index) => (
          <a
            key={index}
            href={link.href}
            className={`nav-link ${link.active ? 'active' : ''}`}
            onClick={link.onClick}
          >
            {link.label}
          </a>
        ))}
      </div>
      <div className="nav-actions">
        {actions.map((action, index) => (
          <button
            key={index}
            className="nav-link"
            onClick={action.onClick}
          >
            {action.icon || action.label}
          </button>
        ))}
      </div>
    </nav>
  );
};

export default NebulaNavbar;
