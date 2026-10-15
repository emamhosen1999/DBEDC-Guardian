import React, { Suspense, lazy, useCallback, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';

import App from '@/Layouts/App.jsx';
import { Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';
import { useQueryFilters } from '@/Hooks/useQueryFilters';

import DailyWorksRegister from './DailyWorks/DailyWorksRegister.jsx';

const DailyWorksSummary = lazy(() => import('./DailyWorks/DailyWorksSummary.jsx'));
const DailyWorksObjections = lazy(() => import('./DailyWorks/DailyWorksObjections.jsx'));
const JurisdictionsManager = lazy(() => import('./Jurisdictions/JurisdictionsManager.jsx'));

const TABS_ID = 'dw-tabs';
const TAB_KEYS = ['works', 'summary', 'objections', 'jurisdictions'];

/**
 * Daily Works: the register, its summary, the objections register and the jurisdictions. A thin Cyber shell: each tab owns
 * its data, its filters (query-string prefix per tab) and its states. The tab lives in `?tab=`.
 */
export default function DailyWorksUnified({ title, users = [], jurisdictions = [], can = {} }) {
    const { auth } = usePage().props;
    const f = useQueryFilters({ mode: 'client', defaults: { tab: 'works' }, debounceKeys: [] });
    const tab = TAB_KEYS.includes(f.values.tab) ? f.values.tab : 'works';
    const tabs = useMemo(() => [
        { key: 'works', label: 'Works' }, { key: 'summary', label: 'Summary' }, { key: 'objections', label: 'Objections' },
        ...(can.jurisdictions ? [{ key: 'jurisdictions', label: 'Jurisdictions' }] : []),
    ], [can.jurisdictions]);
    const openWork = useCallback((id) => f.commit({ tab: 'works', dw_open: String(id) }, { resetPage: false, replace: false }), [f]);
    const authId = auth?.user?.id ?? auth?.user?.employee_id ?? null;

    return (
        <>
            <Head title={title || 'Daily Works'} />
            <Tabs tabs={tabs} value={tab} onChange={(key) => f.set('tab', key)} idPrefix={TABS_ID} label="Daily Works sections" className="cy-tabs--scroll" />
            <div role="tabpanel" id={panelId(TABS_ID, tab)} aria-labelledby={tabId(TABS_ID, tab)}>
                <Suspense fallback={<p className="dw-state" role="status">Loading…</p>}>
                    {tab === 'works' && <DailyWorksRegister authId={authId} jurisdictions={jurisdictions} shellCan={can} />}
                    {tab === 'summary' && <DailyWorksSummary jurisdictions={jurisdictions} />}
                    {tab === 'objections' && <DailyWorksObjections onOpenWork={openWork} />}
                    {tab === 'jurisdictions' && can.jurisdictions && <JurisdictionsManager employees={users} />}
                </Suspense>
            </div>
        </>
    );
}

DailyWorksUnified.layout = (page) => <App>{page}</App>;
