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
    it('shows the comp-off balance, counts per tab and the rows of the selected tab', async () => {
        responses['/attendance/regularizations/mine'] = { requests: [{ id: 1, date: '2026-10-01', type: 'missing_punch', status: 'pending' }] };
        responses['/attendance/overtime/mine'] = { requests: [] };
        responses['/attendance/comp-off/mine'] = { balance_minutes: 135 };
        responses['/attendance/swaps/mine'] = { swaps: [] };
        await mount(<MyRequests />);

        expect(host.textContent).toContain('2h 15m');
        const tabs = [...host.querySelectorAll('[role=tab]')].map((t) => t.textContent);
        expect(tabs[0]).toContain('Regularizations');
        expect(host.textContent).toContain('01 Oct 2026');
        expect(host.textContent).toContain('missing punch');
        expect(host.querySelector('.cy-badge--soft.cy-badge--warning')?.textContent).toBe('pending');

        await act(async () => { host.querySelectorAll('[role=tab]')[1].click(); });
        expect(host.textContent).toContain('No overtime requests yet.');
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
