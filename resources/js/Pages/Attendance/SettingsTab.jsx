import { Panel } from '@/Components/ui/Panel';
import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Box, Flex, Text, Button, TextField, Select, Separator, Badge, IconButton, Spinner, ScrollArea, Checkbox, Switch, Table, Tooltip, Tabs } from '@radix-ui/themes';
import {
    GearIcon, ClockIcon, CalendarIcon, PersonIcon,
    PlusIcon, TrashIcon, Pencil1Icon, CheckCircledIcon,
    CrossCircledIcon, MagnifyingGlassIcon, GlobeIcon,
    LockClosedIcon, MobileIcon, SewingPinIcon, DesktopIcon,
    ArrowUpIcon, ArrowDownIcon
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';
import * as useAttendanceQuery from '@/api/queries/useAttendanceQuery';
import PoliciesManager from './Components/PoliciesManager';
import DateTimePicker from '@/Components/DateTimePicker';
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

const CATEGORY_META = {
    geo_polygon:    { title: 'Geo Polygon',    icon: <GlobeIcon />,     color: 'amber'  },
    wifi_ip:        { title: 'WiFi / IP',       icon: <LockClosedIcon />, color: 'violet' },
    route_waypoint: { title: 'Route Waypoint', icon: <SewingPinIcon />, color: 'blue'   },
    qr_code:        { title: 'QR Code',         icon: <MobileIcon />,    color: 'green'  },
    biometric:      { title: 'Biometric',       icon: <DesktopIcon />,   color: 'red', readonly: true },
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

/* ── modal backdrop ───────────────────────────────────────── */
const Modal = ({ open, onClose, title, children, footer }) => {
    if (!open) return null;
    return (
        <Box
            style={{
                position: 'fixed', inset: 0, zIndex: 10000,
                background: 'rgba(0,0,0,0.65)',
                backdropFilter: 'blur(6px)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                padding: 16,
            }}
            onClick={e => { if (e.target === e.currentTarget) onClose(); }}
        >
            <Box
                style={{
                    background: 'var(--color-panel-solid, var(--color-surface, #ffffff))',
                    border: '1px solid var(--gray-a5)',
                    borderRadius: 'var(--radius-4)',
                    boxShadow: 'var(--shadow-5, 0 25px 50px rgba(0,0,0,0.35))',
                    width: '100%', maxWidth: 760,
                    maxHeight: '92vh',
                    display: 'flex', flexDirection: 'column',
                    overflow: 'hidden',
                }}
            >
                {/* header */}
                <Flex
                    align="center" justify="between" px="4" py="3"
                    style={{ borderBottom: '1px solid var(--gray-a4)', flexShrink: 0, background: 'var(--gray-a2)' }}
                >
                    <Text size="3" weight="bold" style={{ color: 'var(--gray-12)' }}>{title}</Text>
                    <IconButton size="2" variant="ghost" color="gray" onClick={onClose} style={{ cursor: 'pointer' }}>
                        <CrossCircledIcon />
                    </IconButton>
                </Flex>

                {/* body */}
                <Box style={{ flex: 1, overflow: 'auto', minHeight: 0, padding: '16px' }}>
                    {children}
                </Box>

                {/* footer */}
                {footer && (
                    <Flex
                        gap="3" justify="end" px="4" py="3"
                        style={{ borderTop: '1px solid var(--gray-a4)', flexShrink: 0, background: 'var(--gray-a2)' }}
                    >
                        {footer}
                    </Flex>
                )}
            </Box>
        </Box>
    );
};

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

    const validWaypoints = waypoints.filter(w => w.lat && w.lng);

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
        } else {
            setRoadCoords([]);
        }
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
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button color="blue" onClick={handleSave} disabled={isMutating || validWaypoints.length < 2}>
                        {isMutating ? <Spinner size="1" /> : null}
                        Save Route ({validWaypoints.length} waypoints)
                    </Button>
                </>
            }
        >
            <Flex direction="column" gap="4">
                {/* Corridor Tolerance and Status */}
                <Flex align="center" justify="between" wrap="wrap" gap="3">
                    <Flex align="center" gap="3">
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1" style={{ color: 'var(--gray-12)' }}>
                                Corridor Tolerance (meters)
                            </Text>
                            <TextField.Root
                                type="number" size="2" min="10" max="2000"
                                value={tolerance}
                                onChange={e => setTolerance(Number(e.target.value))}
                                style={{ width: 140 }}
                            />
                        </Box>
                        <Badge color={validWaypoints.length >= 2 ? 'green' : 'amber'} variant="soft" size="2">
                            {validWaypoints.length >= 2 ? '✅ Valid Patrol Route' : '⚠️ Need 2+ Waypoints'}
                        </Badge>
                    </Flex>
                    <Button
                        size="2"
                        variant={picking ? 'solid' : 'soft'}
                        color={picking ? 'red' : 'blue'}
                        onClick={() => { setPicking(p => !p); if (!picking) showToast.info('Click anywhere on the map to add a waypoint'); }}
                        style={{ cursor: 'pointer', fontWeight: 600 }}
                    >
                        <PlusIcon /> {picking ? 'Cancel Picking' : 'Pick on Map'}
                    </Button>
                </Flex>

                {picking && (
                    <Box px="3" py="2" style={{ background: 'var(--blue-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--blue-a6)' }}>
                        <Text size="2" color="blue" weight="medium">
                            📍 Live Map Picker Active: Click on the road/corridor to drop a numbered waypoint in sequence.
                        </Text>
                    </Box>
                )}

                {/* Map Preview with Polyline and Radius */}
                <Box style={{ height: 320, borderRadius: 'var(--radius-3)', overflow: 'hidden', border: '1px solid var(--gray-a5)', position: 'relative' }}>
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
                </Box>

                {/* Waypoints Sequence Table */}
                {waypoints.length > 0 && (
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>
                            Waypoint Sequence ({waypoints.length})
                        </Text>
                        <Box style={{ maxHeight: 180, overflowY: 'auto' }}>
                            <Flex direction="column" gap="2">
                                {waypoints.map((w, i) => (
                                    <Flex key={i} align="center" gap="2" p="1" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a3)' }}>
                                        <Badge color={i === 0 ? 'green' : i === waypoints.length - 1 ? 'red' : 'blue'} variant="solid" size="1" style={{ width: 24, justifyContent: 'center' }}>
                                            {i === 0 ? 'S' : i === waypoints.length - 1 ? 'E' : (i + 1)}
                                        </Badge>
                                        <TextField.Root size="1" type="number" step="any" value={w.lat} onChange={e => update(i, 'lat', e.target.value)} style={{ flex: 1 }}>
                                            <TextField.Slot><Text size="1" color="gray">Lat</Text></TextField.Slot>
                                        </TextField.Root>
                                        <TextField.Root size="1" type="number" step="any" value={w.lng} onChange={e => update(i, 'lng', e.target.value)} style={{ flex: 1 }}>
                                            <TextField.Slot><Text size="1" color="gray">Lng</Text></TextField.Slot>
                                        </TextField.Root>
                                        <IconButton size="1" variant="ghost" color="gray" onClick={() => moveUp(i)} disabled={i === 0}>
                                            <ArrowUpIcon />
                                        </IconButton>
                                        <IconButton size="1" variant="ghost" color="gray" onClick={() => moveDown(i)} disabled={i === waypoints.length - 1}>
                                            <ArrowDownIcon />
                                        </IconButton>
                                        <IconButton size="1" variant="ghost" color="red" onClick={() => remove(i)}>
                                            <TrashIcon />
                                        </IconButton>
                                    </Flex>
                                ))}
                            </Flex>
                        </Box>
                    </Flex>
                )}
            </Flex>
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
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button
                        color="amber" onClick={handleSave}
                        disabled={isMutating || valid.length < 3}
                    >
                        {isMutating ? <Spinner size="1" /> : null}
                        Save Polygon ({valid.length}/3+ points)
                    </Button>
                </>
            }
        >
            <Flex direction="column" gap="4">
                <Flex align="center" justify="between" wrap="wrap" gap="3">
                    <Badge color={valid.length >= 3 ? 'green' : 'amber'} variant="soft" size="2">
                        {valid.length} Coordinates {valid.length >= 3 ? '— Closed Boundary Formed' : '— Need at least 3 points'}
                    </Badge>
                    <Button
                        size="2"
                        variant={picking ? 'solid' : 'soft'}
                        color={picking ? 'red' : 'amber'}
                        onClick={() => { setPicking(p => !p); if (!picking) showToast.info('Click map to add a boundary vertex'); }}
                        style={{ cursor: 'pointer', fontWeight: 600 }}
                    >
                        <PlusIcon /> {picking ? 'Cancel' : 'Pick on Map'}
                    </Button>
                </Flex>

                {picking && (
                    <Box px="3" py="2" style={{ background: 'var(--amber-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--amber-a6)' }}>
                        <Text size="2" color="amber" weight="medium">
                            📍 Live Boundary Mode: Click on the map around the toll plaza, office, or gate to draw polygon vertices.
                        </Text>
                    </Box>
                )}

                {/* Live Polygon Map Container */}
                <Box style={{ height: 320, borderRadius: 'var(--radius-3)', overflow: 'hidden', border: '1px solid var(--gray-a5)' }}>
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
                </Box>

                {/* Points List */}
                {points.length > 0 && (
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>
                            Boundary Vertices ({points.length})
                        </Text>
                        <Box style={{ maxHeight: 180, overflowY: 'auto' }}>
                            <Flex direction="column" gap="2">
                                {points.map((pt, i) => (
                                    <Flex key={i} align="center" gap="2" p="1" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a3)' }}>
                                        <Badge color="amber" variant="solid" size="1" style={{ width: 24, justifyContent: 'center' }}>
                                            {i + 1}
                                        </Badge>
                                        <TextField.Root size="1" type="number" step="any" value={pt.lat} onChange={e => update(i, 'lat', e.target.value)} style={{ flex: 1 }}>
                                            <TextField.Slot><Text size="1" color="gray">Lat</Text></TextField.Slot>
                                        </TextField.Root>
                                        <TextField.Root size="1" type="number" step="any" value={pt.lng} onChange={e => update(i, 'lng', e.target.value)} style={{ flex: 1 }}>
                                            <TextField.Slot><Text size="1" color="gray">Lng</Text></TextField.Slot>
                                        </TextField.Root>
                                        <IconButton size="1" variant="ghost" color="gray" onClick={() => moveUp(i)} disabled={i === 0}>
                                            <ArrowUpIcon />
                                        </IconButton>
                                        <IconButton size="1" variant="ghost" color="gray" onClick={() => moveDown(i)} disabled={i === points.length - 1}>
                                            <ArrowDownIcon />
                                        </IconButton>
                                        <IconButton size="1" variant="ghost" color="red" onClick={() => remove(i)}>
                                            <TrashIcon />
                                        </IconButton>
                                    </Flex>
                                ))}
                            </Flex>
                        </Box>
                    </Flex>
                )}
            </Flex>
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
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button color="blue" onClick={handleSave} disabled={isMutating}>
                        {isMutating ? <Spinner size="1" /> : null}
                        {editingType?.id ? 'Update Method' : 'Create Method'}
                    </Button>
                </>
            }
        >
            <Flex direction="column" gap="4">
                {/* name */}
                <Box>
                    <Text size="2" weight="medium" as="div" mb="1" style={{ color: 'var(--gray-12)' }}>
                        Method Name *
                    </Text>
                    <TextField.Root
                        size="2" placeholder="e.g. Main Plaza Geofence"
                        value={form.name}
                        onChange={e => setForm(p => ({ ...p, name: e.target.value }))}
                    />
                </Box>

                {/* description */}
                <Box>
                    <Text size="2" weight="medium" as="div" mb="1" style={{ color: 'var(--gray-12)' }}>
                        Description
                    </Text>
                    <TextField.Root
                        size="2" placeholder="Optional description or zone instructions"
                        value={form.description}
                        onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                    />
                </Box>

                {/* active toggle */}
                <Flex align="center" gap="3">
                    <Switch
                        checked={form.is_active}
                        onCheckedChange={v => setForm(p => ({ ...p, is_active: v }))}
                        size="2"
                    />
                    <Text size="2" weight="medium">{form.is_active ? 'Active (Officers can punch using this method)' : 'Inactive'}</Text>
                </Flex>

                <Separator size="4" />

                {/* type-specific config */}
                {slug === 'wifi_ip' && (
                    <Flex direction="column" gap="3">
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>Network Whitelist Configuration</Text>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Allowed Static IPs (comma-separated)</Text>
                            <TextField.Root
                                size="2" placeholder="103.14.22.5, 182.48.80.12"
                                value={config._tmpAllowedIps ?? (primaryIp?.allowed_ips || []).join(', ')}
                                onChange={e => setConfig(p => ({ ...p, _tmpAllowedIps: e.target.value }))}
                            />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Allowed IP Ranges (CIDR, comma-separated)</Text>
                            <TextField.Root
                                size="2" placeholder="192.168.1.0/24, 10.0.0.0/16"
                                value={config._tmpAllowedRanges ?? (primaryIp?.allowed_ranges || []).join(', ')}
                                onChange={e => setConfig(p => ({ ...p, _tmpAllowedRanges: e.target.value }))}
                            />
                        </Box>
                    </Flex>
                )}

                {slug === 'route_waypoint' && (
                    <Flex direction="column" gap="3">
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>Route Configuration</Text>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Tolerance (meters)</Text>
                            <TextField.Root
                                size="2" type="number" min="10" max="2000"
                                style={{ width: 160 }}
                                value={primaryRoute?.tolerance || 150}
                                onChange={e => {
                                    const routes = Array.isArray(config.routes) ? [...config.routes] : [];
                                    if (routes[0]) routes[0] = { ...routes[0], tolerance: Number(e.target.value) };
                                    setConfig(p => ({ ...p, routes }));
                                }}
                            />
                        </Box>
                        <Text size="2" color="gray">
                            Waypoints: {primaryRoute?.waypoints?.length || 0} configured — use the map button in the type list to edit on the live GIS map.
                        </Text>
                    </Flex>
                )}

                {slug === 'qr_code' && (
                    <Flex direction="column" gap="3">
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>QR Code Configuration</Text>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Max Scanning Distance (meters)</Text>
                            <TextField.Root
                                size="2" type="number" min="1" max="500"
                                style={{ width: 160 }}
                                value={config.max_distance || 50}
                                onChange={e => setConfig(p => ({ ...p, max_distance: Number(e.target.value) }))}
                            />
                        </Box>
                        <Flex align="center" gap="3">
                            <Switch
                                checked={config.require_location ?? false}
                                onCheckedChange={v => setConfig(p => ({ ...p, require_location: v }))}
                                size="2"
                            />
                            <Text size="2">Require GPS location coordinates when scanning QR code</Text>
                        </Flex>
                    </Flex>
                )}

                {slug === 'geo_polygon' && (
                    <Flex direction="column" gap="3">
                        <Text size="2" weight="bold" style={{ color: 'var(--gray-12)' }}>Geofence Configuration</Text>
                        <Text size="2" color="gray">
                            Polygon zones: {config.polygons?.length || (config.polygon?.length ? 1 : 0)} configured — click the map button in the list to draw/edit boundary points on the live GIS map.
                        </Text>
                    </Flex>
                )}
            </Flex>
        </Modal>
    );
};

