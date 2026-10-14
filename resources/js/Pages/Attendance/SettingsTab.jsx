import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Button, Card, Checkbox, DateInput, FormField, Icon, Input, Switch, Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { usePage } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';
import * as useAttendanceQuery from '@/api/queries/useAttendanceQuery';
import PoliciesManager from './Components/PoliciesManager';
import ConfirmDialog from './Components/ConfirmDialog';
import { fetchRoadRouteGeometry } from '@/Components/TeamMap/roadRoutingService';

/* ── map imports (Leaflet) ───────────────────── */
import { MapContainer, TileLayer, Marker, Polyline, Polygon, Circle, useMapEvents, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconRetinaUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-icon-2x.png',
    iconUrl:       'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-icon.png',
    shadowUrl:     'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-shadow.png',
});


/* ── helpers ──────────────────────────────────────────────── */
const getBaseSlug = slug => (slug || '').replace(/_\d+$/, '');

/* tone: the Cyber badge colour; hue: the token family of the category glyph */
const CATEGORY_META = {
    geo_polygon:    { title: 'Geo Polygon',    icon: 'geo',        tone: 'warning',   hue: 'amber'  },
    wifi_ip:        { title: 'WiFi / IP',       icon: 'lock',          tone: 'secondary', hue: 'violet' },
    route_waypoint: { title: 'Route Waypoint', icon: 'geo-alt',       tone: 'info',      hue: 'blue'   },
    qr_code:        { title: 'QR Code',         icon: 'phone',         tone: 'success',   hue: 'green'  },
    biometric:      { title: 'Biometric',       icon: 'fingerprint',   tone: 'danger',    hue: 'red', readonly: true },
};

/* ── tiny map click handlers ────────────── */
const MapClickHandler = ({ onPick, active }) => {
    useMapEvents({
        click(e) {
            if (active) onPick({ lat: e.latlng.lat.toFixed(6), lng: e.latlng.lng.toFixed(6) });
        },
    });
    return null;
};

/* ── modal: the shared Cyber dialog (Escape, backdrop click, focus trap and focus return come with it) ── */
const Modal = ({ open, onClose, title, children, footer }) => {
    if (!open) return null;
    return (
        <Dialog title={title} onClose={onClose} className="cy-modal cy-modal--map">
            <div className="cy-modal__body cy-stack">{children}</div>
            {footer && <div className="cy-modal__foot">{footer}</div>}
        </Dialog>
    );
};

/* A latitude / longitude row of a waypoint or polygon vertex list, with reorder and remove. */
const CoordRow = ({ index, badge, badgeColor, item, count, onUpdate, onUp, onDown, onRemove, noun }) => (
    <div className="cy-coordrow">
        <Badge color={badgeColor} className="cy-coordrow__n">{badge}</Badge>
        <Input size="sm" type="number" step="any" aria-label={`${noun} ${index + 1} latitude`} placeholder="Lat" value={item.lat} onChange={e => onUpdate(index, 'lat', e.target.value)} />
        <Input size="sm" type="number" step="any" aria-label={`${noun} ${index + 1} longitude`} placeholder="Lng" value={item.lng} onChange={e => onUpdate(index, 'lng', e.target.value)} />
        <button type="button" className="cy-iconbtn" onClick={() => onUp(index)} disabled={index === 0} aria-label={`Move ${noun} ${index + 1} up`}><Icon name="chevron-down" className="cy-icon--flip" /></button>
        <button type="button" className="cy-iconbtn" onClick={() => onDown(index)} disabled={index === count - 1} aria-label={`Move ${noun} ${index + 1} down`}><Icon name="chevron-down" /></button>
        <button type="button" className="cy-iconbtn cy-iconbtn--danger" onClick={() => onRemove(index)} aria-label={`Remove ${noun} ${index + 1}`}><Icon name="trash" /></button>
    </div>
);


/* ════════════════════════════════════════════════════════════
   WAYPOINT MAP MODAL (Theme-Aware with Live Polyline & Radius)
   ═══════════════════════════════════════════════════════════ */
