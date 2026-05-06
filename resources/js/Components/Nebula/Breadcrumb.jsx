import React from 'react';

const Breadcrumb = ({ 
  items = [], 
  className = '', 
  separator = '/', 
  ...props 
}) => {
  return (
    <nav className={`ng-breadcrumb ${className}`} {...props}>
      <ol className="ng-breadcrumb-list">
        {items.map((item, index) => (
          <li key={index} className="ng-breadcrumb-item">
            {index > 0 && (
              <span className="ng-breadcrumb-separator">{separator}</span>
            )}
            {index === items.length - 1 ? (
              <span className="ng-breadcrumb-current">{item.label}</span>
            ) : (
              <a href={item.href} className="ng-breadcrumb-link">
                {item.label}
              </a>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
};

export default Breadcrumb;
