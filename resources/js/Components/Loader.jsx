import React from 'react';
import { Flex, Text } from '@radix-ui/themes';

const Loader = ({ label = 'Loading…', size = 'md', ...rest }) => {
  const px = size === 'sm' ? 20 : size === 'lg' ? 40 : 28;
  return (
    <Flex align="center" justify="center" gap="2" {...rest}>
      <div style={{
        width: px, height: px,
        border: '3px solid var(--accent-a5)',
        borderTop: '3px solid var(--accent-9)',
        borderRadius: '50%',
        animation: 'spin 0.7s linear infinite',
      }} />
      {label && <Text size="2" color="gray">{label}</Text>}
    </Flex>
  );
};

export default Loader;
