import React, { useState } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import { useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';

import App from '@/Layouts/App.jsx';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PageHeader from '@/Components/PageHeader';
import PunchStatusCard from '@/Components/PunchStatusCard.jsx';
import { Button, Card, Icon } from '@/Components/Cyber';
import { KpiStrip, WidgetGrid, WidgetCard, useWidgetPayload, widgetByKey } from '@/Components/Dashboard/Widgets/WidgetGrid.jsx';
import MyRequests from './Attendance/Components/MyRequests';
import SwapResponses from './Attendance/Components/SwapResponses';

// Forms for Quick Actions
import SwapRequestForm from '@/Forms/SwapRequestForm';
import RegularizationForm from '@/Forms/RegularizationForm';
import OvertimeRequestForm from '@/Forms/OvertimeRequestForm';

/* Widgets rendered by their own components below (punch card = today, MyRequests = the request list) or placed
   explicitly in the first rows (my shift). The registry still feeds them to the header chips and the mobile app. */
const RENDERED_ELSEWHERE = ['me.attendance_today', 'me.shift', 'me.pending_requests'];

const CHIP_TONE = { good: 'success', info: 'warning', warn: 'warning', crit: 'danger' };
const STATUS_CHIP = { checked_in: 'In', checked_out: 'Out', on_leave: 'Leave', not_punched: 'Not in' };

/* Status chips from the registry only - a chip is dropped when its value is missing. */
function statusChips(widgets) {
    const today = widgetByKey(widgets, 'me.attendance_today');
    const shift = widgetByKey(widgets, 'me.shift');
    const balances = widgetByKey(widgets, 'me.leave_balances');
    const requests = widgetByKey(widgets, 'me.pending_requests');
    const leaveLeft = balances?.rows?.length ? Math.round(balances.rows.reduce((sum, r) => sum + (r.remaining ?? 0), 0) * 10) / 10 : null;
    return [
        { value: STATUS_CHIP[today?.status], label: 'Status', tone: CHIP_TONE[today?.tone] ?? 'default' },
        { value: shift?.today, label: 'Shift', tone: 'default' },
        { value: leaveLeft, label: 'Leave left', tone: 'theme' },
        { value: requests?.total, label: 'Requests', tone: requests?.total > 0 ? 'warning' : 'default' },
    ];
}

/** The swap inbox as a full-width Cyber row; nothing at all (not even an empty row) when nothing is waiting. */
function SwapResponsesRow() {
    return <div className="dl-row cy-row-optional"><div className="dl-col dl-col--12"><SwapResponses /></div></div>;
}

export default function EmployeeDashboard() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const queryClient = useQueryClient();
    const { payload: widgets, reload } = useWidgetPayload('employee');

    const shiftWidget = widgets?.widgets?.find((w) => w.key === 'me.shift');
    const requestsWidget = widgets?.widgets?.find((w) => w.key === 'me.pending_requests');

    // Modal trigger states
    const [swapOpen, setSwapOpen] = useState(false);
    const [regOpen, setRegOpen] = useState(false);
    const [otOpen, setOtOpen] = useState(false);

    const handleSaved = () => {
        ['my-regularizations', 'my-overtime', 'my-comp-off', 'my-swaps', 'swaps', 'awaiting-me']
            .forEach(key => queryClient.invalidateQueries({ queryKey: [key] }));
        reload();
    };

    const who = [user?.name, user?.employee_id, [user?.designation?.title, user?.department?.name].filter(Boolean).join(' · ')].filter(Boolean).join(' · ');

    return (
        <>
            <Head title="Employee Dashboard" />
            <PageHeader
                upper
                title="My"
                muted="Home"
                subtitle={`${dayjs().format('dddd, DD MMM YYYY')}${who ? ` — ${who}` : ''}`}
                chips={statusChips(widgets)}
                actions={(
                    <>
                        <Button variant="outline" size="sm" onClick={() => setSwapOpen(true)}><Icon name="arrow-left-right" /> Shift swap</Button>
                        <Button variant="outline" size="sm" onClick={() => setRegOpen(true)}><Icon name="clipboard-check" /> Regularize</Button>
                        <Button variant="outline" size="sm" onClick={() => setOtOpen(true)}><Icon name="hourglass-split" /> Overtime</Button>
                        <Button as={Link} href={route('leaves-employee')} variant="outline" size="sm"><Icon name="calendar-plus" /> Apply for leave</Button>
                    </>
                )}
            />

            <KpiStrip payload={widgets} />

            <div className="dl-page">
                <div className="dl-row">
                    <div className="dl-col dl-col--6">
                        <ErrorBoundary>
                            <Card id="employee:punch" title="Punch" flush>
                                <PunchStatusCard />
                            </Card>
                        </ErrorBoundary>
                    </div>
                    <div className="dl-col dl-col--6">
                        {shiftWidget
                            ? <WidgetCard widget={shiftWidget} onRetry={reload} />
                            : <Card id="widget:me.shift" title="My shift" flush aria-busy="true"><div className="dl-empty"><span className="cy-row__sub">Loading…</span></div></Card>}
                    </div>
                </div>

                <ErrorBoundary>
                    <SwapResponsesRow />
                </ErrorBoundary>

                <div className="dl-row">
                    <div className="dl-col dl-col--8">
                        <ErrorBoundary>
                            <Card id="employee:my-requests" title="My requests" flush>
                                <MyRequests />
                            </Card>
                        </ErrorBoundary>
                    </div>
                    <div className="dl-col dl-col--4">
                        {requestsWidget
                            ? <WidgetCard widget={requestsWidget} onRetry={reload} />
                            : <Card id="widget:me.pending_requests" title="Pending requests" flush aria-busy="true"><div className="dl-empty"><span className="cy-row__sub">Loading…</span></div></Card>}
                    </div>
                </div>

                <WidgetGrid payload={widgets} skip={RENDERED_ELSEWHERE} onRetry={reload} />
            </div>

            {/* Modals & Forms */}
            <SwapRequestForm open={swapOpen} onOpenChange={setSwapOpen} onSaved={handleSaved} />
            <RegularizationForm open={regOpen} onOpenChange={setRegOpen} onSaved={handleSaved} />
            <OvertimeRequestForm open={otOpen} onOpenChange={setOtOpen} onSaved={handleSaved} />
        </>
    );
}

EmployeeDashboard.layout = (page) => <App>{page}</App>;
