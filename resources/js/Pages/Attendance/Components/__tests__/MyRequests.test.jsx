// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';

const responses = {};
vi.mock('@/api/client', () => ({ requestJson: vi.fn((method, url) => Promise.resolve(responses[url] ?? {})) }));
vi.mock('@/utils/toastUtils', () => ({ showToast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: {} }), Link: ({ children, ...p }) => <a {...p}>{children}</a> }));

const { default: MyRequests } = await import('../MyRequests.jsx');
const { default: SwapResponses } = await import('../SwapResponses.jsx');

let root; let host;
afterEach(() => { act(() => root?.unmount()); host?.remove(); Object.keys(responses).forEach((k) => delete responses[k]); });

async function mount(ui) {
    host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host);
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () => { root.render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>); });
    await act(async () => { await new Promise((r) => setTimeout(r, 0)); });
}

describe('MyRequests (Cyber)', () => {
    it('shows the replacement-leave balance in days, counts per tab and the rows of the selected tab', async () => {
        responses['/attendance/regularizations/mine'] = { requests: [{ id: 1, date: '2026-10-01', type: 'missing_punch', status: 'pending' }] };
        responses['/attendance/overtime/mine'] = { requests: [] };
        responses['/attendance/comp-off/mine'] = { balance_days: 1.5, expiring_days: 0.5 };
        responses['/attendance/swaps/mine'] = { swaps: [] };
        await mount(<MyRequests />);

        expect(host.textContent).toContain('1.5 days');
        expect(host.textContent).toContain('0.5 days');
        const tabs = [...host.querySelectorAll('[role=tab]')].map((t) => t.textContent);
        expect(tabs[0]).toContain('Regularizations');
        expect(host.textContent).toContain('01 Oct 2026');
        expect(host.textContent).toContain('missing punch');
        expect(host.querySelector('.cy-badge--soft.cy-badge--warning')?.textContent).toBe('pending');

        await act(async () => { host.querySelectorAll('[role=tab]')[1].click(); });
        expect(host.textContent).toContain('No overtime requests yet.');
    });
});

describe('MyRequests: overtime claims and re-upload', () => {
    const base = () => {
        responses['/attendance/regularizations/mine'] = { requests: [] };
        responses['/attendance/comp-off/mine'] = { balance_days: 0, expiring_days: 0 };
        responses['/attendance/swaps/mine'] = { swaps: [] };
        responses['/attendance/replacement/mine'] = [];
    };

    it('shows a returned claim with the approver reason and a Re-upload button, and flags minute-based history as legacy', async () => {
        base();
        responses['/attendance/overtime/mine'] = {
            requests: [
                { id: 5, date: '2026-10-07', status: 'pending', claim_type: 'rest_day', compensation: 'replacement', day_credit: 1, legacy: false,
                  attachments: { files: [{ id: 1, name: 'a.jpg' }], returned_for_reupload: true, reupload: { reason: 'The photo is cut off at the bottom.' } } },
                { id: 6, date: '2026-06-18', status: 'approved', requested_minutes: 120, legacy: true, attachments: { files: [], returned_for_reupload: false } },
            ],
        };
        await mount(<MyRequests />);
        await act(async () => { host.querySelectorAll('[role=tab]')[1].click(); });

        expect(host.textContent).toContain('Rest day · 1 day as replacement leave');
        expect(host.textContent).toContain('Returned for a clearer document');
        expect(host.textContent).toContain('The photo is cut off at the bottom.');
        expect([...host.querySelectorAll('button')].map((b) => b.textContent.trim())).toContain('Re-upload');
        expect(host.textContent).toContain('legacy (hours)');
        expect(host.textContent).toContain('120 min requested');
    });
});

describe('SwapResponses (Cyber)', () => {
    it('renders nothing when no swap waits for the employee', async () => {
        responses['/attendance/swaps/awaiting-me'] = { swaps: [] };
        await mount(<SwapResponses />);
        expect(host.querySelector('.dl-card')).toBeNull();
    });

    it('lists waiting swaps with accept and decline actions', async () => {
        responses['/attendance/swaps/awaiting-me'] = { swaps: [{ id: 7, requester: { name: 'Rahim' }, requester_date: '2026-10-12', counterparty_date: '2026-10-14', reason: 'Family' }] };
        await mount(<SwapResponses />);
        expect(host.textContent).toContain('Rahim');
        expect([...host.querySelectorAll('button')].map((b) => b.textContent.trim())).toEqual(expect.arrayContaining(['Accept', 'Decline']));
    });
});
