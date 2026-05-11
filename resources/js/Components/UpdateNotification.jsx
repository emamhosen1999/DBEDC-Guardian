import React from 'react';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

const UpdateNotification = ({ isVisible = false, onUpdate, onDismiss, isUpdating = false, version, ...rest }) => {
  if (!isVisible) return null;
  return (
    <Box style={{
      position: 'fixed', top: 16, right: 16, zIndex: 9999,
      background: 'var(--accent-9)', color: '#fff',
      borderRadius: 'var(--radius-3)', padding: '12px 16px',
      boxShadow: 'var(--shadow-4)', maxWidth: 320,
    }}>
      <Text size="2" weight="bold" style={{ display: 'block', color: '#fff' }}>
        Update available {version ? `(v${version})` : ''}
      </Text>
      <Text size="1" style={{ display: 'block', color: 'rgba(255,255,255,0.85)', marginTop: 4 }}>
        A new version is ready to install.
      </Text>
      <Flex gap="2" mt="3">
        <Button size="1" variant="solid" style={{ background: '#fff', color: 'var(--accent-9)' }} onClick={onUpdate} disabled={isUpdating}>
          {isUpdating ? 'Updating…' : 'Update now'}
        </Button>
        <Button size="1" variant="ghost" style={{ color: '#fff' }} onClick={onDismiss}>Later</Button>
      </Flex>
    </Box>
  );
};

export default UpdateNotification;
