/**
 * Adapter between the Daily Timesheet's own data source (GET attendance/locations-today, `getUserLocationsForDate`)
 * and the shared Cyber corridor map, which draws "layers" of "features". The people layer is the exact client twin of
 * App\Services\Map\Layers\AttendancePunchesLayer (same ids, same `person` payload), so the popup, roster drawer and
 * officer modal read it unchanged. Geofences, patrol waypoints and punch-in to punch-out lines are rebuilt from the
 * attendance types the same response carries (what the earlier Leaflet card drew), with no third-party routing call.
 */

export const PEOPLE_LAYER = 'workforce.punches';
export const GEOFENCE_LAYER = 'workforce.geofences';
export const WAYPOINT_LAYER = 'workforce.waypoints';
export const TRAJECTORY_LAYER = 'workforce.trajectories';

export const MAP_GROUPS = { workforce: 'Workforce' };

const isNum = (v) => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));

/** A coordinate in any of the shapes the attendance settings store ({lat,lng}, {latitude,longitude}, [lat,lng]). */
export function coordOf(pt) {
    if (!pt) return null;
    if (Array.isArray(pt)) return pt.length >= 2 && isNum(pt[0]) && isNum(pt[1]) ? { lat: Number(pt[0]), lng: Number(pt[1]) } : null;
    if (typeof pt === 'object') {
        const lat = pt.lat ?? pt.latitude;
        const lng = pt.lng ?? pt.longitude;
        return isNum(lat) && isNum(lng) ? { lat: Number(lat), lng: Number(lng) } : null;
    }
    return null;
}

/** A punch location (object, or the JSON string some rows carry) with an optional address. */
export function placeOf(loc) {
    let value = loc;
    if (typeof value === 'string') {
        try { value = JSON.parse(value); } catch { return null; }
    }
    const c = coordOf(value);
    if (!c) return null;
    const address = value?.address;
    return { ...c, address: address !== undefined && address !== null && String(address) !== '' ? String(address) : null };
}

const stamp = (time, place) => (time ? `${String(time).slice(0, 5)}${place?.address ? ` at ${place.address}` : ''}` : null);

/**
 * One marker per employee at the last known position. Rows with no usable coordinates are counted, never guessed.
 * @returns {{ features: object[], unplaced: number }}
 */
export function buildPeople(rows, date, timesheetHref) {
    const features = [];
    let unplaced = 0;
    for (const row of Array.isArray(rows) ? rows : []) {
        const inn = placeOf(row.punchin_location);
        const out = placeOf(row.punchout_location);
        const here = out ?? inn;
        if (!here) { unplaced += 1; continue; }
        const done = (row.status ?? 'active') === 'completed';
        const id = String(row.employee_id ?? row.user_id);
        const fields = [
            ['Status', done ? 'Done' : 'Active'],
            ['Designation', row.designation],
            ['Punch in', stamp(row.punchin_time, inn)],
            ['Punch out', stamp(row.punchout_time, out)],
        ].filter(([, value]) => value !== null && value !== undefined && value !== '').map(([label, value]) => ({ label, value: String(value) }));
        features.push({
            id: `${PEOPLE_LAYER}:${id}`,
            shape: 'point',
            title: String(row.name ?? 'Unknown'),
            tone: done ? 'info' : 'good',
            fields,
            lat: here.lat,
            lng: here.lng,
            person: {
                employee_id: id,
                name: String(row.name ?? 'Unknown'),
                designation: row.designation ?? null,
                department: row.department ?? null,
                photo: row.profile_image_url ?? null,
                status: done ? 'completed' : 'active',
                attendance_type: row.attendance_type?.name ?? null,
                requires_photo: Boolean(row.requires_photo),
                date,
                punch_in: inn === null ? null : { ...inn, time: row.punchin_time ?? null, photo: row.punchin_photo_url ?? null },
                punch_out: out === null && !row.punchout_time ? null : { ...(out ?? {}), time: row.punchout_time ?? null, photo: row.punchout_photo_url ?? null },
                cycles: Array.isArray(row.cycles) ? row.cycles.length : 0,
                timesheet: timesheetHref ?? null,
            },
        });
    }
    return { features, unplaced };
}

