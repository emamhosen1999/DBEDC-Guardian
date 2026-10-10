import React, { useMemo } from 'react';
import dayjs from 'dayjs';
import { CyberChart } from '@/Components/Cyber';

/* The month's figures for the employees on the table's current page, in Cyber's side-panel composition:
   sections divided by one solid line (Attendance rate, daily presents, leaves taken). */
export default function MonthlySidebar({
    rows = [],
    days = [],
    monthNum,
    yearNum,
    leaveTypes = [],
    leaveCounts = {},
    isLoading = false,
}) {
    const stats = useMemo(() => {
        let present = 0;
        let absent = 0;
        const trend = days.map((d) => {
            const dateKey = dayjs(`${yearNum}-${String(monthNum).padStart(2, '0')}-${String(d).padStart(2, '0')}`).format('YYYY-MM-DD');
            let dayPresent = 0;
            rows.forEach((row) => {
                const cell = row[dateKey];
                const status = typeof cell === 'object' ? cell?.status : cell;
                if (status === '√') { present += 1; dayPresent += 1; } else if (status === '▼') absent += 1;
            });
            return dayPresent;
        });
        const leaves = (leaveTypes || []).map((t) => {
            let count = 0;
            Object.keys(leaveCounts || {}).forEach((userId) => { count += leaveCounts[userId]?.[t.type] || 0; });
            return { name: t.type, count };
        }).filter((item) => item.count > 0);
        const total = present + absent;
        return { present, absent, rate: total > 0 ? Math.round((present / total) * 100) : 0, trend, leaves };
    }, [rows, days, monthNum, yearNum, leaveTypes, leaveCounts]);

    // Chart options are memoised: CyberChart re-draws whenever the options identity changes.
    const donut = useMemo(() => (t) => ({
        chart: { type: 'donut', height: 150 },
        labels: ['Present', 'Absent'],
        series: [stats.present, stats.absent],
        colors: [t.success, t.danger],
        stroke: { width: 1, colors: [t.border] },
        fill: { type: 'solid', opacity: 1 },
        legend: { show: false },
        dataLabels: { enabled: false },
        plotOptions: { pie: { donut: { size: '68%' } } },
    }), [stats.present, stats.absent]);

    const trend = useMemo(() => (t) => ({
        chart: { type: 'area', height: 130 },
        series: [{ name: 'Present', data: stats.trend }],
        xaxis: { categories: days, tickAmount: 6, labels: { rotate: 0 } },
        yaxis: { labels: { formatter: (v) => Math.round(v) } },
        colors: [t.success],
        legend: { show: false },
        dataLabels: { enabled: false },
    }), [stats.trend, days]);

    const leaves = useMemo(() => (t) => ({
        chart: { type: 'bar', height: 130 },
        series: [{ name: 'Days', data: stats.leaves.map((l) => l.count) }],
        xaxis: { categories: stats.leaves.map((l) => l.name) },
        yaxis: { labels: { formatter: (v) => Math.round(v) } },
        colors: [t.theme],
        fill: { type: 'solid', opacity: 0.85 },
        legend: { show: false },
        dataLabels: { enabled: false },
        plotOptions: { bar: { columnWidth: '45%' } },
    }), [stats.leaves]);

    if (isLoading) {
        return <div className="cy-empty" role="status"><p className="cy-empty__text">Loading analytics…</p></div>;
    }

    return (
        <div className="cy-side">
            <section>
                <h3 className="cy-side__title">Attendance rate</h3>
                <div className="cy-side__figure"><b>{stats.rate}%</b><span className="cy-muted">of marked days</span></div>
                {stats.present + stats.absent > 0 && <CyberChart options={donut} label={`Attendance rate ${stats.rate}%: ${stats.present} present, ${stats.absent} absent`} table={false} />}
                <dl className="cy-side__split">
                    <dt>Present</dt><dd className="cy-text-good">{stats.present}</dd>
                    <dt>Absent</dt><dd className="cy-text-crit">{stats.absent}</dd>
                </dl>
                <p className="cy-sub">Employees on this page: {rows.length}</p>
            </section>

            <section>
                <h3 className="cy-side__title">Daily presents</h3>
                {rows.length > 0
                    ? <CyberChart options={trend} label="Employees present each day of the month" />
                    : <p className="cy-muted">No data to display trend</p>}
            </section>

            {stats.leaves.length > 0 && (
                <section>
                    <h3 className="cy-side__title">Leaves taken</h3>
                    <CyberChart options={leaves} label="Leave days taken by type" />
                </section>
            )}
        </div>
    );
}
