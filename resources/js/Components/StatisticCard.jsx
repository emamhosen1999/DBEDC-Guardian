import React from 'react';
import { Card, Flex, Box, Text, Skeleton } from '@radix-ui/themes';

const parseColor = (colorClass) => {
  if (!colorClass) return 'gray';
  const c = colorClass.toLowerCase();
  if (c.includes('green') || c.includes('success')) return 'green';
  if (c.includes('red') || c.includes('danger')) return 'red';
  if (c.includes('blue') || c.includes('info') || c.includes('primary')) return 'blue';
  if (c.includes('amber') || c.includes('warning') || c.includes('orange')) return 'amber';
  if (c.includes('purple')) return 'purple';
  if (c.includes('pink')) return 'pink';
  return 'gray';
};

export default function StatisticCard({
  title,
  value,
  icon,
  color,
  description,
  isLoading = false,
}) {
  const radixColor = parseColor(color);

  return (
    <Card style={{ flex: '1 1 200px', minWidth: 200 }}>
      <Flex align="center" justify="between" mb="2">
        <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          {title}
        </Text>
        {icon && (
          <Box style={{
            padding: 6,
            borderRadius: 'var(--radius-2)',
            background: `var(--${radixColor}-a3)`,
            color: `var(--${radixColor}-9)`,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}>
            {icon}
          </Box>
        )}
      </Flex>
      {isLoading ? (
        <Flex direction="column" gap="1">
          <Skeleton style={{ width: 52, height: 28 }} />
          <Skeleton style={{ width: 100, height: 12 }} />
        </Flex>
      ) : (
        <Flex direction="column" gap="1">
          <Text size={{ initial: '5', md: '6' }} weight="bold">
            {value ?? '\u2014'}
          </Text>
          {description && (
            <Text size="1" color="gray">
              {description}
            </Text>
          )}
        </Flex>
      )}
    </Card>
  );
}
