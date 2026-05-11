import React from 'react';
import { Flex, Text, Separator } from '@radix-ui/themes';

const InfoRow = ({ label, value, icon, color, ...rest }) => (
  <Flex align="center" justify="between" py="1" gap="3" {...rest}>
    <Text size="2" color="gray">{label}</Text>
    <Text size="2" weight="medium" color={color}>{value ?? '—'}</Text>
  </Flex>
);

export default InfoRow;