const WaypointModal = ({ open, onClose, type, onSave }) => {
    const updateAttendanceType = useAttendanceQuery.useUpdateAttendanceType();

    const getPrimaryRoute = cfg => {
        const routes = Array.isArray(cfg?.routes) ? cfg.routes : [];
        return routes.find(r => r?.is_active !== false) || routes[0] || null;
    };

    const primaryRoute = getPrimaryRoute(type?.config || {});
    const [tolerance,  setTolerance]  = useState(primaryRoute?.tolerance || 150);
    const [waypoints,  setWaypoints]  = useState(primaryRoute?.waypoints || []);
    const [roadCoords, setRoadCoords] = useState([]);
    const [picking,    setPicking]    = useState(false);
    const [isMutating, setisMutating] = useState(false);

    // Sync waypoints when type changes
    useEffect(() => {
        const route = getPrimaryRoute(type?.config || {});
        setWaypoints(route?.waypoints || []);
        setTolerance(route?.tolerance || 150);
    }, [type]);

    // Memoised: a fresh array on every render re-ran the effect below, and its setRoadCoords([]) re-rendered again
    // ("Maximum update depth exceeded"), an endless loop that froze the attendance page and its navigation.
    const validWaypoints = useMemo(() => waypoints.filter(w => w.lat && w.lng), [waypoints]);

    // Fetch live road driving geometry when waypoints change
    useEffect(() => {
        if (validWaypoints.length >= 2) {
            let isCurrent = true;
            fetchRoadRouteGeometry(validWaypoints).then(res => {
                if (isCurrent && res && res.latLngs) {
                    setRoadCoords(res.latLngs);
                }
            });
            return () => { isCurrent = false; };
        }
        setRoadCoords(prev => (prev.length ? [] : prev));
        return undefined;
    }, [validWaypoints]);

    const mapCenter = validWaypoints[0]?.lat
        ? [parseFloat(validWaypoints[0].lat), parseFloat(validWaypoints[0].lng)]
        : [23.8103, 90.4125];

    const addFromMap = coords => { setWaypoints(p => [...p, coords]); setPicking(false); };
    const remove     = i => setWaypoints(p => p.filter((_, idx) => idx !== i));
    const update     = (i, field, val) => setWaypoints(p => p.map((w, idx) => idx === i ? { ...w, [field]: val } : w));
    const moveUp     = i => {
        if (i <= 0) return;
        setWaypoints(p => {
            const arr = [...p];
            const temp = arr[i - 1];
            arr[i - 1] = arr[i];
            arr[i] = temp;
            return arr;
        });
    };
    const moveDown   = i => {
        if (i >= waypoints.length - 1) return;
        setWaypoints(p => {
            const arr = [...p];
            const temp = arr[i + 1];
            arr[i + 1] = arr[i];
            arr[i] = temp;
            return arr;
        });
    };

    const handleSave = async () => {
        if (validWaypoints.length < 2) {
            showToast.error('At least 2 valid waypoints required to form a patrol corridor.');
            return;
        }
        setisMutating(true);
        try {
            const cfg = type?.config || {};
            const existingRoutes = Array.isArray(cfg.routes) ? cfg.routes : [];
            const updated = {
                ...primaryRoute,
                id:        primaryRoute?.id || `route_${Date.now()}`,
                name:      primaryRoute?.name || 'Primary Route',
                waypoints: validWaypoints,
                tolerance,
                is_active: primaryRoute?.is_active ?? true,
            };
            const remaining = existingRoutes.filter(r => r?.id !== primaryRoute?.id);
            const { waypoints: _w, tolerance: _t, ...rest } = cfg;
            const newConfig = { ...rest, waypoints: validWaypoints, routes: [updated, ...remaining] };
            const data = await updateAttendanceType.mutateAsync({ id: type.id, config: newConfig });
            onSave(data.attendanceType);
            showToast.success('Waypoints route saved successfully.');
            onClose();
        } catch (e) {
            showToast.error(e?.response?.data?.message || 'Failed to save waypoints.');
        } finally {
            setisMutating(false);
        }
    };

    const polylineCoords = validWaypoints.map(w => [parseFloat(w.lat), parseFloat(w.lng)]);

    return (
        <Modal
            open={open} onClose={onClose}
            title={`Route Waypoint Patrol Corridor — ${type?.name || ''}`}
            footer={
                <>
                    <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" onClick={handleSave} disabled={isMutating || validWaypoints.length < 2} loading={isMutating}>
                        Save Route ({validWaypoints.length} waypoints)
                    </Button>
                </>
            }
        >
            {/* Corridor Tolerance and Status */}
            <div className="cy-modal__row">
                <FormField label="Corridor Tolerance (meters)" htmlFor="wp-tolerance">
                    <Input id="wp-tolerance" type="number" min="10" max="2000" value={tolerance} onChange={e => setTolerance(Number(e.target.value))} className="cy-input--short" />
                </FormField>
                <Badge soft color={validWaypoints.length >= 2 ? 'success' : 'warning'}>
                    {validWaypoints.length >= 2 ? 'Valid patrol route' : 'Need 2+ waypoints'}
                </Badge>
                <Button
                    size="sm"
                    color={picking ? 'danger' : 'theme'}
                    variant={picking ? 'solid' : 'outline'}
                    aria-pressed={picking}
                    onClick={() => { setPicking(p => !p); if (!picking) showToast.info('Click anywhere on the map to add a waypoint'); }}
                >
                    <Icon name="plus-lg" /> {picking ? 'Cancel picking' : 'Pick on map'}
                </Button>
            </div>

            {picking && (
                <div className="cy-alert" data-tone="info" role="status">Live map picker active: click on the road or corridor to drop a numbered waypoint in sequence.</div>
            )}

            {/* Map Preview with Polyline and Radius */}
            <div className="cy-modal__map">
                <MapContainer center={mapCenter} zoom={13} style={{ height: '100%', width: '100%' }}>
                    <TileLayer url="https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png" />
                    {(roadCoords.length >= 2 ? roadCoords : polylineCoords).length >= 2 && (
                        <>
                            <Polyline
                                positions={roadCoords.length >= 2 ? roadCoords : polylineCoords}
                                pathOptions={{ color: '#0284c7', weight: 10, opacity: 0.18, lineCap: 'round', lineJoin: 'round' }}
                            />
                            <Polyline
                                positions={roadCoords.length >= 2 ? roadCoords : polylineCoords}
                                pathOptions={{ color: '#0284c7', weight: 4, opacity: 0.85, lineCap: 'round', lineJoin: 'round' }}
                            />
                            <Polyline
                                positions={roadCoords.length >= 2 ? roadCoords : polylineCoords}
                                pathOptions={{ color: '#ffffff', weight: 2, opacity: 0.9, dashArray: '6, 6', lineCap: 'round', lineJoin: 'round' }}
                            />
                        </>
                    )}
                    {validWaypoints.map((w, i) => {
                        const isStart = i === 0;
                        const isEnd = i === validWaypoints.length - 1;
                        const markerColor = isStart ? '#10b981' : isEnd ? '#ef4444' : '#0284c7';
                        const pos = [parseFloat(w.lat), parseFloat(w.lng)];

                        return (
                            <React.Fragment key={i}>
                                <Circle
                                    center={pos}
                                    radius={tolerance}
                                    pathOptions={{ color: markerColor, fillColor: markerColor, fillOpacity: 0.12, weight: 1.5 }}
                                />
                                <Marker
                                    position={pos}
                                    icon={L.divIcon({
                                        html: `<div style="background:${markerColor};color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:11px;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,.4)">${isStart ? 'S' : isEnd ? 'E' : (i + 1)}</div>`,
                                        className: '',
                                        iconSize: [26, 26],
                                        iconAnchor: [13, 13],
                                    })}
                                />
                            </React.Fragment>
                        );
                    })}
                    <MapClickHandler active={picking} onPick={addFromMap} />
                </MapContainer>
            </div>

            {/* Waypoints Sequence */}
            {waypoints.length > 0 && (
                <div role="group" aria-label={`Waypoint sequence (${waypoints.length})`}>
                    <p className="cy-label">Waypoint sequence ({waypoints.length})</p>
                    <div className="cy-coordlist">
                        {waypoints.map((w, i) => (
                            <CoordRow key={i} index={i} item={w} count={waypoints.length} noun="waypoint"
                                badge={i === 0 ? 'S' : i === waypoints.length - 1 ? 'E' : (i + 1)}
                                badgeColor={i === 0 ? 'success' : i === waypoints.length - 1 ? 'danger' : 'info'}
                                onUpdate={update} onUp={moveUp} onDown={moveDown} onRemove={remove} />
                        ))}
                    </div>
                </div>
            )}
        </Modal>
    );
};


