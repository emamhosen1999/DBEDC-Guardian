import React from 'react';
import { Flex, Box } from '@radix-ui/themes';
import StatisticCard from './StatisticCard';

const StatsCards = ({ stats = [], isLoading = false, onRefresh, className, style, ...rest }) => (
  <Flex gap="3" wrap="wrap" mb="4" style={style}>
    {stats.map((s, i) => (
      <StatisticCard
        key={s.key || s.title || i}
        title={s.title || s.label}
        value={s.value}
        icon={s.icon}
        iconBg={s.iconBg}
        color={s.color}
        description={s.description || s.subtitle}
        trend={s.trend}
        isLoading={isLoading}
      />
    ))}
  </Flex>
);

export default StatsCards;
