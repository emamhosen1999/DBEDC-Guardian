import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';

import { Card, Icon } from '@/Components/Cyber';
import { Field, IconButton, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import {
    ChainageRuler, CorridorSvgMap, LayerMenu, MapPopup, MapRosterDrawer, MapStatsRibbon, OfficerDetailModal, PhotoLightbox,
} from '@/Components/Cyber/Map';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { MAP_GROUPS, PEOPLE_LAYER, buildLayers, filterRows, placeOf } from '../timesheetMap';

const POLL_MS = 15000;
const STATUS = [['all', 'All'], ['active', 'Active'], ['completed', 'Done']];

const storageKey = (viewer) => `cy-map:attendance:hidden:${viewer ?? 'anon'}`;
const readHidden = (key) => { try { return new Set(JSON.parse(window.localStorage.getItem(key) || '[]')); } catch { return new Set(); } };
const writeHidden = (key, set) => { try { window.localStorage.setItem(key, JSON.stringify([...set])); } catch { /* storage unavailable: stays in memory */ } };

/** Boundary and road context are static, licensed files: loaded on demand so they never weigh on the first paint. */
function useStaticContext() {
    const [ctx, setCtx] = useState({ districts: null, context: null });
    useEffect(() => {
        let alive = true;
        Promise.all([import('@/Components/Cyber/Map/data/districts.json'), import('@/Components/Cyber/Map/data/map-context.json')])
            .then(([d, c]) => { if (alive) setCtx({ districts: d.default, context: c.default }); })
            .catch(() => { /* the map still draws the centreline and layers without the backdrop */ });
        return () => { alive = false; };
    }, []);
    return ctx;
}

const timeText = (d) => (d ? d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : null);

/**
 * Daily Timesheet map: where the team punched on the selected day, on the same Cyber corridor map as the Dashboard
 * (avatar markers with status rings, clustering, legend, popup, roster drawer, officer detail, photo lightbox).
 * Data source unchanged: `getUserLocationsForDate`, refreshed every 15 s while the tab is open and visible.
 */
export default function TimesheetMap({ selectedDate, isActive = true }) {
    const viewer = usePage().props?.auth?.user?.employee_id;
    const ctx = useStaticContext();
    const date = String(selectedDate ?? '').split('T')[0];

    const [data, setData] = useState({ rows: [], configs: [], alignment: null });
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [updatedAt, setUpdatedAt] = useState(null);
    const [live, setLive] = useState(true);

    /* The status filter lives in the URL under a `loc_` prefix (every Attendance tab stays mounted). */
    const f = useQueryFilters({ mode: 'client', debounceKeys: [], defaults: { loc_status: 'all' } });
    const status = f.values.loc_status;
    const setStatus = (v) => f.set('loc_status', v);
    const [query, setQuery] = useState('');

    const key = storageKey(viewer);
    const [hidden, setHidden] = useState(() => readHidden(key));
    const [selectedId, setSelectedId] = useState(null);
    const [focus, setFocus] = useState(null);
    const [roster, setRoster] = useState(() => typeof window !== 'undefined' && window.innerWidth >= 1200);
    const [officer, setOfficer] = useState(null);
    const [photo, setPhoto] = useState(null);

    /* ── data ─────────────────────────────────────────────── */
    const alignmentRef = useRef(null);
    const sequence = useRef(0);
    const loaded = useRef({ date: null });

    const load = useCallback(async (silent) => {
        if (!date) return;
        const mine = ++sequence.current;
        if (silent) setRefreshing(true); else { setLoading(true); setFailed(false); setData((d) => ({ ...d, rows: [] })); }
        try {
            const params = { date, _t: Date.now() };
            if (!alignmentRef.current) params.with_alignment = 1;
            const response = await fetch(route('getUserLocationsForDate', params), { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}: Failed to fetch user locations`);
            const body = await response.json();
            if (mine !== sequence.current) return; // a newer request owns the screen
            if (body.alignment) alignmentRef.current = body.alignment;
            setData({
                rows: Array.isArray(body.locations) ? body.locations : [],
                configs: Array.isArray(body.attendance_type_configs) ? body.attendance_type_configs : [],
                alignment: alignmentRef.current,
            });
            setUpdatedAt(new Date());
            setFailed(false);
            loaded.current = { date };
        } catch (error) {
            if (mine !== sequence.current) return;
            console.error('Failed to load team locations:', error);
            if (!silent) setFailed(true);
        } finally {
            if (mine === sequence.current) { setLoading(false); setRefreshing(false); }
        }
    }, [date]);

    // Load when the tab is open and the day changes; coming back to the tab refreshes quietly.
    useEffect(() => {
        if (!isActive) return;
        load(loaded.current.date === date);
    }, [isActive, date, load]);

    useEffect(() => {
        if (!isActive || !live) return undefined;
        const id = setInterval(() => { if (document.visibilityState === 'visible') load(true); }, POLL_MS);
        return () => clearInterval(id);
    }, [isActive, live, load]);

    /* ── derived ──────────────────────────────────────────── */
    const timesheetHref = useMemo(() => route('attendance.unified', { date }), [date]);
    const shownRows = useMemo(() => filterRows(data.rows, { query, status }), [data.rows, query, status]);
    const built = useMemo(() => buildLayers({ rows: shownRows, configs: data.configs, date, timesheetHref }), [shownRows, data.configs, date, timesheetHref]);
    const layers = built.layers;
    const visible = useMemo(() => layers.filter((l) => !hidden.has(l.key)), [layers, hidden]);
    const people = useMemo(() => (visible.find((l) => l.key === PEOPLE_LAYER)?.features ?? []).filter((x) => x.person), [visible]);
    const allActive = data.rows.filter((r) => r.status === 'active').length;
    const placedAll = useMemo(() => data.rows.filter((r) => placeOf(r.punchin_location) || placeOf(r.punchout_location)).length, [data.rows]);
    const unplacedAll = data.rows.length - placedAll;

    const setAndStore = useCallback((next) => { setHidden(next); writeHidden(key, next); }, [key]);
    const toggle = useCallback((k) => { const n = new Set(hidden); if (n.has(k)) n.delete(k); else n.add(k); setAndStore(n); }, [hidden, setAndStore]);
    const setAll = useCallback((on) => setAndStore(on ? new Set() : new Set(layers.map((l) => l.key))), [layers, setAndStore]);
    const select = useCallback((feature) => setSelectedId(feature ? feature.id : null), []);

    const ribbon = [
        { key: 'on', label: 'Staff on map', value: placedAll, tone: 'theme' },
        { key: 'active', label: 'On duty', value: allActive, tone: 'good' },
        { key: 'done', label: 'Checked out', value: data.rows.length - allActive, tone: 'info' },
        unplacedAll ? { key: 'unplaced', label: 'Not placed', value: unplacedAll, tone: 'warn', title: 'Punches without a readable position are counted, never guessed.' } : null,
    ];

    const popup = ({ feature, layer, close }) => (
        <MapPopup feature={feature} layer={layer} onClose={close} onPhoto={setPhoto} onDetails={setOfficer} drill={layer.href} />
    );

    const footer = (
        <>
            <span className="cy-fresh">{[date, updatedAt ? `updated ${timeText(updatedAt)}` : null, live ? `live, every ${POLL_MS / 1000} s` : 'live updates paused', `${shownRows.length} of ${data.rows.length} people shown`].filter(Boolean).join(' · ')}</span>
            <span className="cy-fresh">Source: attendance punch locations (same data as the timesheet); corridor centreline and boundaries as on the Dashboard map</span>
        </>
    );

    return (
        <Card
            id="attendance-map"
            title="Team locations"
            className="cy-mapcard cy-mapcard--attendance"
            flush
            actions={layers.length ? <LayerMenu layers={layers} groups={MAP_GROUPS} hidden={hidden} onToggle={toggle} onSetAll={setAll} /> : null}
            footer={footer}
        >
            {() => (
                <>
                    <Toolbar label="Map filters">
                        <ToolbarGroup>
                            <Field icon="search" type="search" label="Search people" value={query} onChange={(e) => setQuery(e.target.value)} />
                            <div className="cy-seg" role="group" aria-label="Status filter">
                                {STATUS.map(([value, label]) => (
                                    <button key={value} type="button" className="cy-map__btn" aria-pressed={status === value} onClick={() => setStatus(value)}>{label}</button>
                                ))}
                            </div>
                        </ToolbarGroup>
                        <ToolbarGroup end>
                            <button type="button" className="cy-btn cy-btn--outline-default" aria-pressed={live} onClick={() => setLive((v) => !v)}>
                                <Icon name="activity" /> {live ? 'Live' : 'Paused'}
                            </button>
                            <IconButton icon="arrow-clockwise" label="Refresh locations" spin={loading || refreshing} disabled={loading || refreshing} onClick={() => load(true)} />
                        </ToolbarGroup>
                    </Toolbar>

                    {loading && data.rows.length === 0 ? (
                        <div className="cy-empty" role="status"><p className="cy-empty__text">Loading team coordinates…</p></div>
                    ) : failed ? (
                        <div className="cy-empty cy-empty--error" role="alert">
                            <Icon name="exclamation-circle" className="cy-empty__icon" />
                            <p className="cy-empty__title">Could not load team locations</p>
                            <button type="button" className="cy-btn cy-btn--outline-theme" onClick={() => load(false)}><Icon name="arrow-clockwise" /> Retry</button>
                        </div>
                    ) : data.rows.length === 0 ? (
                        <div className="cy-empty">
                            <Icon name="geo-alt" className="cy-empty__icon" />
                            <p className="cy-empty__title">No team location records</p>
                            <p className="cy-empty__text">No check-in or patrol coordinates were recorded for {date}. Check a different day.</p>
                        </div>
                    ) : (
                        <>
                            <MapStatsRibbon items={ribbon} />
                            <div className="cy-map-stage">
                                <CorridorSvgMap
                                    label="Team locations map"
                                    alignment={data.alignment}
                                    layers={visible}
                                    groups={MAP_GROUPS}
                                    districts={ctx.districts}
                                    context={ctx.context}
                                    selectedId={selectedId}
                                    onSelect={select}
                                    renderPopup={popup}
                                    focus={focus}
                                    hud={people.length ? (
                                        <button type="button" className="cy-map__btn" aria-pressed={roster} onClick={() => setRoster((r) => !r)}><Icon name="people" /> Roster {people.length}</button>
                                    ) : null}
                                />
                                {roster && people.length > 0 && (
                                    <MapRosterDrawer
                                        people={people}
                                        selectedId={selectedId}
                                        onClose={() => setRoster(false)}
                                        onSelect={(feature) => { setSelectedId(feature.id); setFocus({ lat: feature.lat, lng: feature.lng, z: 8, n: Date.now() }); }}
                                    />
                                )}
                            </div>
                            {data.alignment && (
                                <ChainageRuler
                                    alignment={data.alignment}
                                    layers={visible}
                                    selectedId={selectedId}
                                    onSelect={(feature) => { setSelectedId(feature.id); if (Number.isFinite(feature.lat)) setFocus({ lat: feature.lat, lng: feature.lng, z: 6, n: Date.now() }); }}
                                    onFocusChainage={(m) => {
                                        const pts = data.alignment.points;
                                        const i = pts.findIndex((p) => p[2] >= m);
                                        if (i > 0) setFocus({ lat: pts[i][0], lng: pts[i][1], z: 6, n: Date.now() });
                                    }}
                                />
                            )}
                            {officer && <OfficerDetailModal feature={officer} onClose={() => setOfficer(null)} onPhoto={setPhoto} onLocate={(feature) => { setSelectedId(feature.id); setFocus({ lat: feature.lat, lng: feature.lng, z: 9, n: Date.now() }); }} />}
                            {photo && <PhotoLightbox photo={photo} onClose={() => setPhoto(null)} />}
                        </>
                    )}
                </>
            )}
        </Card>
    );
}