/* ════════════════════════════════════════════════════════════
   POLYGON MAP MODAL (Theme-Aware with Live Filled Polygon)
   ═══════════════════════════════════════════════════════════ */
const PolygonModal = ({ open, onClose, type, onSave }) => {
    const updateAttendanceType = useAttendanceQuery.useUpdateAttendanceType();

    const getPrimaryPolygon = cfg => {
        const polys = Array.isArray(cfg?.polygons) ? cfg.polygons : [];
        return polys.find(p => p?.is_active !== false) || polys[0] || null;
    };

    const primaryPoly = getPrimaryPolygon(type?.config || {});
    const [points,  setPoints]  = useState(primaryPoly?.points || type?.config?.polygon || []);
    const [picking, setPicking] = useState(false);
    const [isMutating, setisMutating] = useState(false);

    // Sync points when type changes
    useEffect(() => {
        const poly = getPrimaryPolygon(type?.config || {});
        setPoints(poly?.points || type?.config?.polygon || []);
    }, [type]);

    const valid = points.filter(p => p.lat && p.lng);
    const mapCenter = valid[0]?.lat
        ? [parseFloat(valid[0].lat), parseFloat(valid[0].lng)]
        : [23.8103, 90.4125];

    const addFromMap = coords => { setPoints(p => [...p, coords]); setPicking(false); };
    const remove     = i => setPoints(p => p.filter((_, idx) => idx !== i));
    const update     = (i, field, val) => setPoints(p => p.map((pt, idx) => idx === i ? { ...pt, [field]: val } : pt));
    const moveUp     = i => {
        if (i <= 0) return;
        setPoints(p => {
            const arr = [...p];
            const temp = arr[i - 1];
            arr[i - 1] = arr[i];
            arr[i] = temp;
            return arr;
        });
    };
    const moveDown   = i => {
        if (i >= points.length - 1) return;
        setPoints(p => {
            const arr = [...p];
            const temp = arr[i + 1];
            arr[i + 1] = arr[i];
            arr[i] = temp;
            return arr;
        });
    };

    const handleSave = async () => {
        if (valid.length < 3) {
            showToast.error('A minimum of 3 valid coordinates is required to form a closed polygon boundary.');
            return;
        }
        setisMutating(true);
        try {
            const cfg  = type?.config || {};
            const polys = Array.isArray(cfg.polygons) ? cfg.polygons : [];
            const updated = {
                ...primaryPoly,
                id:        primaryPoly?.id || `polygon_${Date.now()}`,
                name:      primaryPoly?.name || type?.name || 'Primary Location Zone',
                points:    valid,
                is_active: primaryPoly?.is_active ?? true,
            };
            const remaining = polys.filter(p => p?.id !== primaryPoly?.id);
            const { polygon: _p, ...rest } = cfg;
            const newConfig = { ...rest, polygon: valid, polygons: [updated, ...remaining] };
            const data = await updateAttendanceType.mutateAsync({ id: type.id, config: newConfig });
            onSave(data.attendanceType);
            showToast.success('Geofence polygon saved successfully.');
            onClose();
        } catch (e) {
            showToast.error(e?.response?.data?.message || 'Failed to save polygon.');
        } finally {
            setisMutating(false);
        }
    };

    const polygonCoords = valid.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);

    return (
        <Modal
            open={open} onClose={onClose}
            title={`Geofence Boundary Zone — ${type?.name || ''}`}
            footer={
                <>
                    <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" onClick={handleSave} disabled={isMutating || valid.length < 3} loading={isMutating}>
                        Save Polygon ({valid.length}/3+ points)
                    </Button>
                </>
            }
        >
            <div className="cy-modal__row">
                <Badge soft color={valid.length >= 3 ? 'success' : 'warning'}>
                    {valid.length} coordinates {valid.length >= 3 ? '— closed boundary formed' : '— need at least 3 points'}
                </Badge>
                <Button
                    size="sm"
                    color={picking ? 'danger' : 'theme'}
                    variant={picking ? 'solid' : 'outline'}
                    aria-pressed={picking}
                    onClick={() => { setPicking(p => !p); if (!picking) showToast.info('Click map to add a boundary vertex'); }}
                >
                    <Icon name="plus-lg" /> {picking ? 'Cancel' : 'Pick on map'}
                </Button>
            </div>

            {picking && (
                <div className="cy-alert" data-tone="warning" role="status">Live boundary mode: click on the map around the toll plaza, office, or gate to draw polygon vertices.</div>
            )}

            {/* Live Polygon Map Container */}
            <div className="cy-modal__map">
                <MapContainer center={mapCenter} zoom={13} style={{ height: '100%', width: '100%' }}>
                    <TileLayer url="https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png" />
                    {polygonCoords.length >= 3 && (
                        <Polygon
                            positions={polygonCoords}
                            pathOptions={{ color: '#d97706', fillColor: '#f59e0b', fillOpacity: 0.25, weight: 2.5, dashArray: '6, 6' }}
                        />
                    )}
                    {valid.map((pt, i) => (
                        <Marker
                            key={i}
                            position={[parseFloat(pt.lat), parseFloat(pt.lng)]}
                            icon={L.divIcon({
                                html: `<div style="background:#d97706;color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:11px;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,.4)">${i + 1}</div>`,
                                className: '', iconSize: [26, 26], iconAnchor: [13, 13],
                            })}
                        />
                    ))}
                    <MapClickHandler active={picking} onPick={addFromMap} />
                </MapContainer>
            </div>

            {/* Points List */}
            {points.length > 0 && (
                <div role="group" aria-label={`Boundary vertices (${points.length})`}>
                    <p className="cy-label">Boundary vertices ({points.length})</p>
                    <div className="cy-coordlist">
                        {points.map((pt, i) => (
                            <CoordRow key={i} index={i} item={pt} count={points.length} noun="vertex" badge={i + 1} badgeColor="warning"
                                onUpdate={update} onUp={moveUp} onDown={moveDown} onRemove={remove} />
                        ))}
                    </div>
                </div>
            )}
        </Modal>
    );
};


