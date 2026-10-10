import { describe, expect, it } from 'vitest';
import { PEOPLE_LAYER, buildGeofences, buildLayers, buildPeople, buildTrajectories, buildWaypoints, filterRows, placeOf } from '../timesheetMap';

const row = (over = {}) => ({
    user_id: '1', employee_id: 'E1', name: 'Rahim', designation: 'Patrol Officer', department: 'O&M', profile_image_url: '/p.jpg',
    status: 'active', cycles: [{}], attendance_type: { name: 'Route' }, requires_photo: true,
    punchin_location: { lat: 23.9, lng: 90.4, address: 'K10' }, punchout_location: null,
    punchin_time: '08:15:00', punchout_time: null, punchin_photo_url: '/in.jpg', punchout_photo_url: null, ...over,
});

describe('placeOf', () => {
    it('reads objects and JSON strings, rejects blanks', () => {
        expect(placeOf({ lat: '23.5', lng: 90, address: '' })).toEqual({ lat: 23.5, lng: 90, address: null });
        expect(placeOf('{"lat":1,"lng":2,"address":"A"}')).toEqual({ lat: 1, lng: 2, address: 'A' });
        expect(placeOf({ lat: '', lng: 2 })).toBeNull();
        expect(placeOf('not json')).toBeNull();
        expect(placeOf(null)).toBeNull();
    });
});

describe('buildPeople (twin of AttendancePunchesLayer)', () => {
    it('keeps every popup field and the punch photos', () => {
        const { features, unplaced } = buildPeople([row()], '2026-10-10', '/attendance?date=2026-10-10');
        expect(unplaced).toBe(0);
        const f = features[0];
        expect(f.id).toBe(`${PEOPLE_LAYER}:E1`);
        expect(f.tone).toBe('good');
        expect(f.person).toMatchObject({ name: 'Rahim', designation: 'Patrol Officer', photo: '/p.jpg', status: 'active', timesheet: '/attendance?date=2026-10-10', cycles: 1, requires_photo: true });
        expect(f.person.punch_in).toMatchObject({ time: '08:15:00', photo: '/in.jpg', address: 'K10' });
        expect(f.person.punch_out).toBeNull();
        expect(f.fields.find((x) => x.label === 'Punch in').value).toBe('08:15 at K10');
    });

    it('places a finished employee at the punch-out and counts unplaceable rows without guessing', () => {
        const done = row({ status: 'completed', punchout_location: { lat: 24, lng: 90.5 }, punchout_time: '17:00:00' });
        const none = row({ employee_id: 'E2', punchin_location: null });
        const { features, unplaced } = buildPeople([done, none], 'd', null);
        expect(features).toHaveLength(1);
        expect(features[0]).toMatchObject({ lat: 24, lng: 90.5, tone: 'info' });
        expect(features[0].person.punch_out.time).toBe('17:00:00');
        expect(unplaced).toBe(1);
    });
});

describe('overlays from the attendance types', () => {
    it('draws geofence polygons and skips inactive zones and tiny shapes', () => {
        const pts = [{ lat: 1, lng: 1 }, { lat: 1, lng: 2 }, { lat: 2, lng: 2 }];
        const f = buildGeofences([
            { id: 1, name: 'Gate', slug: 'geo_polygon_1', base_slug: 'geo_polygon', config: { polygon: pts, polygons: [{ name: 'Off', points: pts, is_active: false }, { name: 'Two', points: pts }] } },
            { id: 2, name: 'Tiny', slug: 'geo_polygon_2', base_slug: 'geo_polygon', config: { polygon: pts.slice(0, 2) } },
        ]);
        expect(f.map((x) => x.title)).toEqual(['Gate', 'Two']);
        expect(f[0].path).toHaveLength(3);
    });

    it('turns waypoints into a line, markers with tolerance rings', () => {
        const f = buildWaypoints([{ id: 3, name: 'Patrol', slug: 'route_waypoint_1', base_slug: 'route_waypoint', config: { tolerance: 80, waypoints: [[1, 1], [1, 2], [2, 2]] } }]);
        expect(f.filter((x) => x.shape === 'route')).toHaveLength(1);
        const rings = f.filter((x) => x.shape === 'circle');
        expect(rings).toHaveLength(3);
        expect(rings[0]).toMatchObject({ radius_m: 80 });
        expect(rings[0].fields.find((x) => x.label === 'Waypoint').value).toBe('Route start');
    });

    it('draws a punch-in to punch-out line only for finished employees who moved', () => {
        const moved = row({ punchout_location: { lat: 24, lng: 90.5 }, punchout_time: '17:00:00' });
        const same = row({ employee_id: 'E3', punchout_location: { lat: 23.9, lng: 90.4 }, punchout_time: '17:00:00' });
        expect(buildTrajectories([moved, same, row({ employee_id: 'E4' })])).toHaveLength(1);
    });
});

describe('buildLayers', () => {
    it('orders layers bottom to top with people last and offers no empty layer', () => {
        const out = buildLayers({ rows: [row()], configs: [], date: 'd', timesheetHref: '/x' });
        expect(out.layers.map((l) => l.key)).toEqual([PEOPLE_LAYER]);
        expect(out.layers[0].count).toBe(1);
        expect(buildLayers({ rows: [], configs: [], date: 'd' }).layers).toEqual([]);
    });
});

describe('filterRows', () => {
    const rows = [row(), row({ employee_id: 'E9', name: 'Karim', status: 'completed', designation: 'Clerk', department: 'HR' })];
    it('filters by status and by name, id, designation or department', () => {
        expect(filterRows(rows, { status: 'active' })).toHaveLength(1);
        expect(filterRows(rows, { status: 'completed' })[0].name).toBe('Karim');
        expect(filterRows(rows, { query: 'clerk' })[0].name).toBe('Karim');
        expect(filterRows(rows, { query: 'e1' })[0].name).toBe('Rahim');
        expect(filterRows(rows, { query: 'hr' })).toHaveLength(1);
        expect(filterRows(rows, { query: 'zzz' })).toHaveLength(0);
    });
});
