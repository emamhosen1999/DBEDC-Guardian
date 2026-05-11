import React from 'react';
import { Dialog, Button, Text, Flex } from '@radix-ui/themes';
import { router } from '@inertiajs/react';

const SessionExpiredModal = ({ isOpen = false, onClose, ...rest }) => (
  <Dialog.Root open={isOpen} onOpenChange={v => { if (!v) onClose?.(); }}>
    <Dialog.Content style={{ maxWidth: 380 }}>
      <Dialog.Title>Session Expired</Dialog.Title>
      <Text size="2" color="gray">Your session has expired. Please log in again to continue.</Text>
      <Flex justify="end" gap="2" mt="4">
        <Button onClick={() => router.get(route('login'))}>Log In Again</Button>
      </Flex>
    </Dialog.Content>
  </Dialog.Root>
);

export default SessionExpiredModal;
