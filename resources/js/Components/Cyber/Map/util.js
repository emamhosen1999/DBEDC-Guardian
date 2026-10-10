/** Small formatting helpers shared by the Cyber map components. */

/** "07:23:30" or an ISO stamp to "07:23"; null when there is nothing to show. */
export function clockOf(value) {
    if (!value) return null;
    const s = String(value);
    const m = /(\d{2}):(\d{2})/.exec(s);
    return m ? `${m[1]}:${m[2]}` : null;
}

export const initialsOf = (name) => String(name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || '?';

export const placeOf = (punch) => punch?.address || (Number.isFinite(punch?.lat) ? `${punch.lat.toFixed(5)}, ${punch.lng.toFixed(5)}` : null);

/** Tone key -> Cyber badge colour. */
export const BADGE = { good: 'success', warn: 'warning', crit: 'danger', info: 'info', theme: 'theme', neutral: 'secondary' };

export const isoDay = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
