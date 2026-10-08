import { Head } from '@inertiajs/react';
import React from 'react';

import App from '@/Layouts/App.jsx';
import PageHeader from '@/Components/PageHeader';
import { useCommandData } from '@/Components/Dashboard/Command/kit.jsx';
import { KpiStrip, WidgetGrid, useWidgetPayload, statValue } from '@/Components/Dashboard/Widgets/WidgetGrid.jsx';

function greeting() {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

/* Status chips: scoped organisation figures from the widget registry (they honour the viewer's DepartmentScope),
   then the project's progress from the command-center payload. A chip is dropped when its value is not in a payload
   (PageHeader skips null / undefined). */
function statusChips(command, widgets) {
    const ncrOpen = statValue(widgets, 'project.ncr', 'open');
    const approvals = widgets?.widgets?.find((w) => w.key === 'team.approvals' && !w.error)?.data?.total;
    const absent = statValue(widgets, 'team.today', 'absent');
    const generated = widgets?.generated_at ?? command?.generated_at;
    return [
        { value: statValue(widgets, 'team.today', 'present'), label: 'Present', tone: 'success', title: 'Present today, within your scope' },
        { value: absent, label: 'Absent', tone: absent > 0 ? 'danger' : 'default' },
        { value: statValue(widgets, 'team.today', 'on_leave'), label: 'On leave', tone: 'warning' },
        { value: approvals, label: 'Approvals', tone: approvals > 0 ? 'warning' : 'default', title: 'Requests waiting for your decision' },
        { value: ncrOpen, label: 'Open NCRs', tone: ncrOpen > 0 ? 'danger' : 'success' },
        command?.access?.project !== false && command?.project?.progress != null ? { value: `${command.project.progress}%`, label: 'Progress', tone: 'theme' } : null,
        generated ? { value: new Date(generated).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }), label: 'Updated', tone: 'default' } : null,
    ];
}

export default function Dashboard({ auth }) {
    const { data: command } = useCommandData();
    const { payload: widgets, reload } = useWidgetPayload('main');
    const firstName = auth?.user?.name?.split(' ')?.[0] ?? 'Operator';

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader
                upper
                title="Command"
                muted="Center"
                subtitle={`${greeting()}, ${firstName} — Expressway O&M & TMC Floor.`}
                chips={statusChips(command, widgets)}
            />

            {/* Full-width "Corridor map" widget slot: directly under the PageHeader, above the KPI strip (built separately). */}

            <KpiStrip payload={widgets} />
            <WidgetGrid payload={widgets} onRetry={reload} />
        </>
    );
}

Dashboard.layout = (page) => <App>{page}</App>;
