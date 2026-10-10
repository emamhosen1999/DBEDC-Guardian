import React, { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { Button, Card, Icon } from '@/Components/Cyber';
import {
    ChainageRuler, CorridorSvgMap, LayerList, LayerMenu, MapPopup, MapRosterDrawer, MapStatsRibbon, OfficerDetailModal, PhotoLightbox,
} from '@/Components/Cyber/Map';
import { formatChainage } from '@/Components/Cyber/Map/geo.js';
import { isoDay } from '@/Components/Cyber/Map/util.js';

const PEOPLE_LAYER = 'workforce.punches';
const storageKey = (viewer) => `cy-map:hidden:${viewer ?? 'anon'}`;
const readHidden = (key) => { try { return new Set(JSON.parse(window.localStorage.getItem(key) || '[]')); } catch { return new Set(); } };
const writeHidden = (key, set) => { try { window.localStorage.setItem(key, JSON.stringify([...set])); } catch { /* storage unavailable: stays in memory */ } };

const clock = (iso) => {
    const d = iso ? new Date(iso) : null;
    return d && !Number.isNaN(d.getTime()) ? d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : null;
};

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

function sourceLine(payload, ctx) {
    const s = payload?.alignment?.source;
    return [
        s ? `Centreline: ${s.label}${s.captured_on ? `, ${s.captured_on}` : ''}` : null,
        ctx.districts ? ctx.districts.attribution : null,
        ctx.context ? `Roads: ${ctx.context.attribution}` : null,
    ].filter(Boolean).join(' · ');
}

/**
 * The corridor "everything map" hero card of the main Dashboard. One card, a vector map (no tiles), every layer the
 * viewer is permitted to see (each with its own permission gate and DepartmentScope on the server), and when maximized
 * the layer search, date filters and roster for the whole picture. Never a close tool: minimize and maximize only.
 */
export default function CorridorMapCard({ widget, onRetry }) {
    const viewer = usePage().props?.auth?.user?.employee_id;
    const ctx = useStaticContext();
    const key = storageKey(viewer);
    const [hidden, setHidden] = useState(() => readHidden(key));
    const [override, setOverride] = useState(null); // payload for a chosen date or range
    const [range, setRange] = useState({ from: '', to: '' });
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [query, setQuery] = useState('');
    const [selectedId, setSelectedId] = useState(null);
    const [focus, setFocus] = useState(null);
    const [roster, setRoster] = useState(false);
    const [officer, setOfficer] = useState(null);
    const [photo, setPhoto] = useState(null);

    const payload = override ?? widget.data;

    const setAndStore = useCallback((next) => { setHidden(next); writeHidden(key, next); }, [key]);
    const toggle = useCallback((k) => { const n = new Set(hidden); n.has(k) ? n.delete(k) : n.add(k); setAndStore(n); }, [hidden, setAndStore]);
    const setAll = useCallback((on) => setAndStore(on ? new Set() : new Set((payload?.layers ?? []).map((l) => l.key))), [payload, setAndStore]);

    const fetchRange = useCallback(async (next) => {
        setRange(next);
        if (!next.from && !next.to) { setOverride(null); setFailed(false); return; }
        setLoading(true);
        try {
            const from = next.from || next.to;
            const to = next.to || next.from;
            const { data } = await axios.get(route('dashboard.map'), { params: { from, to } });
            setOverride(data);
            setFailed(false);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, []);

    const layers = payload?.layers ?? [];
    const visible = useMemo(() => layers.filter((l) => !hidden.has(l.key)), [layers, hidden]);
    const people = useMemo(() => (layers.find((l) => l.key === PEOPLE_LAYER && !hidden.has(l.key))?.features ?? []).filter((f) => f.person), [layers, hidden]);
    const select = useCallback((f) => setSelectedId(f ? f.id : null), []);

    const featureTotal = visible.reduce((n, l) => n + l.count, 0);
    const active = people.filter((f) => f.person.status === 'active').length;
    const ribbon = [
        { key: 'layers', label: 'Layers', value: `${visible.length}/${layers.length}`, tone: 'theme' },
        { key: 'features', label: 'On the map', value: featureTotal.toLocaleString('en-GB'), tone: 'neutral' },
        people.length ? { key: 'active', label: 'Staff active', value: active, tone: 'good' } : null,
        people.length ? { key: 'done', label: 'Staff done', value: people.length - active, tone: 'info' } : null,
        payload?.summary?.unplaced ? { key: 'unplaced', label: 'Not placed', value: payload.summary.unplaced, tone: 'warn', title: 'Records whose chainage or position could not be read unambiguously are counted, never guessed.' } : null,
    ];

    const day = payload?.filter?.single_day ? payload.filter.from : `${payload?.filter?.from} to ${payload?.filter?.to}`;
    const today = isoDay(new Date());
    const preset = (daysBack) => { const d = new Date(); d.setDate(d.getDate() - daysBack); return isoDay(d); };

    const footer = (
        <>
            <span className="cy-fresh">{[widget.data?.scope?.label, override ? `Showing ${day}` : 'Today', clock(widget.as_of) ? `updated ${clock(widget.as_of)}` : null, payload?.summary ? `${payload.summary.with_data} layers with data, ${payload.summary.empty} empty and hidden` : null].filter(Boolean).join(' · ')}</span>
            <span className="cy-fresh">{sourceLine(payload, ctx)}</span>
        </>
    );

    const popup = ({ feature, layer, close }) => (
        <MapPopup feature={feature} layer={layer} onClose={close} onPhoto={setPhoto} onDetails={setOfficer} drill={layer.href} />
    );

    return (
        <ErrorBoundary>
            <Card
                id={`widget:${widget.key}`}
                title={widget.title}
                className="cy-mapcard"
                href={widget.href ?? undefined}
                flush
                actions={layers.length ? <LayerMenu layers={layers} groups={payload.groups} hidden={hidden} onToggle={toggle} onSetAll={setAll} /> : null}
                footer={footer}
            >
                {({ expanded }) => (widget.error || !payload ? (
                    <div className="dl-empty">
                        <p className="cy-row__sub">{widget.error ?? 'Map data is unavailable.'}</p>
                        {onRetry && <Button variant="outline" size="sm" onClick={onRetry}><Icon name="arrow-clockwise" /> Retry</Button>}
                    </div>
                ) : (
                    <>
                        <MapStatsRibbon items={ribbon} />
                        <div className="cy-map-stage">
                            <aside className="cy-map-side" aria-label="Map filters" hidden={!expanded}>
                                <div className="cy-map-side__filters">
                                    <label className="cy-field">Search layers
                                        <input type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Defects, daily works, staff" />
                                    </label>
                                    <div className="cy-field__row">
                                        <label className="cy-field">From
                                            <input type="date" max={today} value={range.from} onChange={(e) => fetchRange({ ...range, from: e.target.value })} />
                                        </label>
                                        <label className="cy-field">To
                                            <input type="date" max={today} value={range.to} onChange={(e) => fetchRange({ ...range, to: e.target.value })} />
                                        </label>
                                    </div>
                                    <div className="cy-map-side__presets" role="group" aria-label="Date presets">
                                        <button type="button" className="cy-map__btn" onClick={() => fetchRange({ from: '', to: '' })} aria-pressed={!override}>Today</button>
                                        <button type="button" className="cy-map__btn" onClick={() => fetchRange({ from: preset(1), to: preset(1) })}>Yesterday</button>
                                        <button type="button" className="cy-map__btn" onClick={() => fetchRange({ from: preset(6), to: today })}>7 days</button>
                                        <button type="button" className="cy-map__btn" onClick={() => fetchRange({ from: preset(29), to: today })}>30 days</button>
                                    </div>
                                    <span className="cy-map-side__status" role="status">
                                        {loading ? 'Loading…' : failed ? 'Could not load that period.' : `Showing ${override ? day : 'today'}. Open items show whatever the date; attendance shows the last day of a range.`}
                                    </span>
                                </div>
                                <LayerList layers={layers} groups={payload.groups} hidden={hidden} onToggle={toggle} onSetAll={setAll} query={query} />
                            </aside>
                            <CorridorSvgMap
                                alignment={payload.alignment}
                                layers={visible}
                                groups={payload.groups}
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
                                    onSelect={(f) => { setSelectedId(f.id); setFocus({ lat: f.lat, lng: f.lng, z: 8, n: Date.now() }); }}
                                />
                            )}
                        </div>
                        <ChainageRuler
                            alignment={payload.alignment}
                            layers={visible}
                            selectedId={selectedId}
                            onSelect={(f) => { setSelectedId(f.id); if (Number.isFinite(f.lat)) setFocus({ lat: f.lat, lng: f.lng, z: 6, n: Date.now() }); }}
                            onFocusChainage={(m) => {
                                const pts = payload.alignment.points;
                                const i = pts.findIndex((p) => p[2] >= m);
                                if (i > 0) setFocus({ lat: pts[i][0], lng: pts[i][1], z: 6, n: Date.now() });
                            }}
                        />
                        {officer && <OfficerDetailModal feature={officer} onClose={() => setOfficer(null)} onPhoto={setPhoto} onLocate={(f) => { setSelectedId(f.id); setFocus({ lat: f.lat, lng: f.lng, z: 9, n: Date.now() }); }} />}
                        {photo && <PhotoLightbox photo={photo} onClose={() => setPhoto(null)} />}
                    </>
                ))}
            </Card>
        </ErrorBoundary>
    );
}

export { formatChainage };
