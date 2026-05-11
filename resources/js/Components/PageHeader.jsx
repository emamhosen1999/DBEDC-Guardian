import React from 'react';
import { Flex, Heading, Text, Box, Button, Separator } from '@radix-ui/themes';

const PageHeader = ({
  title,
  subtitle,
  description,
  icon,
  actions,
  actionButtons,
  children,
  variant,
  compact,
  ...rest
}) => {
  const buttons = actionButtons ?? (actions ? (Array.isArray(actions) ? actions : []) : []);

  return (
    <Box>
      <Flex
        align="start"
        justify="between"
        gap="4"
        px="4"
        py={compact ? '2' : '4'}
        wrap="wrap"
        style={{ borderBottom: '1px solid var(--gray-a4)' }}
      >
        <Flex align="center" gap="3" style={{ minWidth: 0 }}>
          {icon && (
            <Box style={{ color: 'var(--accent-9)', flexShrink: 0 }}>{icon}</Box>
          )}
          <Box style={{ minWidth: 0 }}>
            {subtitle && (
              <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                {subtitle}
              </Text>
            )}
            <Heading size={compact ? '4' : '5'} style={{ lineHeight: 1.2 }}>{title}</Heading>
            {description && (
              <Text size="2" color="gray" style={{ display: 'block', marginTop: 2 }}>{description}</Text>
            )}
          </Box>
        </Flex>

        {buttons.length > 0 && (
          <Flex align="center" gap="2" wrap="wrap" style={{ flexShrink: 0 }}>
            {buttons.map((btn, i) => (
              <Button
                key={i}
                size="2"
                variant={btn.variant || 'solid'}
                color={btn.color}
                disabled={btn.disabled}
                onClick={btn.onPress || btn.onClick}
              >
                {btn.icon && <span style={{ display: 'flex', alignItems: 'center' }}>{btn.icon}</span>}
                {btn.label}
              </Button>
            ))}
          </Flex>
        )}

        {actions && !Array.isArray(actions) && (
          <Flex align="center" gap="2" wrap="wrap" style={{ flexShrink: 0 }}>
            {actions}
          </Flex>
        )}
      </Flex>

      {children && <Box>{children}</Box>}
    </Box>
  );
};

export default PageHeader;
