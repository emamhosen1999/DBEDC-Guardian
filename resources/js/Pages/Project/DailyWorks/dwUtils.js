import axios from 'axios';

import { errorsOf, formatDay, getJson, postJson, todayDhaka } from '../Jurisdictions/jurisdictionUtils.js';

export { errorsOf, formatDay, getJson, postJson, todayDhaka };

/** Status vocabulary: label and Cyber badge tone. `complete` is the legacy spelling the server already normalises to `completed`. */
export const STATUSES = [
    { value: 'new', label: 'New', tone: 'info' },
    { value: 'in-progress', label: 'In progress', tone: 'warning' },
    { value: 'pending', label: 'Pending', tone: 'secondary' },
    { value: 'emergency', label: 'Emergency', tone: 'danger' },
    { value: 'resubmission', label: 'Resubmission', tone: 'warning' },
    { value: 'rejected', label: 'Rejected', tone: 'danger' },
    { value: 'completed', label: 'Completed', tone: 'success' },
];
export const statusMeta = (s) => STATUSES.find((x) => x.value === (s === 'complete' ? 'completed' : s)) ?? { value: s, label: s ?? 'Unknown', tone: 'secondary' };

export const RESULTS = { pass: { label: 'Pass', tone: 'success' }, fail: { label: 'Fail', tone: 'danger' } };
export const TYPES = ['Embankment', 'Structure', 'Pavement'];
export const SIDES = ['SR-R', 'SR-L', 'TR-R', 'TR-L'];
export const SIDE_LABEL = { 'SR-R': 'Service road, right', 'SR-L': 'Service road, left', 'TR-R': 'Trunk road, right', 'TR-L': 'Trunk road, left' };

/** Every register column: key, heading, whether it is shown by default, whether the server can sort by it. */
export const COLUMNS = [
    { key: 'date', label: 'Date', sort: 'date', on: true },
    { key: 'number', label: 'RFI number', sort: 'number', on: true },
    { key: 'type', label: 'Type', sort: 'type', on: true },
    { key: 'location', label: 'Location', sort: 'location', on: true },
    { key: 'side', label: 'Side', on: false },
    { key: 'jurisdiction', label: 'Jurisdiction', on: true },
    { key: 'status', label: 'Status', sort: 'status', on: true },
    { key: 'incharge', label: 'In-charge', sort: 'incharge', on: true },
    { key: 'assigned', label: 'Assigned to', sort: 'assigned', on: false },
    { key: 'description', label: 'Description', on: false },
    { key: 'completion', label: 'Completed', sort: 'completion', on: false },
    { key: 'resubmissions', label: 'Resubmissions', sort: 'resubmissions', on: false },
    { key: 'objections', label: 'Objections', on: true },
    { key: 'files', label: 'Files', on: false },
    { key: 'updated', label: 'Updated', sort: 'updated', on: false },
];

const COLUMN_STORE = 'dw.register.columns.v1';
const DENSITY_STORE = 'dw.register.density.v1';

export function loadColumns() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(COLUMN_STORE) || 'null');
        if (Array.isArray(saved)) return COLUMNS.filter((c) => saved.includes(c.key)).map((c) => c.key);
    } catch { /* storage may be blocked */ }
    return COLUMNS.filter((c) => c.on).map((c) => c.key);
}
export function saveColumns(keys) {
    try { window.localStorage.setItem(COLUMN_STORE, JSON.stringify(keys)); } catch { /* ignore */ }
}
export function loadDensity() {
    try { return window.localStorage.getItem(DENSITY_STORE) === 'compact' ? 'compact' : 'comfortable'; } catch { return 'comfortable'; }
}
export function saveDensity(value) {
    try { window.localStorage.setItem(DENSITY_STORE, value); } catch { /* ignore */ }
}

/** The query-string filter keys (prefix `dw_`) and their defaults. Lists are comma separated. */
export const DEFAULTS = {
    dw_q: '', dw_status: '', dw_type: '', dw_side: '', dw_inc: '', dw_asg: '', dw_jur: '', dw_from: '', dw_to: '', dw_cf: '', dw_ct: '',
    dw_obj: '', dw_quality: '', dw_trash: '', dw_sort: 'date', dw_dir: 'desc', dw_page: 1, dw_pp: 25, dw_open: '',
};

/** The register API's query string from the page's URL state. */
export function registerParams(v) {
    const p = {
        q: v.dw_q, status: v.dw_status, type: v.dw_type, side: v.dw_side, incharge: v.dw_inc, assigned: v.dw_asg, jurisdiction: v.dw_jur,
        date_from: v.dw_from, date_to: v.dw_to, chain_from: v.dw_cf, chain_to: v.dw_ct, objections: v.dw_obj, quality: v.dw_quality,
        trashed: v.dw_trash ? 1 : '', sort: v.dw_sort, dir: v.dw_dir, page: v.dw_page, per_page: v.dw_pp,
    };
    return Object.fromEntries(Object.entries(p).filter(([, val]) => val !== '' && val != null));
}

/** The filters without sort and page (what an export or the summary should use). */
export function filterParams(v) {
    const { sort, dir, page, per_page, ...rest } = registerParams(v);
    return rest;
}

export const activeFilterCount = (v) => ['dw_q', 'dw_status', 'dw_type', 'dw_side', 'dw_inc', 'dw_asg', 'dw_jur', 'dw_from', 'dw_to', 'dw_cf', 'dw_ct', 'dw_obj', 'dw_quality', 'dw_trash'].filter((k) => v[k] !== '' && v[k] != null).length;

export const csv = (s) => (s ? String(s).split(',').filter(Boolean) : []);

/** "2026-05-04T08:30:00+06:00" or "2026-05-04 08:30:00" as "4 May 2026, 08:30". */
export function formatMoment(value) {
    if (!value) return '';
    const d = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Dhaka' });
}

/** Wording of a quiet period, shared by every tab: say when the latest record is dated and offer a wider period. */
export function quietMessage(scopedTotal, latest) {
    if (!scopedTotal) return 'There are no daily works in your scope yet.';
    return latest ? `Nothing matches. The latest daily work in your scope is dated ${formatDay(latest)}.` : 'Nothing matches these filters.';
}

export function fileName(response, fallback) {
    const cd = response.headers?.['content-disposition'] ?? '';
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
    return m ? decodeURIComponent(m[1]) : fallback;
}

/** Download a file the server streams (export, error report). */
export async function download(url, params, fallbackName) {
    const response = await axios.get(url, { params, responseType: 'blob' });
    const href = URL.createObjectURL(response.data);
    const a = document.createElement('a');
    a.href = href;
    a.download = fileName(response, fallbackName);
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(href);
    return response;
}
