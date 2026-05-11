import React from 'react';
import { Dialog, Box } from '@radix-ui/themes';

const EnhancedModal = ({ children, isOpen, onClose, onOpenChange, title, size, ...rest }) => (
  <Dialog.Root open={isOpen} onOpenChange={v => { if (!v) onClose?.(); onOpenChange?.(v); }}>
    <Dialog.Content style={{ maxWidth: size === 'lg' ? 700 : size === 'xl' ? 900 : size === 'sm' ? 380 : 520 }}>
      {title && <Dialog.Title>{title}</Dialog.Title>}
      {typeof children === 'function' ? children({ onClose }) : children}
    </Dialog.Content>
  </Dialog.Root>
);

export default EnhancedModal;
