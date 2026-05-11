import React, { useEffect, useState } from 'react';
import { Box, Card, Flex, Grid, Heading, Skeleton, Text } from '@radix-ui/themes';
import { CheckCircledIcon, ClockIcon, FileTextIcon, StackIcon } from '@radix-ui/react-icons';
import axios from 'axios';

const STAT_CONFIG = [
  { key: 'total',           label: 'Total Tasks',     Icon: StackIcon,        color: 'accent' },
  { key: 'completed',       label: 'Completed',       Icon: CheckCircledIcon, color: 'green'  },
  { key: 'pending',         label: 'Pending',         Icon: ClockIcon,        color: 'amber'  },
  { key: 'rfi_submissions', label: 'RFI Submissions', Icon: FileTextIcon,     color: 'blue'   },
];

function StatTile({ label, value, Icon, color, loading }) {
  return (
    <Card >
      <Flex align="center" justify="between" mb="2">
        <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          {label}
        </Text>
        <Box style={{
          padding: 5, flexShrink: 0,
          background: `var(--${color}-a3)`,
          borderRadius: 'var(--radius-2)',
        }}>
          <Icon style={{ color: `var(--${color}-9)`, display: 'block' }} />
        </Box>
      </Flex>
      {loading
        ? <Skeleton style={{ width: 52, height: 28 }} />
        : <Text size={{ initial: '4', md: '6' }} weight="bold">{value ?? '\u2014'}</Text>
      }
    </Card>
  );
}

export default function StatisticCard() {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    axios.get(route('stats'))
      .then(r => setStats(r.data.statistics))
      .catch(() => setStats(null))
      .finally(() => setLoading(false));
  }, []);

  return (
    
        <Grid columns="2" gap="3" style={{ flex: 1 }}>
          {STAT_CONFIG.map(({ key, label, Icon, color }) => (
            <StatTile
              key={key}
              label={label}
              value={stats?.[key]}
              Icon={Icon}
              color={color}
              loading={loading}
            />
          ))}
        </Grid>
     
  );
}