/** The straight line between the punch-in and punch-out of each finished employee (what the earlier card drew). */
export function buildTrajectories(rows) {
    const features = [];
    for (const row of Array.isArray(rows) ? rows : []) {
        const inn = placeOf(row.punchin_location);
        const out = placeOf(row.punchout_location);
        if (!inn || !out || !row.punchout_time) continue;
        if (inn.lat === out.lat && inn.lng === out.lng) continue;
        const id = String(row.employee_id ?? row.user_id);
        features.push({
            id: `${TRAJECTORY_LAYER}:${id}`,
            shape: 'route',
            title: `${row.name ?? 'Unknown'}: punch in to punch out`,
            tone: 'info',
            fields: [{ label: 'Employee', value: String(row.name ?? 'Unknown') }, { label: 'Punch in', value: stamp(row.punchin_time, inn) ?? '' }, { label: 'Punch out', value: stamp(row.punchout_time, out) ?? '' }].filter((f) => f.value),
            path: [[inn.lat, inn.lng], [out.lat, out.lng]],
            lat: (inn.lat + out.lat) / 2,
            lng: (inn.lng + out.lng) / 2,
        });
    }
    return features;
}

const polygonTypeOf = (t) => t.base_slug === 'geo_polygon' || /polygon|geofence/.test(t.slug ?? '') || Boolean(t.config?.polygon?.length || t.config?.polygons?.length);
const routeTypeOf = (t) => t.base_slug === 'route_waypoint' || /route|waypoint|patrol/.test(t.slug ?? '') || Boolean(t.config?.waypoints?.length || t.config?.routes?.length);

const centroid = (path) => ({ lat: path.reduce((a, p) => a + p[0], 0) / path.length, lng: path.reduce((a, p) => a + p[1], 0) / path.length });

/** Geofence polygons (and their centres) of every attendance type that defines one; an inactive zone is not drawn. */
export function buildGeofences(configs) {
    const features = [];
    for (const type of Array.isArray(configs) ? configs : []) {
        const config = type.config;
        if (!config || !polygonTypeOf(type)) continue;
        const zones = [];
        if (Array.isArray(config.polygon) && config.polygon.length >= 3) zones.push({ name: type.name, points: config.polygon });
        (Array.isArray(config.polygons) ? config.polygons : []).forEach((z, i) => {
            const points = z?.points ?? z?.coordinates ?? z;
            if (Array.isArray(points) && points.length >= 3 && z?.is_active !== false) zones.push({ name: z?.name || `${type.name} zone ${i + 1}`, points });
        });
        zones.forEach((zone, i) => {
            const path = zone.points.map(coordOf).filter(Boolean).map((c) => [c.lat, c.lng]);
            if (path.length < 3) return;
            const mid = centroid(path);
            features.push({
                id: `${GEOFENCE_LAYER}:${type.id}-${i}`,
                shape: 'polygon',
                title: String(zone.name),
                tone: 'info',
                fields: [{ label: 'Attendance type', value: String(type.name) }, { label: 'Vertices', value: String(path.length) }],
                path,
                lat: mid.lat,
                lng: mid.lng,
            });
        });
    }
    return features;
}

