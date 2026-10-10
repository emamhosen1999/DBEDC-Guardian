import React, { useState, useCallback, lazy, Suspense } from 'react';
import { Head, usePage } from '@inertiajs/react';
import App from '@/Layouts/App';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import dayjs from 'dayjs';
import PageHeader from '@/Components/PageHeader';
import { Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';

import DailyTimesheetTab  from './DailyTimesheetTab';
const MonthlyCalendarTab = lazy(() => import('./MonthlyCalendarTab'));
const RosterTab          = lazy(() => import('./RosterTab'));
const AnalyticsTab       = lazy(() => import('./AnalyticsTab'));
const ShiftsSettings     = lazy(() => import('./ShiftsSettings'));
const SettingsTab        = lazy(() => import('./SettingsTab'));
const ApprovalsInbox     = lazy(() => import('./Components/ApprovalsInbox'));
const BiometricPanel     = lazy(() => import('@/Components/AdminUnified/BiometricPanel'));
import ErrorBoundary      from '@/Components/ErrorBoundary/ErrorBoundary';

/* Id prefix of the tab list: tab and panel ids stay stable across renders. */
const TABS_ID = 'attendance';

/* Second, lighter half of the two-tone header: what the open tab is. */
const TAB_MUTED = {
    timesheet: 'Timesheet', monthly: 'Calendar', analytics: 'Analytics', approvals: 'Approvals',
    roster: 'Roster', shifts: 'Shifts', settings: 'Settings', biometric: 'Devices',
};

/* Loading state while a tab's chunk arrives (Cyber's empty-state box, announced to screen readers). */
const TabLoading = () => (
    <div className="cy-empty" role="status"><p className="cy-empty__text">Loading…</p></div>
);

/* The roster, analytics, approvals, shift, settings and biometric tabs keep their own layouts for now (batch A2);
   they sit in the new shell with the page gutter their own markup expects. */
const LegacyTab = ({ children }) => <div className="cy-legacy-tab">{children}</div>;

/* ── optional: mark-as-present modals (keep your existing) ── */
// import MarkAsPresentForm     from '@/Forms/MarkAsPresentForm';
// import BulkMarkAsPresentForm from '@/Forms/BulkMarkAsPresentForm';

/* `biometricEmployees` is the roster the Biometric Devices tab's unknown-punch
 * picker resolves PINs against — `{ id, name, employee_id }`, everyone, not just
 * the Employee-role/own-department set the `employees` prop carries for the
 * roster and shift tabs. The controller only sends it to users who can open that
 * tab; everyone else gets `[]`, which BiometricPanel handles by fetching its own
 * copy. Defaulted here so an older cached page bundle cannot crash on it. */
const AttendancePage = ({ title, departments = [], designations = [], devices = [], biometricEmployees = [] }) => {
    const { auth } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 640px)');

    /* Each tab is a distinct view with its own data, so it belongs in the URL:
       /attendance?tab=roster survives a refresh and can be shared. */
    /* The day and month being looked at decide what every tab fetches, so they
       live in the URL as well. Defaults are empty so that an explicitly chosen
       day stays visible in a copied link; empty resolves to today. Each tab's
       own filters use a short prefix (r_ roster, t_ timesheet, m_ monthly,
       a_ approvals, s_ shifts) because every tab stays mounted. */
    const f = useQueryFilters({
        mode: 'client',
        defaults: { tab: 'timesheet', date: '', month: '' },
        debounceKeys: [],
    });
    const { set: setTab, setMany: setDates } = f;
    const setActiveTab = useCallback((val) => setTab('tab', val), [setTab]);

    const selectedDate = f.values.date || dayjs().format('YYYY-MM-DD');
    const selectedMonth = f.values.month || dayjs(selectedDate).format('YYYY-MM');

    /* date change — keep daily and monthly in sync, in one URL write */
    const handleDateChange = useCallback(e => {
        const val = e.target.value;
        setDates({ date: val, month: dayjs(val).format('YYYY-MM') });
    }, [setDates]);

    const handleMonthChange = useCallback(val => {
        setDates({ month: val });
    }, [setDates]);

    /* permissions — Super Administrator bypasses all gates unconditionally (matches the
       backend Gate::before bypass), even for abilities that don't exist as permission records. */
    const isSuperAdmin = auth.isSuperAdmin || false;
    const has = (permission) => isSuperAdmin || auth.permissions?.includes(permission) || false;
    /* Company-wide attendance CONFIGURATION (settings, biometric devices, shift definitions,
       rotation patterns, policies) stays on attendance.settings. */
    const canSettings = has('attendance.settings');
    /* PER-EMPLOYEE roster work — assign shifts, edit the roster, decide swaps — rides on
       attendance.roster.manage (what a department admin holds; attendance.settings implies it,
       matching the route gate). Department Managers keep the read-only roster view. */
    const canRosterManage = has('attendance.roster.manage') || canSettings;
    const isDeptManager = auth.roles?.includes('Department Manager') || false;
    const canRoster = canRosterManage || isDeptManager;
    /* The Approvals inbox (regularizations, overtime, swaps, punch exceptions) is gated per
       endpoint in routes/web.php by these permissions — `attendance.manage` alone is not a
       real permission, so it only ever opened the tab for a Super Administrator. */
    const canManage = has('attendance.manage') || has('attendance.correct') || has('attendance.create')
        || has('attendance.update') || canRosterManage;

    /* tab definitions */
    const tabs = [
        { key: 'timesheet', label: 'Daily Timesheet' },
        { key: 'monthly',   label: 'Monthly Calendar' },
        { key: 'analytics', label: 'Analytics' },
        ...(canManage ? [{ key: 'approvals', label: 'Approvals' }] : []),
        ...(canRoster ? [{ key: 'roster',   label: 'Roster' }] : []),
        ...(canRoster ? [{ key: 'shifts',   label: 'Shift Management' }] : []),
        ...(canSettings ? [{ key: 'settings',  label: 'Settings' }] : []),
        ...(canSettings ? [{ key: 'biometric', label: 'Biometric Devices' }] : []),
    ];

    /* A URL can name a tab this user may not open - a stale link, or a permission
       revoked since. Fall back to the first tab rather than render nothing. */
    const activeTab = tabs.some(t => t.key === f.values.tab) ? f.values.tab : tabs[0].key;

    /* Header chips: the open tab reports its own figures (the timesheet its day partition, the calendar its month);
       the date chip is the shell's. A tab that has nothing to report adds nothing. */
    const [reported, setReported] = useState({});
    const report = useCallback((tab, chips) => {
        setReported((prev) => (JSON.stringify(prev[tab]) === JSON.stringify(chips) ? prev : { ...prev, [tab]: chips }));
    }, []);
    const reportTimesheet = useCallback((chips) => report('timesheet', chips), [report]);
    const reportMonthly = useCallback((chips) => report('monthly', chips), [report]);

    const dateChip = activeTab === 'monthly'
        ? { value: dayjs(selectedMonth + '-01').format('MMM YYYY'), label: 'Month', tone: 'theme' }
        : activeTab === 'settings' || activeTab === 'biometric' ? null
            : { value: dayjs(selectedDate).format('D MMM YYYY'), label: 'Date', tone: 'theme' };
    const headerChips = [...(reported[activeTab] ?? []), dateChip];

    const panel = (key) => ({
        role: 'tabpanel',
        id: panelId(TABS_ID, key),
        'aria-labelledby': tabId(TABS_ID, key),
        hidden: activeTab !== key,
    });

    /* ── render ───────────────────────────────────────────── */
    return (
        <>
            <Head title={title || 'Attendance'} />

            {/* Cyber page header: two-tone title, the open tab's figures as chips (first five, the rest under "+N") */}
            <PageHeader upper title="Attendance" muted={TAB_MUTED[activeTab]} chips={headerChips} />

            <Tabs tabs={tabs} value={activeTab} onChange={setActiveTab} idPrefix={TABS_ID} label="Attendance sections" className="cy-tabs--scroll" />

            {/* Every tab stays mounted (its filters live in the URL under their own prefix); only the open one shows. */}
            <div {...panel('timesheet')}>
                <ErrorBoundary>
                    <DailyTimesheetTab
                        selectedDate={selectedDate}
                        onDateChange={handleDateChange}
                        isActive={activeTab === 'timesheet'}
                        departments={departments}
                        designations={designations}
                        onSummary={reportTimesheet}
                    />
                </ErrorBoundary>
            </div>

            <div {...panel('monthly')}>
                <ErrorBoundary>
                    <Suspense fallback={<TabLoading />}>
                        <MonthlyCalendarTab
                            selectedMonth={selectedMonth}
                            onMonthChange={handleMonthChange}
                            departments={departments}
                            onSummary={reportMonthly}
                        />
                    </Suspense>
                </ErrorBoundary>
            </div>

            <div {...panel('analytics')}>
                <LegacyTab>
                    <ErrorBoundary>
                        <Suspense fallback={<TabLoading />}>
                            {/* Only fetches while visible; the tab stays mounted like the others. */}
                            <AnalyticsTab
                                month={selectedMonth}
                                onMonthChange={handleMonthChange}
                                departments={departments}
                                canViewTeam={isSuperAdmin || auth.permissions?.includes('attendance.view') || false}
                                isActive={activeTab === 'analytics'}
                                isMobile={isMobile}
                            />
                        </Suspense>
                    </ErrorBoundary>
                </LegacyTab>
            </div>

            {canManage && (
                <div {...panel('approvals')}>
                    <LegacyTab>
                        <ErrorBoundary>
                            <Suspense fallback={<TabLoading />}>
                                <ApprovalsInbox />
                            </Suspense>
                        </ErrorBoundary>
                    </LegacyTab>
                </div>
            )}

            {canRoster && (
                <div {...panel('roster')}>
                    <LegacyTab>
                        <ErrorBoundary>
                            <Suspense fallback={<TabLoading />}>
                                <RosterTab
                                    departments={departments}
                                    month={selectedMonth}
                                    onMonthChange={handleMonthChange}
                                    isActive={activeTab === 'roster'}
                                />
                            </Suspense>
                        </ErrorBoundary>
                    </LegacyTab>
                </div>
            )}

            {canRoster && (
                <div {...panel('shifts')}>
                    <LegacyTab>
                        <ErrorBoundary>
                            <Suspense fallback={<TabLoading />}>
                                <ShiftsSettings />
                            </Suspense>
                        </ErrorBoundary>
                    </LegacyTab>
                </div>
            )}

            {canSettings && (
                <div {...panel('settings')}>
                    <LegacyTab>
                        <ErrorBoundary>
                            <Suspense fallback={<TabLoading />}>
                                <SettingsTab />
                            </Suspense>
                        </ErrorBoundary>
                    </LegacyTab>
                </div>
            )}

            {canSettings && (
                <div {...panel('biometric')}>
                    <LegacyTab>
                        <ErrorBoundary>
                            <Suspense fallback={<TabLoading />}>
                                <BiometricPanel
                                    initialDevices={devices}
                                    employees={biometricEmployees}
                                    isMobile={isMobile}
                                    tick={0}
                                    onCountChange={() => {}}
                                    onSetHeaderActions={() => {}}
                                    isActive={activeTab === 'biometric'}
                                />
                            </Suspense>
                        </ErrorBoundary>
                    </LegacyTab>
                </div>
            )}
        </>
    );
};

AttendancePage.layout = page => <App>{page}</App>;

export default AttendancePage;
