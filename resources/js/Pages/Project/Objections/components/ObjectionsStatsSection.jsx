import React, { useMemo } from 'react';
import StatsCards from '@/Components/StatsCards.jsx';
import { buildObjectionsStats } from '../utils/buildObjectionsStats';

export default function ObjectionsStatsSection({
    apiStats,
    objections,
    statsLoading,
    onRefresh,
}) {
    const stats = useMemo(
        () => buildObjectionsStats(apiStats, objections),
        [apiStats, objections]
    );

    return (
        <div className="relative">
            <StatsCards
                stats={stats}
                onRefresh={onRefresh}
                loading={statsLoading}
            />
        </div>
    );
}