/* ════════════════════════════════════════════════════════════
   MAIN SETTINGS TAB
   ═══════════════════════════════════════════════════════════ */
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

    /* accordion open state — all open by default */
    const [openSections, setOpenSections] = useState(Object.keys(CATEGORY_META));
    const toggleSection = slug => setOpenSections(p => p.includes(slug) ? p.filter(s => s !== slug) : [...p, slug]);

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
    const handleTypeDelete = async t => {
        if (!confirm(`Delete "${t.name}"? This cannot be undone.`)) return;
        try {
            await deleteAttendanceType.mutateAsync(t.id);
            setTypes(p => p.filter(x => x.id !== t.id));
            showToast.success('Type deleted.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete.');
        }
    };

    /* ── render ─────────────────────────────────────────────── */
    return (
        <>
            <Tabs.Root value={activeSubTab} onValueChange={setActiveSubTab}>
                <Tabs.List
                    style={{
                        marginBottom: 'var(--space-4)',
                        overflowX: 'auto',
                        display: 'flex',
                        flexWrap: 'nowrap',
                    }}
                >
                    <Tabs.Trigger value="general">
                        <Flex align="center" gap="2">
                            <GearIcon />
                            <Text size="2" weight="medium">General Settings</Text>
                        </Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="types">
                        <Flex align="center" gap="2">
                            <GlobeIcon />
                            <Text size="2" weight="medium">Attendance Methods</Text>
                        </Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="policies">
                        <Flex align="center" gap="2">
                            <LockClosedIcon />
                            <Text size="2" weight="medium">Access Policies</Text>
                        </Flex>
                    </Tabs.Trigger>
                </Tabs.List>

                {/* ── General Settings Tab ───────────────────── */}
                <Tabs.Content value="general">
                    <Box py="3">
                        <form onSubmit={handleGeneralSave}>
                            <Flex direction="column" gap="5">
                                {/* Office Timing */}
                                <Panel>
                                    <Flex align="center" gap="2" mb="3">
                                        <ClockIcon style={{ color: 'var(--accent-9)', width: 16 }} />
                                        <Text size="3" weight="bold">Office Timing</Text>
                                    </Flex>
                                    <Flex gap="4" wrap="wrap">
                                        <Box style={{ flex: '1 1 180px' }}>
                                            <Text size="2" weight="medium" as="div" mb="1">Start Time</Text>
                                            <DateTimePicker
                                                mode="time"
                                                size="2"
                                                name="office_start_time"
                                                value={settings?.office_start_time || '09:00'}
                                                onChange={(val) => setSettings(prev => ({ ...prev, office_start_time: val }))}
                                                clearable={false}
                                            />
                                        </Box>
                                        <Box style={{ flex: '1 1 180px' }}>
                                            <Text size="2" weight="medium" as="div" mb="1">End Time</Text>
                                            <DateTimePicker
                                                mode="time"
                                                size="2"
                                                name="office_end_time"
                                                value={settings?.office_end_time || '18:00'}
                                                onChange={(val) => setSettings(prev => ({ ...prev, office_end_time: val }))}
                                                clearable={false}
                                            />
                                        </Box>
                                        <Box style={{ flex: '1 1 180px' }}>
                                            <Text size="2" weight="medium" as="div" mb="1">Break Duration (min)</Text>
                                            <TextField.Root
                                                type="number" size="2" name="break_time_duration"
                                                min="0" max="480"
                                                defaultValue={settings?.break_time_duration || 60}
                                            />
                                        </Box>
                                    </Flex>
                                </Panel>

                                {/* Attendance Policies */}
                                <Panel>
                                    <Flex align="center" gap="2" mb="3">
                                        <GearIcon style={{ color: 'var(--accent-9)', width: 16 }} />
                                        <Text size="3" weight="bold">Attendance Policies</Text>
                                    </Flex>
                                    <Flex direction="column" gap="4">
                                        <Flex gap="4" wrap="wrap">
                                            <Box style={{ flex: '1 1 180px' }}>
                                                <Text size="2" weight="medium" as="div" mb="1">Late Mark After (min)</Text>
                                                <TextField.Root
                                                    type="number" size="2" name="late_mark_after"
                                                    min="0" max="120"
                                                    defaultValue={settings?.late_mark_after || 15}
                                                />
                                            </Box>
                                            <Box style={{ flex: '1 1 180px' }}>
                                                <Text size="2" weight="medium" as="div" mb="1">Early Leave Before (min)</Text>
                                                <TextField.Root
                                                    type="number" size="2" name="early_leave_before"
                                                    min="0" max="120"
                                                    defaultValue={settings?.early_leave_before || 30}
                                                />
                                            </Box>
                                            <Box style={{ flex: '1 1 180px' }}>
                                                <Text size="2" weight="medium" as="div" mb="1">Overtime After (min)</Text>
                                                <TextField.Root
                                                    type="number" size="2" name="overtime_after"
                                                    min="0" max="480"
                                                    defaultValue={settings?.overtime_after || 30}
                                                />
                                            </Box>
                                        </Flex>

                                        <Flex gap="4" wrap="wrap" align="center">
                                            <Flex align="center" gap="2" style={{ cursor: 'pointer', height: '100%', minHeight: 32 }}>
                                                <Checkbox
                                                    size="2"
                                                    checked={autoPunchOut}
                                                    onCheckedChange={v => setAutoPunchOut(!!v)}
                                                />
                                                <Text size="2">Enable Auto Punch Out</Text>
                                            </Flex>
                                            {autoPunchOut && (
                                                <Box style={{ flex: '1 1 180px' }}>
                                                    <Text size="2" weight="medium" as="div" mb="1">Auto Punch Out Time</Text>
                                                    <DateTimePicker
                                                        mode="time"
                                                        size="2"
                                                        name="auto_punch_out_time"
                                                        value={settings?.auto_punch_out_time || '18:00'}
                                                        onChange={(val) => setSettings(prev => ({ ...prev, auto_punch_out_time: val }))}
                                                        clearable={false}
                                                    />
                                                </Box>
                                            )}
                                        </Flex>
                                    </Flex>
                                </Panel>

                                {/* Weekend */}
                                <Panel>
                                    <Flex align="center" gap="2" mb="3">
                                        <CalendarIcon style={{ color: 'var(--accent-9)', width: 16 }} />
                                        <Text size="3" weight="bold">Weekend Days</Text>
                                    </Flex>
                                    <Flex gap="5" wrap="wrap">
                                        {['friday', 'saturday', 'sunday'].map(day => (
                                            <Flex key={day} align="center" gap="2" style={{ cursor: 'pointer' }}>
                                                <Checkbox
                                                    size="2"
                                                    checked={weekends[day]}
                                                    onCheckedChange={v => setWeekends(p => ({ ...p, [day]: !!v }))}
                                                />
                                                <Text size="2" style={{ textTransform: 'capitalize' }}>{day}</Text>
                                            </Flex>
                                        ))}
                                    </Flex>
                                </Panel>

                                {/* save button */}
                                <Flex justify="end">
                                    <Button type="submit" size="2" variant="solid" color="accent" disabled={isMutating}>
                                        {isMutating ? <Spinner size="1" /> : null}
                                        Save Settings
                                    </Button>
                                </Flex>
                            </Flex>
                        </form>
                    </Box>
                </Tabs.Content>

                {/* ── Attendance Types Tab ───────────────────── */}
                <Tabs.Content value="types">
                    <Box py="3">
                        <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                            <Flex align="center" gap="2">
                                <PersonIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                                <Text size="3" weight="bold">Attendance Methods</Text>
                            </Flex>
                            <TextField.Root
                                size="2" placeholder="Search types…"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                style={{ width: 220 }}
                            >
                                <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                            </TextField.Root>
                        </Flex>

                        <Flex direction="column" gap="3">
                            {Object.entries(CATEGORY_META).map(([slug, meta]) => {
                                const catTypes = grouped[slug] || [];
                                const isOpen   = openSections.includes(slug);

                                return (
                                    <Panel key={slug}>
                                        {/* accordion header */}
                                        <Flex
                                            align="center" justify="between"
                                            style={{ cursor: 'pointer', userSelect: 'none' }}
                                            onClick={() => toggleSection(slug)}
                                        >
                                            <Flex align="center" gap="3">
                                                <Box style={{ color: `var(--${meta.color}-9)` }}>{meta.icon}</Box>
                                                <Box>
                                                    <Text size="3" weight="bold">{meta.title}</Text>
                                                    <Flex gap="2" mt="1">
                                                        <Badge color={meta.color} variant="soft" size="1">
                                                            {catTypes.length} config{catTypes.length !== 1 ? 's' : ''}
                                                        </Badge>
                                                        <Badge color="green" variant="soft" size="1">
                                                            {catTypes.filter(t => t.is_active).length} active
                                                        </Badge>
                                                        {meta.readonly && (
                                                            <Badge color="gray" variant="soft" size="1">read-only</Badge>
                                                        )}
                                                    </Flex>
                                                </Box>
                                            </Flex>
                                            <Flex align="center" gap="2">
                                                {!meta.readonly && (
                                                    <Button
                                                        size="1" variant="soft" color={meta.color}
                                                        onClick={e => {
                                                            e.stopPropagation();
                                                            setTypeModal({ open: true, type: { id: null, slug, icon: meta.icon, config: {} } });
                                                        }}
                                                    >
                                                        <PlusIcon /> Add
                                                    </Button>
                                                )}
                                                <Text size="2" color="gray">{isOpen ? '▲' : '▼'}</Text>
                                            </Flex>
                                        </Flex>

                                        {/* accordion body */}
                                        {isOpen && (
                                            <Box mt="3">
                                                <Separator size="4" mb="3" />

                                                {/* ── biometric: readonly view with devices ── */}
                                                {meta.readonly ? (
                                                    catTypes.length === 0 ? (
                                                        <Flex direction="column" align="center" py="5" gap="2">
                                                            <Text size="2" color="gray">No biometric attendance types yet.</Text>
                                                            <Text size="1" color="gray">Create one from the Biometric Devices admin panel and assign devices to it.</Text>
                                                        </Flex>
                                                    ) : (
                                                        <>
                                                            <Text size="1" color="gray" mb="3" as="p">
                                                                Biometric types are managed from the Biometric Devices admin panel. Devices listed here are assigned to this type.
                                                            </Text>
                                                            <Table.Root size="2" variant="ghost">
                                                                <Table.Header>
                                                                    <Table.Row>
                                                                        <Table.ColumnHeaderCell><Text size="2">Name</Text></Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell><Text size="2">Status</Text></Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell><Text size="2">Assigned Devices</Text></Table.ColumnHeaderCell>
                                                                    </Table.Row>
                                                                </Table.Header>
                                                                <Table.Body>
                                                                    {catTypes.map(t => (
                                                                        <Table.Row key={t.id}>
                                                                            <Table.Cell>
                                                                                <Text size="2" weight="medium">{t.name}</Text>
                                                                                {t.description && <Text size="1" color="gray" as="div">{t.description}</Text>}
                                                                            </Table.Cell>
                                                                            <Table.Cell>
                                                                                <Badge
                                                                                    color={t.is_active ? 'green' : 'gray'}
                                                                                    variant="soft" size="1"
                                                                                >
                                                                                    {t.is_active ? <><CheckCircledIcon /> Active</> : <><CrossCircledIcon /> Inactive</>}
                                                                                </Badge>
                                                                            </Table.Cell>
                                                                            <Table.Cell>
                                                                                {t.biometric_devices?.length > 0 ? (
                                                                                    <Flex gap="1" wrap="wrap">
                                                                                        {t.biometric_devices.map(d => (
                                                                                            <Badge key={d.id} color="red" variant="soft" size="1">
                                                                                                {d.name}{d.location ? ` — ${d.location}` : ''}
                                                                                            </Badge>
                                                                                        ))}
                                                                                    </Flex>
                                                                                ) : (
                                                                                    <Text size="1" color="gray">No devices assigned</Text>
                                                                                )}
                                                                            </Table.Cell>
                                                                        </Table.Row>
                                                                    ))}
                                                                </Table.Body>
                                                            </Table.Root>
                                                        </>
                                                    )
                                                ) : (
                                                    /* ── normal editable categories ── */
                                                    catTypes.length === 0 ? (
                                                        <Flex direction="column" align="center" py="5" gap="2">
                                                            <Text size="2" color="gray">No {meta.title.toLowerCase()} types yet.</Text>
                                                            <Text size="1" color="gray">Click Add above to create one.</Text>
                                                        </Flex>
                                                    ) : (
                                                        <Table.Root size="2" variant="ghost">
                                                            <Table.Header>
                                                                <Table.Row>
                                                                    <Table.ColumnHeaderCell><Text size="2">Name</Text></Table.ColumnHeaderCell>
                                                                    <Table.ColumnHeaderCell><Text size="2">Description</Text></Table.ColumnHeaderCell>
                                                                    <Table.ColumnHeaderCell><Text size="2">Status</Text></Table.ColumnHeaderCell>
                                                                    <Table.ColumnHeaderCell style={{ textAlign: 'right' }}><Text size="2">Actions</Text></Table.ColumnHeaderCell>
                                                                </Table.Row>
                                                            </Table.Header>
                                                            <Table.Body>
                                                                {catTypes.map(t => (
                                                                    <Table.Row key={t.id}>
                                                                        <Table.Cell>
                                                                            <Text size="2" weight="medium">{t.name}</Text>
                                                                        </Table.Cell>
                                                                        <Table.Cell>
                                                                            <Text size="2" color="gray">{t.description || '—'}</Text>
                                                                        </Table.Cell>
                                                                        <Table.Cell>
                                                                            <Badge color={t.is_active ? 'green' : 'gray'} variant="soft" size="1">
                                                                                {t.is_active ? <><CheckCircledIcon /> Active</> : <><CrossCircledIcon /> Inactive</>}
                                                                            </Badge>
                                                                        </Table.Cell>
                                                                        <Table.Cell>
                                                                            <Flex gap="1" justify="end">
                                                                                <Tooltip content="Edit">
                                                                                    <IconButton size="1" variant="ghost" color="blue"
                                                                                        onClick={() => setTypeModal({ open: true, type: t })}>
                                                                                        <Pencil1Icon />
                                                                                    </IconButton>
                                                                                </Tooltip>
                                                                                {slug === 'route_waypoint' && (
                                                                                    <Tooltip content="Configure Waypoints">
                                                                                        <IconButton size="1" variant="ghost" color="blue"
                                                                                            onClick={() => setWaypointModal({ open: true, type: t })}>
                                                                                            <SewingPinIcon />
                                                                                        </IconButton>
                                                                                    </Tooltip>
                                                                                )}
                                                                                {slug === 'geo_polygon' && (
                                                                                    <Tooltip content="Configure Polygon">
                                                                                        <IconButton size="1" variant="ghost" color="amber"
                                                                                            onClick={() => setPolygonModal({ open: true, type: t })}>
                                                                                            <GlobeIcon />
                                                                                        </IconButton>
                                                                                    </Tooltip>
                                                                                )}
                                                                                <Tooltip content="Delete">
                                                                                    <IconButton size="1" variant="ghost" color="red"
                                                                                        onClick={() => handleTypeDelete(t)}>
                                                                                        <TrashIcon />
                                                                                    </IconButton>
                                                                                </Tooltip>
                                                                            </Flex>
                                                                        </Table.Cell>
                                                                    </Table.Row>
                                                                ))}
                                                            </Table.Body>
                                                        </Table.Root>
                                                    )
                                                )}
                                            </Box>
                                        )}
                                    </Panel>
                                );
                            })}
                        </Flex>
                    </Box>
                </Tabs.Content>

                {/* ── Policies Tab ───────────────────────────── */}
                <Tabs.Content value="policies">
                    <Box py="3">
                        <PoliciesManager employees={employees} departments={departments} designations={designations} />
                    </Box>
                </Tabs.Content>
            </Tabs.Root>

            {/* ── modals ─────────────────────────────────────────── */}
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