/* ════════════════════════════════════════════════════════════
   TYPE EDIT / CREATE MODAL
   ═══════════════════════════════════════════════════════════ */
const TypeModal = ({ open, onClose, editingType, onSave }) => {
    const updateAttendanceType = useAttendanceQuery.useUpdateAttendanceType();
    const createAttendanceType = useAttendanceQuery.useCreateAttendanceType();
    
    const [form,    setForm]    = useState({ name: '', description: '', is_active: true });
    const [config,  setConfig]  = useState({});
    const [isMutating,  setisMutating]  = useState(false);

    useEffect(() => {
        if (editingType) {
            setForm({
                name:        editingType.name        || '',
                description: editingType.description || '',
                is_active:   editingType.is_active   ?? true,
            });
            setConfig(editingType.config || {});
        } else {
            setForm({ name: '', description: '', is_active: true });
            setConfig({});
        }
    }, [editingType, open]);

    const slug = getBaseSlug(editingType?.slug);

    const getPrimaryRoute   = cfg => { const r = Array.isArray(cfg?.routes)    ? cfg.routes    : []; return r.find(x => x?.is_active !== false) || r[0] || null; };
    const getPrimaryIpLoc   = cfg => { const r = Array.isArray(cfg?.ip_locations) ? cfg.ip_locations : []; return r.find(x => x?.is_active !== false) || r[0] || null; };

    const handleSave = async () => {
        if (!form.name.trim()) {
            showToast.error('Please enter a name for this attendance method.');
            return;
        }
        setisMutating(true);
        try {
            let finalConfig = { ...config };

            if (slug === 'wifi_ip') {
                const ipLocs    = Array.isArray(config.ip_locations) ? config.ip_locations : [];
                const primary   = ipLocs[0] || { id: `office_${Date.now()}`, name: 'Primary Office', is_active: true };
                const ips       = (config._tmpAllowedIps   || '').split(',').map(s => s.trim()).filter(Boolean);
                const ranges    = (config._tmpAllowedRanges || '').split(',').map(s => s.trim()).filter(Boolean);
                const updated   = { ...primary, allowed_ips: ips, allowed_ranges: ranges };
                const { _tmpAllowedIps: _a, _tmpAllowedRanges: _b, ...rest } = finalConfig;
                finalConfig = { ...rest, ip_locations: [updated, ...ipLocs.slice(1)] };
            }

            const payload = { name: form.name, description: form.description, is_active: form.is_active, config: finalConfig };
            let res;
            if (editingType?.id) {
                res = await updateAttendanceType.mutateAsync({ id: editingType.id, ...payload });
            } else {
                res = await createAttendanceType.mutateAsync({ ...payload, slug: editingType?.slug });
            }
            onSave(res.attendanceType, !editingType?.id);
            showToast.success(editingType?.id ? 'Attendance method updated.' : 'Attendance method created.');
            onClose();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to save attendance method.');
        } finally { setisMutating(false); }
    };

    const primaryIp    = getPrimaryIpLoc(config);
    const primaryRoute = getPrimaryRoute(config);

    return (
        <Modal
            open={open} onClose={onClose}
            title={editingType?.id ? `Edit Method — ${editingType.name}` : `Create New ${CATEGORY_META[slug]?.title || 'Attendance Method'}`}
            footer={
                <>
                    <Button color="default" variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" onClick={handleSave} disabled={isMutating} loading={isMutating}>
                        {editingType?.id ? 'Update Method' : 'Create Method'}
                    </Button>
                </>
            }
        >
            <FormField label="Method name" htmlFor="type-name" required>
                <Input id="type-name" placeholder="e.g. Main Plaza Geofence" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} />
            </FormField>

            <FormField label="Description" htmlFor="type-desc">
                <Input id="type-desc" placeholder="Optional description or zone instructions" value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))} />
            </FormField>

            <Switch checked={form.is_active} onCheckedChange={v => setForm(p => ({ ...p, is_active: v }))} label={form.is_active ? 'Active (Officers can punch using this method)' : 'Inactive'} />

            {slug === 'wifi_ip' && (
                <fieldset className="cy-fieldset cy-stack cy-fieldset--ruled">
                    <legend className="cy-legend">Network whitelist configuration</legend>
                    <FormField label="Allowed static IPs (comma-separated)" htmlFor="type-ips">
                        <Input id="type-ips" placeholder="103.14.22.5, 182.48.80.12"
                            value={config._tmpAllowedIps ?? (primaryIp?.allowed_ips || []).join(', ')}
                            onChange={e => setConfig(p => ({ ...p, _tmpAllowedIps: e.target.value }))} />
                    </FormField>
                    <FormField label="Allowed IP ranges (CIDR, comma-separated)" htmlFor="type-ranges">
                        <Input id="type-ranges" placeholder="192.168.1.0/24, 10.0.0.0/16"
                            value={config._tmpAllowedRanges ?? (primaryIp?.allowed_ranges || []).join(', ')}
                            onChange={e => setConfig(p => ({ ...p, _tmpAllowedRanges: e.target.value }))} />
                    </FormField>
                </fieldset>
            )}

            {slug === 'route_waypoint' && (
                <fieldset className="cy-fieldset cy-stack cy-fieldset--ruled">
                    <legend className="cy-legend">Route configuration</legend>
                    <FormField label="Tolerance (meters)" htmlFor="type-tolerance">
                        <Input id="type-tolerance" type="number" min="10" max="2000" className="cy-input--short"
                            value={primaryRoute?.tolerance || 150}
                            onChange={e => {
                                const routes = Array.isArray(config.routes) ? [...config.routes] : [];
                                if (routes[0]) routes[0] = { ...routes[0], tolerance: Number(e.target.value) };
                                setConfig(p => ({ ...p, routes }));
                            }} />
                    </FormField>
                    <span className="cy-muted">Waypoints: {primaryRoute?.waypoints?.length || 0} configured — use the map button in the type list to edit on the live GIS map.</span>
                </fieldset>
            )}

            {slug === 'qr_code' && (
                <fieldset className="cy-fieldset cy-stack cy-fieldset--ruled">
                    <legend className="cy-legend">QR code configuration</legend>
                    <FormField label="Max scanning distance (meters)" htmlFor="type-maxdist">
                        <Input id="type-maxdist" type="number" min="1" max="500" className="cy-input--short"
                            value={config.max_distance || 50}
                            onChange={e => setConfig(p => ({ ...p, max_distance: Number(e.target.value) }))} />
                    </FormField>
                    <Switch checked={config.require_location ?? false} onCheckedChange={v => setConfig(p => ({ ...p, require_location: v }))} label="Require GPS location coordinates when scanning QR code" />
                </fieldset>
            )}

            {slug === 'geo_polygon' && (
                <fieldset className="cy-fieldset cy-stack cy-fieldset--ruled">
                    <legend className="cy-legend">Geofence configuration</legend>
                    <span className="cy-muted">Polygon zones: {config.polygons?.length || (config.polygon?.length ? 1 : 0)} configured — click the map button in the list to draw/edit boundary points on the live GIS map.</span>
                </fieldset>
            )}
        </Modal>
    );
};


