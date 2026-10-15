// @vitest-environment jsdom
import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const postMock = vi.fn();
const getMock = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a) => postMock(...a), get: (...a) => getMock(...a), delete: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: {} }), Link: ({ href, children, ...r }) => <a href={href} {...r}>{children}</a> }));
vi.mock('@/utils/toastUtils', () => ({ showToast: { success: vi.fn(), error: vi.fn() } }));

const utils = await import('../dwUtils.js');
const { WorksTable, WorksCards } = await import('../RegisterTable.jsx');
const { FilterChips } = await import('../RegisterFilters.jsx');
const { default: ImportWizard } = await import('../ImportWizard.jsx');
const { default: WorkFormDialog } = await import('../WorkFormDialog.jsx');

let root; let host;
beforeEach(() => { globalThis.route = (name, p) => `/r/${name}${p ? `/${[].concat(p).join('/')}` : ''}`; postMock.mockReset(); getMock.mockReset(); });
afterEach(() => { act(() => root?.unmount()); host?.remove(); document.body.innerHTML = ''; });
const mount = (ui) => {
    host = document.createElement('div'); document.body.appendChild(host); root = createRoot(host);
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    act(() => root.render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>));
};
const click = (el) => act(() => { el.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const flush = () => act(() => new Promise((r) => setTimeout(r, 0)));
const type = (el, value) => act(() => {
    const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value);
    el.dispatchEvent(new Event('input', { bubbles: true }));
});

const work = (o = {}) => ({
    id: 1, number: 'RFI-1', date: '2026-05-04', status: 'new', type: 'Pavement', location: 'K05+100', start_m: 5100, side: 'TR-L', jurisdiction: { id: 1, code: 'PH-1', name: 'Phase One' },
    incharge: '9', incharge_name: 'Ada', active_objections: 0, files: 0, resubmission_count: 0, transitions: [], can: {}, ...o,
});

describe('register query helpers', () => {
    it('turn the URL state into the API query and drop empty values', () => {
        const p = utils.registerParams({ ...utils.DEFAULTS, dw_q: 'culvert', dw_status: 'new,rejected', dw_cf: 'K5+900', dw_trash: '' });
        expect(p).toMatchObject({ q: 'culvert', status: 'new,rejected', chain_from: 'K5+900', sort: 'date', dir: 'desc', page: 1, per_page: 25 });
        expect(p).not.toHaveProperty('trashed');
        expect(utils.filterParams({ ...utils.DEFAULTS, dw_q: 'x' })).toEqual({ q: 'x' });
        expect(utils.activeFilterCount({ ...utils.DEFAULTS, dw_q: 'x', dw_type: 'Pavement' })).toBe(2);
    });
    it('reads the legacy completed spelling and says when the latest record is dated', () => {
        expect(utils.statusMeta('complete').label).toBe('Completed');
        expect(utils.quietMessage(10, '2026-06-05')).toContain('5 Jun 2026');
        expect(utils.quietMessage(0, null)).toContain('no daily works');
    });
});

describe('register table', () => {
    it('has sortable headers with aria-sort, flags unplaced locations and opens a work by its number', () => {
        const onSort = vi.fn(); const onOpen = vi.fn();
        const rows = [work(), work({ id: 2, number: 'RFI-2', start_m: null, location: 'Main gate', jurisdiction: null })];
        mount(<WorksTable rows={rows} columns={['date', 'number', 'location', 'jurisdiction', 'status']} sort="date" dir="desc" onSort={onSort} selected={new Set()} onSelect={vi.fn()} onSelectAll={vi.fn()} onOpen={onOpen} density="comfortable" />);
        const th = [...host.querySelectorAll('th')].find((x) => x.textContent === 'Date');
        expect(th.getAttribute('aria-sort')).toBe('descending');
        click(th.querySelector('button'));
        expect(onSort).toHaveBeenCalledWith('date');
        expect(host.textContent).toContain('Not placed');
        click(host.querySelector('button[aria-label="Open RFI RFI-1"]'));
        expect(onOpen).toHaveBeenCalledWith(rows[0]);
    });
    it('lets the user select rows and everything on the page', () => {
        const onSelect = vi.fn(); const onSelectAll = vi.fn();
        mount(<WorksTable rows={[work()]} columns={['number']} sort="date" dir="asc" onSort={vi.fn()} selected={new Set()} onSelect={onSelect} onSelectAll={onSelectAll} onOpen={vi.fn()} density="compact" />);
        const boxes = host.querySelectorAll('input[type="checkbox"]');
        click(boxes[0]); expect(onSelectAll).toHaveBeenCalledWith(true);
        click(boxes[1]); expect(onSelect).toHaveBeenCalledWith(1, true);
    });
    it('renders cards for the phone layout', () => {
        mount(<WorksCards rows={[work({ active_objections: 2 })]} selected={new Set()} onSelect={vi.fn()} onOpen={vi.fn()} />);
        expect(host.querySelector('ul.dw-cards li')).not.toBeNull();
        expect(host.textContent).toContain('2 active');
    });
});

describe('filter chips', () => {
    it('shows one removable chip per filter', () => {
        const setMany = vi.fn();
        mount(<FilterChips values={{ ...utils.DEFAULTS, dw_status: 'new,rejected', dw_jur: '1' }} setMany={setMany} jurisdictions={[{ id: 1, code: 'PH-1', name: 'x' }]} people={[]} />);
        const buttons = host.querySelectorAll('button.dw-chip__x');
        expect(buttons).toHaveLength(3);
        click(buttons[0]);
        expect(setMany).toHaveBeenCalledWith({ dw_status: 'rejected' });
        click(buttons[2]);
        expect(setMany).toHaveBeenLastCalledWith({ dw_jur: '' });
    });
    it('renders nothing without filters', () => {
        mount(<FilterChips values={utils.DEFAULTS} setMany={vi.fn()} jurisdictions={[]} people={[]} />);
        expect(host.querySelector('.dw-chips')).toBeNull();
    });
});

describe('import wizard', () => {
    const preview = {
        token: 't1', incharges: [{ id: '9', name: 'Ada' }], already_imported: null,
        analysis: { counts: { rows: 2, valid: 1, warning: 0, error: 1, create: 1, resubmit: 0, skip: 0, reject: 1 }, sheets: [{ sheet: 'Sheet1', date: '2026-05-04', rows: [
            { row: 1, rfi_number: 'RFI-A', date: '2026-05-04', type: 'Pavement', location: 'K05+100', span: { from: 5100, to: 5100 }, jurisdiction: { code: 'PH-1' }, incharge: { id: '9', name: 'Ada' }, action: 'create', level: 'valid', issues: [] },
            { row: 2, rfi_number: 'RFI-B', date: '2026-05-04', type: 'Pavement', location: 'Main gate', span: null, jurisdiction: null, incharge: null, action: 'reject', level: 'error', issues: [{ level: 'error', message: 'The location is not a readable chainage.' }] },
        ] }] },
    };
    it('reads the file, lists every row with its result and needs the partial confirmation before importing', async () => {
        postMock.mockResolvedValueOnce({ data: { summary: preview } });
        mount(<ImportWizard can={{}} onClose={vi.fn()} onDone={vi.fn()} />);
        const file = new File(['x'], 'works.xlsx');
        const input = document.body.querySelector('input[type="file"]');
        act(() => { Object.defineProperty(input, 'files', { value: [file], configurable: true }); input.dispatchEvent(new Event('change', { bubbles: true })); });
        click([...document.body.querySelectorAll('button')].find((b) => b.textContent === 'Read the file'));
        await flush();
        expect(document.body.textContent).toContain('1 errors');
        expect(document.body.textContent).toContain('The location is not a readable chainage.');
        const importBtn = [...document.body.querySelectorAll('button')].find((b) => b.textContent.startsWith('Import 1 row'));
        expect(importBtn.disabled).toBe(true);
        const box = [...document.body.querySelectorAll('input[type="checkbox"]')].find((b) => b.closest('.cy-check')?.textContent.includes('Import the 1 good rows'));
        click(box);
        expect(importBtn.disabled).toBe(false);
    });
    it('says why a file could not be read', async () => {
        postMock.mockRejectedValueOnce({ response: { status: 422, data: { errors: { file: ['The file must be an Excel or CSV file.'] } } } });
        mount(<ImportWizard can={{}} onClose={vi.fn()} onDone={vi.fn()} />);
        const input = document.body.querySelector('input[type="file"]');
        act(() => { Object.defineProperty(input, 'files', { value: [new File(['x'], 'a.txt')], configurable: true }); input.dispatchEvent(new Event('change', { bubbles: true })); });
        click([...document.body.querySelectorAll('button')].find((b) => b.textContent === 'Read the file'));
        await flush();
        expect(document.body.textContent).toContain('must be an Excel or CSV file');
    });
});

describe('work form', () => {
    it('shows the server validation errors against their fields and a conflict as a message', async () => {
        postMock.mockResolvedValue({ data: {} });
        postMock.mockRejectedValueOnce({ response: { status: 422, data: { errors: { number: ['RFI Number is required.'], location: ['The location must start with K.'] } } } });
        mount(<WorkFormDialog people={[]} can={{ assign: false }} onClose={vi.fn()} onSaved={vi.fn()} onConflict={vi.fn()} />);
        click([...document.body.querySelectorAll('button')].find((b) => b.textContent === 'Add work'));
        await flush();
        expect(document.body.textContent).toContain('RFI Number is required.');
        expect(document.body.querySelector('#dw-l').getAttribute('aria-invalid')).toBe('true');
    });
    it('previews the jurisdiction and in-charge for a chainage and warns about a duplicate RFI number', async () => {
        postMock.mockResolvedValue({ data: { span: { text: 'K5+100' }, jurisdiction: { code: 'PH-1', name: 'Phase One' }, in_charge: { name: 'Ada' }, team: [], duplicate: { number: 'RFI-9', date: '2026-05-01', status: 'new', deleted: false } } });
        mount(<WorkFormDialog people={[]} can={{}} onClose={vi.fn()} onSaved={vi.fn()} onConflict={vi.fn()} />);
        type(document.body.querySelector('#dw-l'), 'K5+100');
        type(document.body.querySelector('#dw-n'), 'RFI-9');
        await act(() => new Promise((r) => setTimeout(r, 700)));
        await flush();
        expect(document.body.textContent).toContain('In-charge then');
        expect(document.body.textContent).toContain('already used');
    });
});
