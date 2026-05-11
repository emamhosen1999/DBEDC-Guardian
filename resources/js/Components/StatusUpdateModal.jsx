import React from 'react';
import { Dialog, Button, Flex, Text } from '@radix-ui/themes';

const StatusUpdateModal = ({ isOpen = false, onClose, onConfirm, title = 'Update Status', message, ...rest }) => (
  <Dialog.Root open={isOpen} onOpenChange={v => { if (!v) onClose?.(); }}>
    <Dialog.Content style={{ maxWidth: 400 }}>
      <Dialog.Title>{title}</Dialog.Title>
      {message && <Text size="2">{message}</Text>}
      <Flex justify="end" gap="2" mt="4">
        <Button variant="outline" color="gray" onClick={onClose}>Cancel</Button>
        <Button onClick={() => { onConfirm?.(); onClose?.(); }}>Confirm</Button>
      </Flex>
    </Dialog.Content>
  </Dialog.Root>
);

export default StatusUpdateModal;
