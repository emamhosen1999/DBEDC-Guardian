import React from 'react';
import { Flex, Text, Box, Button } from '@radix-ui/themes';

const NoDataMessage = ({
  message = 'No data available',
  description,
  icon,
  action,
  actionLabel,
  onAction,
  title,
  ...rest
}) => (
  <Flex
    direction="column"
    align="center"
    justify="center"
    gap="3"
    py="8"
    px="4"
    style={{ textAlign: 'center', width: '100%' }}
    {...rest}
  >
    {icon && (
      <Box style={{ color: 'var(--gray-8)', fontSize: 40 }}>{icon}</Box>
    )}
    <Text size="3" weight="medium" color="gray">{title || message}</Text>
    {description && <Text size="2" color="gray">{description}</Text>}
    {(onAction || action) && (
      <Button variant="soft" onClick={onAction || action}>{actionLabel || 'Try again'}</Button>
    )}
  </Flex>
);

export default NoDataMessage;
