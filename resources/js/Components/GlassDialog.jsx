import React from 'react';
import { Dialog } from '@radix-ui/themes';

const GlassDialog = ({ children, isOpen, onClose, title, ...rest }) => (
  <Dialog.Root open={isOpen} onOpenChange={v => { if (!v) onClose?.(); }}>
    <Dialog.Content>
      {title && <Dialog.Title>{title}</Dialog.Title>}
      {children}
    </Dialog.Content>
  </Dialog.Root>
);

export default GlassDialog;
