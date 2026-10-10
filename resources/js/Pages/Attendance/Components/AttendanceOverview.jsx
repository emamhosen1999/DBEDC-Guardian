import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { StatStrip } from '@/Components/Cyber';

export default function AttendanceOverview({ date, mode = 'daily', month, scope = 'all' }) {
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const isMonthly = mode === 'monthly';
    const isSelf = scope === 'self';

    useEffect(() => {
        let isMounted = true;
        setLoading(true);

        const request = isMonthly
            ? (() => {
                const [year, m] = (month || '').split('-');
                const params = {
                    currentMonth: parseInt(m, 10) || (new Date().getMonth() + 1),
                    currentYear: parseInt(year, 10) || new Date().getFullYear(),
                };
                // self scope → the current user's own monthly stats; otherwise org-wide
                return isSelf
                    ? axios.get('/attendance/my-monthly-stats', { params })
                    : axios.get(route('attendance.monthlyStats', params));
            })()
            : axios.get(route('attendance.dailyOverview', { date }));

        request
            .then(res => {
                if (!isMounted) return;
                if (isMonthly) {
                    const a = res.data?.stats?.attendance || res.data?.data?.attendance || {};
                    setStats({ present: a.present, absent: a.absent, late: a.lateArrivals, on_leave: a.leaves });
                } else {
                    setStats(res.data);
                }
                setLoading(false);
            })
            .catch(err => {
                console.error('Failed to fetch attendance overview:', err);
                if (isMounted) setLoading(false);
            });

        return () => { isMounted = false; };
    }, [date, mode, month, isMonthly, isSelf]);

    // A figure still loading shows a dash (undefined), never a zero that looks like data.
    const figure = (value) => (loading ? undefined : value ?? 0);
    const statItems = [
        { key: 'present', label: 'Present', value: figure(stats?.present), tone: 'good' },
        { key: 'absent', label: 'Absent', value: figure(stats?.absent), tone: (stats?.absent ?? 0) > 0 ? 'crit' : 'neutral' },
        { key: 'late', label: 'Late arrivals', value: figure(stats?.late), tone: (stats?.late ?? 0) > 0 ? 'warn' : 'neutral' },
        { key: 'leave', label: 'On leave', value: figure(stats?.on_leave), tone: 'info' },
    ];

    return <StatStrip items={statItems} label={isMonthly ? 'Monthly attendance overview' : 'Daily attendance overview'} busy={loading} />;
}
