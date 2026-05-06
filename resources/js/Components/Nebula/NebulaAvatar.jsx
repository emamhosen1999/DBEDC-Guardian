import React from 'react';

const NebulaAvatar = ({ 
  src = null, 
  alt = '', 
  initials = '', 
  size = 'md', 
  className = '', 
  ...props 
}) => {
  const sizeClasses = {
    sm: 'ng-avatar-sm',
    md: '',
    lg: 'ng-avatar-lg',
  };

  const classes = [
    'ng-avatar',
    sizeClasses[size] || sizeClasses.md,
    className,
  ].filter(Boolean).join(' ');

  if (src) {
    return (
      <img 
        src={src} 
        alt={alt} 
        className={classes} 
        {...props}
      />
    );
  }

  return (
    <div className={classes} {...props}>
      {initials}
    </div>
  );
};

const NebulaAvatarStack = ({ 
  children, 
  max = 3, 
  className = '', 
  ...props 
}) => {
  const avatars = React.Children.toArray(children);
  const visible = avatars.slice(0, max);
  const remaining = Math.max(0, avatars.length - max);

  return (
    <div className={`ng-avatar-stack ${className}`} {...props}>
      {visible.map((avatar, index) => (
        <div 
          key={index} 
          className="ng-avatar-stack-item"
          style={{ zIndex: visible.length - index }}
        >
          {avatar}
        </div>
      ))}
      {remaining > 0 && (
        <div className="ng-avatar-stack-item ng-avatar-stack-more">
          +{remaining}
        </div>
      )}
    </div>
  );
};

export { NebulaAvatar, NebulaAvatarStack };