/* ════════════════════════════════════════════════════════════
   MAIN SETTINGS TAB
   ═══════════════════════════════════════════════════════════ */
const TABS_ID = 'attendance-settings';

const SUB_TABS = [
    { key: 'general', label: 'General Settings' },
    { key: 'types', label: 'Attendance Methods' },
    { key: 'policies', label: 'Access Policies' },
];

const SettingsTab = () => {
    const {
        attendanceSettings: initSettings,
        attendanceTypes: initTypes,
        employees = [],
        departments = [],
        designations = [],
    } = usePage().props;

    /* The settings sub-tab lives in the URL under an `st_` prefix (every
       Attendance tab stays mounted). */
    const f = useQueryFilters({ mode: 'client', debounceKeys: [], defaults: { st_sub: 'general' } });
    const activeSubTab = f.values.st_sub;
    const setActiveSubTab = (v) => f.set('st_sub', v);
    const [settings,   setSettings]   = useState(initSettings || {});
    const [types,      setTypes]      = useState(initTypes    || []);
    const [search,     setSearch]     = useState('');
    const [autoPunchOut, setAutoPunchOut] = useState(initSettings?.auto_punch_out || false);

    // React Query mutations
    const updateAttendanceType = useAttendanceQuery.useUpdateAttendanceType();
    const createAttendanceType = useAttendanceQuery.useCreateAttendanceType();
    const deleteAttendanceType = useAttendanceQuery.useDeleteAttendanceType();
    const updateAttendanceSettings = useAttendanceQuery.useUpdateAttendanceSettings();
    const isMutating = updateAttendanceType.isPending || createAttendanceType.isPending || deleteAttendanceType.isPending || updateAttendanceSettings.isPending;

    /* modals */
    const [typeModal,    setTypeModal]    = useState({ open: false, type: null });
    const [waypointModal,setWaypointModal]= useState({ open: false, type: null });
    const [polygonModal, setPolygonModal] = useState({ open: false, type: null });

    /* weekend checkboxes */
    const [weekends, setWeekends] = useState({
        friday:   (initSettings?.weekend_days || []).includes('friday'),
        saturday: (initSettings?.weekend_days || []).includes('saturday'),
        sunday:   (initSettings?.weekend_days || []).includes('sunday'),
    });

    // Sync state with Inertia props when they change
    useEffect(() => {
        if (initTypes) {
            setTypes(initTypes);
        }
    }, [initTypes]);

    useEffect(() => {
        if (initSettings) {
            setSettings(initSettings);
            setAutoPunchOut(initSettings.auto_punch_out || false);
            setWeekends({
                friday:   (initSettings.weekend_days || []).includes('friday'),
                saturday: (initSettings.weekend_days || []).includes('saturday'),
                sunday:   (initSettings.weekend_days || []).includes('sunday'),
            });
        }
    }, [initSettings]);

    /* grouped types */
    const grouped = useMemo(() => {
        const filtered = search
            ? types.filter(t =>
                t.name.toLowerCase().includes(search.toLowerCase()) ||
                (t.description || '').toLowerCase().includes(search.toLowerCase())
              )
            : types;

        const groups = {};
        Object.keys(CATEGORY_META).forEach(slug => { groups[slug] = []; });
        filtered.forEach(t => {
            const base = getBaseSlug(t.slug);
            if (groups[base] !== undefined) groups[base].push(t);
        });
        return groups;
    }, [types, search]);

    /* save general settings */
    const handleGeneralSave = async e => {
        e.preventDefault();
        try {
            const fd = new FormData(e.target);
            const data = Object.fromEntries(fd.entries());
            const weekend_days = Object.entries(weekends).filter(([, v]) => v).map(([k]) => k);
            const payload = {
                ...data,
                weekend_days,
                break_time_duration: parseInt(data.break_time_duration) || 0,
                late_mark_after:     parseInt(data.late_mark_after)     || 0,
                early_leave_before:  parseInt(data.early_leave_before)  || 0,
                overtime_after:      parseInt(data.overtime_after)       || 0,
                auto_punch_out:      autoPunchOut,
                auto_punch_out_time: autoPunchOut ? data.auto_punch_out_time : null,
            };
            const res = await updateAttendanceSettings.mutateAsync(payload);
            setSettings(res.attendanceSettings);
            showToast.success(res.message || 'Settings saved.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to save settings.');
        }
    };

    /* type CRUD callbacks */
    const handleTypeSave = (updated, isNew) => {
        setTypes(p => isNew ? [...p, updated] : p.map(t => t.id === updated.id ? updated : t));
    };
    /* The type awaiting delete confirmation. */
    const [deletingType, setDeletingType] = useState(null);
    const handleTypeDelete = async t => {
        setDeletingType(null);
        try {
            await deleteAttendanceType.mutateAsync(t.id);
            setTypes(p => p.filter(x => x.id !== t.id));
            showToast.success('Type deleted.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete.');
        }
    };


    const panel = (key) => ({ role: 'tabpanel', id: panelId(TABS_ID, key), 'aria-labelledby': tabId(TABS_ID, key) });
    const subTab = SUB_TABS.some(t => t.key === activeSubTab) ? activeSubTab : 'general';
    const statusBadge = (t) => (
        <Badge soft color={t.is_active ? 'success' : 'secondary'}>
            <Icon name={t.is_active ? 'check-circle' : 'x-circle'} /> {t.is_active ? 'Active' : 'Inactive'}
        </Badge>
    );
    const iconAction = (label, icon, onClick, danger = false) => (
        <button type="button" className={`cy-iconbtn${danger ? ' cy-iconbtn--danger' : ''}`} onClick={onClick} aria-label={label} title={label}><Icon name={icon} /></button>
    );

    /* ── render ─────────────────────────────────────────────── */
    return (
        <>
            <Tabs tabs={SUB_TABS} value={subTab} onChange={setActiveSubTab} idPrefix={TABS_ID} label="Attendance settings sections" className="cy-tabs--scroll" />

            {/* ── General Settings Tab ───────────────────── */}
            {subTab === 'general' && (
                <form onSubmit={handleGeneralSave} className="dl-page" {...panel('general')}>
                    {/* Office Timing */}
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <Card id="attendance-settings-timing" title="Office timing" flush>
                                <div className="cy-card-section cy-formgrid">
                                    <FormField label="Start time" htmlFor="office_start_time">
                                        <DateInput id="office_start_time" mode="time" label="Start time" name="office_start_time"
                                            value={settings?.office_start_time || '09:00'}
                                            onChange={(val) => setSettings(prev => ({ ...prev, office_start_time: val }))} required />
                                    </FormField>
                                    <FormField label="End time" htmlFor="office_end_time">
                                        <DateInput id="office_end_time" mode="time" label="End time" name="office_end_time"
                                            value={settings?.office_end_time || '18:00'}
                                            onChange={(val) => setSettings(prev => ({ ...prev, office_end_time: val }))} required />
                                    </FormField>
                                    <FormField label="Break duration (min)" htmlFor="break_time_duration">
                                        <Input id="break_time_duration" type="number" name="break_time_duration" min="0" max="480" defaultValue={settings?.break_time_duration || 60} />
                                    </FormField>
                                </div>
                            </Card>
                        </div>
                    </div>

                    {/* Attendance Policies */}
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <Card id="attendance-settings-rules" title="Attendance rules" flush>
                                <div className="cy-card-section cy-formgrid">
                                    <FormField label="Late mark after (min)" htmlFor="late_mark_after">
                                        <Input id="late_mark_after" type="number" name="late_mark_after" min="0" max="120" defaultValue={settings?.late_mark_after || 15} />
                                    </FormField>
                                    <FormField label="Early leave before (min)" htmlFor="early_leave_before">
                                        <Input id="early_leave_before" type="number" name="early_leave_before" min="0" max="120" defaultValue={settings?.early_leave_before || 30} />
                                    </FormField>
                                    <FormField label="Overtime after (min)" htmlFor="overtime_after">
                                        <Input id="overtime_after" type="number" name="overtime_after" min="0" max="480" defaultValue={settings?.overtime_after || 30} />
                                    </FormField>
                                </div>
                                <div className="cy-card-section cy-formgrid">
                                    <Checkbox checked={autoPunchOut} onCheckedChange={v => setAutoPunchOut(!!v)} label="Enable auto punch out" />
                                    {autoPunchOut && (
                                        <FormField label="Auto punch out time" htmlFor="auto_punch_out_time">
                                            <DateInput id="auto_punch_out_time" mode="time" label="Auto punch out time" name="auto_punch_out_time"
                                                value={settings?.auto_punch_out_time || '18:00'}
                                                onChange={(val) => setSettings(prev => ({ ...prev, auto_punch_out_time: val }))} required />
                                        </FormField>
                                    )}
                                </div>
                            </Card>
                        </div>
                    </div>

                    {/* Weekend */}
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <Card id="attendance-settings-weekend" title="Weekend days" flush
                                footer={<div className="cy-footactions"><Button type="submit" loading={isMutating}>Save settings</Button></div>}>
                                <fieldset className="cy-card-section cy-fieldset cy-checkrow">
                                    <legend className="visually-hidden">Weekend days</legend>
                                    {['friday', 'saturday', 'sunday'].map(day => (
                                        <Checkbox key={day} checked={weekends[day]} onCheckedChange={v => setWeekends(p => ({ ...p, [day]: !!v }))} label={day.charAt(0).toUpperCase() + day.slice(1)} />
                                    ))}
                                </fieldset>
                            </Card>
                        </div>
                    </div>
                </form>
            )}

            {/* ── Attendance Types Tab ───────────────────── */}
            {subTab === 'types' && (
                <div className="dl-page" {...panel('types')}>
                    <div className="dl-row">
                        <div className="dl-col dl-col--12">
                            <div className="cy-toolbar" role="search">
                                <label className="cy-tool">
                                    <span className="visually-hidden">Search types</span>
                                    <Icon name="search" />
                                    <input className="cy-input cy-input--sm" type="search" placeholder="Search types…" value={search} onChange={e => setSearch(e.target.value)} />
                                </label>
                            </div>
                        </div>
                    </div>

                    {Object.entries(CATEGORY_META).map(([slug, meta]) => {
                        const catTypes = grouped[slug] || [];
                        return (
                            <div className="dl-row" key={slug}>
                                <div className="dl-col dl-col--12">
                                    <Card
                                        id={`attendance-methods-${slug}`}
                                        title={meta.title}
                                        flush
                                        actions={!meta.readonly ? (
                                            <Button size="sm" color="theme" variant="outline" onClick={() => setTypeModal({ open: true, type: { id: null, slug, config: {} } })} aria-label={`Add ${meta.title} method`}>
                                                <Icon name="plus-lg" /> Add
                                            </Button>
                                        ) : null}
                                    >
                                        <div className="cy-legendrow">
                                            <span className="cy-legendrow__item"><Icon name={meta.icon} /><Badge soft color={meta.tone}>{catTypes.length} config{catTypes.length !== 1 ? 's' : ''}</Badge></span>
                                            <Badge soft color="success">{catTypes.filter(t => t.is_active).length} active</Badge>
                                            {meta.readonly && <Badge soft color="secondary">read-only</Badge>}
                                        </div>

                                        {meta.readonly ? (
                                            catTypes.length === 0 ? (
                                                <div className="cy-empty cy-empty--flat">
                                                    <Icon name={meta.icon} className="cy-empty__icon" />
                                                    <p className="cy-empty__text">No biometric attendance types yet.</p>
                                                    <p className="cy-empty__text">Create one from the Biometric Devices admin panel and assign devices to it.</p>
                                                </div>
                                            ) : (
                                                <>
                                                    <p className="cy-formtext cy-formtext--padded">Biometric types are managed from the Biometric Devices admin panel. Devices listed here are assigned to this type.</p>
                                                    <div className="cy-dt" tabIndex={0} role="region" aria-label="Biometric attendance types, scrollable">
                                                        <table className="cy-table">
                                                            <thead><tr><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Assigned devices</th></tr></thead>
                                                            <tbody>
                                                                {catTypes.map(t => (
                                                                    <tr key={t.id}>
                                                                        <td><b>{t.name}</b>{t.description && <span className="cy-sub">{t.description}</span>}</td>
                                                                        <td>{statusBadge(t)}</td>
                                                                        <td>
                                                                            {t.biometric_devices?.length > 0 ? (
                                                                                <span className="cy-seqrow">
                                                                                    {t.biometric_devices.map(d => <Badge key={d.id} soft color="danger">{d.name}{d.location ? ` — ${d.location}` : ''}</Badge>)}
                                                                                </span>
                                                                            ) : <span className="cy-muted">No devices assigned</span>}
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </>
                                            )
                                        ) : catTypes.length === 0 ? (
                                            <div className="cy-empty cy-empty--flat">
                                                <Icon name={meta.icon} className="cy-empty__icon" />
                                                <p className="cy-empty__text">No {meta.title.toLowerCase()} types yet.</p>
                                                <p className="cy-empty__text">Click Add above to create one.</p>
                                            </div>
                                        ) : (
                                            <div className="cy-dt" tabIndex={0} role="region" aria-label={`${meta.title} methods, scrollable`}>
                                                <table className="cy-table">
                                                    <thead><tr><th scope="col">Name</th><th scope="col">Description</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th></tr></thead>
                                                    <tbody>
                                                        {catTypes.map(t => (
                                                            <tr key={t.id}>
                                                                <td><b>{t.name}</b></td>
                                                                <td className="cy-muted">{t.description || '—'}</td>
                                                                <td>{statusBadge(t)}</td>
                                                                <td>
                                                                    <span className="cy-actions">
                                                                        {iconAction(`Edit ${t.name}`, 'pencil', () => setTypeModal({ open: true, type: t }))}
                                                                        {slug === 'route_waypoint' && iconAction(`Configure waypoints of ${t.name}`, 'geo-alt', () => setWaypointModal({ open: true, type: t }))}
                                                                        {slug === 'geo_polygon' && iconAction(`Configure polygon of ${t.name}`, 'geo', () => setPolygonModal({ open: true, type: t }))}
                                                                        {iconAction(`Delete ${t.name}`, 'trash', () => setDeletingType(t), true)}
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </Card>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* ── Policies Tab ───────────────────────────── */}
            {subTab === 'policies' && (
                <div className="dl-page" {...panel('policies')}>
                    <PoliciesManager employees={employees} departments={departments} designations={designations} />
                </div>
            )}

            {/* ── modals ─────────────────────────────────────────── */}
            {deletingType && (
                <ConfirmDialog
                    title="Delete attendance method"
                    message={`Delete "${deletingType.name}"? This cannot be undone.`}
                    onClose={() => setDeletingType(null)}
                    onConfirm={() => handleTypeDelete(deletingType)}
                />
            )}
            <TypeModal
                open={typeModal.open}
                onClose={() => setTypeModal({ open: false, type: null })}
                editingType={typeModal.type}
                onSave={handleTypeSave}
            />
            <WaypointModal
                open={waypointModal.open}
                onClose={() => setWaypointModal({ open: false, type: null })}
                type={waypointModal.type}
                onSave={t => handleTypeSave(t, false)}
            />
            <PolygonModal
                open={polygonModal.open}
                onClose={() => setPolygonModal({ open: false, type: null })}
                type={polygonModal.type}
                onSave={t => handleTypeSave(t, false)}
            />
        </>
    );
};

export default SettingsTab;