/** Patrol routes: a line through the waypoints, each waypoint a marker, with its attendance tolerance as a ring. */
export function buildWaypoints(configs) {
    const features = [];
    for (const type of Array.isArray(configs) ? configs : []) {
        const config = type.config;
        if (!config || !routeTypeOf(type)) continue;
        const tolerance = Number(config.tolerance) > 0 ? Number(config.tolerance) : 150;
        const routes = [];
        if (Array.isArray(config.waypoints) && config.waypoints.length) routes.push({ name: type.name, waypoints: config.waypoints, tolerance });
        (Array.isArray(config.routes) ? config.routes : []).forEach((r, i) => {
            const waypoints = r?.waypoints ?? r?.points ?? r?.coords;
            if (Array.isArray(waypoints) && waypoints.length) routes.push({ name: r.name || `${type.name} route ${i + 1}`, waypoints, tolerance: Number(r.tolerance) > 0 ? Number(r.tolerance) : tolerance });
        });
        routes.forEach((route, ri) => {
            const points = route.waypoints.map(coordOf).filter(Boolean);
            if (!points.length) return;
            const base = `${WAYPOINT_LAYER}:${type.id}-${ri}`;
            if (points.length >= 2) {
                const mid = centroid(points.map((p) => [p.lat, p.lng]));
                features.push({
                    id: `${base}-line`, shape: 'route', title: String(route.name), tone: 'info',
                    fields: [{ label: 'Attendance type', value: String(type.name) }, { label: 'Waypoints', value: String(points.length) }],
                    path: points.map((p) => [p.lat, p.lng]), lat: mid.lat, lng: mid.lng,
                });
            }
            points.forEach((p, i) => {
                const role = points.length === 1 ? 'Checkpoint' : i === 0 ? 'Route start' : i === points.length - 1 ? 'Route end' : `Waypoint ${i + 1}`;
                features.push({
                    id: `${base}-${i}`, shape: 'circle', title: `${route.name}: ${role}`, tone: 'info',
                    fields: [{ label: 'Attendance type', value: String(type.name) }, { label: 'Waypoint', value: role }, { label: 'Tolerance', value: `${route.tolerance} m` }],
                    lat: p.lat, lng: p.lng, radius_m: route.tolerance,
                });
            });
        });
    }
    return features;
}

const layer = (key, label, icon, geometry, features, extra = {}) => ({
    key, label, group: 'workforce', geometry, tone: 'info', icon, time_mode: 'period', scope: 'department',
    fields: {}, source: 'Attendance locations of the selected day (Daily Timesheet source)', count: features.length, unplaced: 0, truncated: false,
    href: null, features, ...extra,
});

/** Layers for the shared map, bottom to top (zones, waypoints, lines, then people). Empty layers are not offered. */
export function buildLayers({ rows, configs, date, timesheetHref }) {
    const people = buildPeople(rows, date, timesheetHref);
    const out = [];
    const zones = buildGeofences(configs);
    if (zones.length) out.push(layer(GEOFENCE_LAYER, 'Attendance geofences', 'bounding-box', ['polygon'], zones, { source: 'Geofence zones of the attendance types' }));
    const waypoints = buildWaypoints(configs);
    if (waypoints.length) out.push(layer(WAYPOINT_LAYER, 'Patrol waypoints', 'signpost-split', ['point', 'circle', 'route'], waypoints, { source: 'Route waypoints and tolerance of the attendance types' }));
    const lines = buildTrajectories(rows);
    if (lines.length) out.push(layer(TRAJECTORY_LAYER, 'Punch-in to punch-out', 'arrow-left-right', ['route'], lines, { source: 'Straight line between the two punches' }));
    if (people.features.length) {
        out.push(layer(PEOPLE_LAYER, 'Attendance locations', 'person-badge', ['point'], people.features, { tone: 'good', unplaced: people.unplaced, href: timesheetHref ?? null }));
    }
    return { layers: out, unplaced: people.unplaced, people: people.features };
}

/** Narrow the markers by the toolbar's search text and status; the same fields the earlier card searched. */
export function filterRows(rows, { query = '', status = 'all' } = {}) {
    const needle = query.trim().toLowerCase();
    return (Array.isArray(rows) ? rows : []).filter((u) => {
        if (status === 'active' && u.status !== 'active') return false;
        if (status === 'completed' && u.status === 'active') return false;
        if (!needle) return true;
        return [u.name, u.employee_id, u.designation, u.department].some((v) => String(v ?? '').toLowerCase().includes(needle));
    });
}
