import axios from 'axios';

import { formatChainage } from '@/Components/Cyber/Map/geo.js';

export const ROLE_LABEL = { in_charge: 'In-charge', deputy_in_charge: 'Deputy in-charge', member: 'Member' };
export const PERIODS = [
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
    { value: '365d', label: 'Last 12 months' },
    { value: 'all', label: 'All time' },
    { value: 'custom', label: 'Custom range' },
];

export const chainageRange = (j) => `${formatChainage(j.start_m)} to ${formatChainage(j.end_m)}`;
export const lengthKm = (m) => `${(m / 1000).toLocaleString('en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} km`;

/** Today's date in Asia/Dhaka as YYYY-MM-DD (the app's calendar day). */
export const todayDhaka = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

export const formatDay = (d) => {
    if (!d) return 'Since records began';
    const [y, m, day] = d.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, day)).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
};

/** The colour variable of a band token (jurisdiction.css). */
export const bandColour = (token) => `var(--jr-c-${token})`;

/** Overview query params from the URL state. */
export function overviewParams({ asOf, period, from, to }) {
    const p = { period: period || '30d' };
    if (asOf) p.as_of = asOf;
    if (period === 'custom') { if (from) p.from = from; if (to) p.to = to; }
    return p;
}

export const getJson = (url, params) => axios.get(url, { params }).then((r) => r.data);
export const postJson = (url, data) => axios.post(url, data).then((r) => r.data);

/** Laravel validation errors as { field: 'first message' }, plus a general message. */
export function errorsOf(error) {
    const res = error?.response;
    const fields = {};
    Object.entries(res?.data?.errors ?? {}).forEach(([k, v]) => { fields[k] = Array.isArray(v) ? v[0] : String(v); });
    return { fields, message: res?.status === 403 ? (res?.data?.message || 'You do not have permission for this.') : (res?.data?.message || error?.message || 'Something went wrong.'), status: res?.status };
}
