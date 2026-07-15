import { Panel } from '@/Components/ui/Panel';
import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { Box, Flex, Text, Button, TextField, Select, Separator, Badge, IconButton, Spinner, ScrollArea, Checkbox, Switch, Table, Tooltip, Tabs } from '@radix-ui/themes';
import {
    GearIcon, ClockIcon, CalendarIcon, PersonIcon,
    PlusIcon, TrashIcon, Pencil1Icon, CheckCircledIcon,
    CrossCircledIcon, MagnifyingGlassIcon, GlobeIcon,
    LockClosedIcon, MobileIcon, SewingPinIcon, DesktopIcon,
} from '@radix-ui/react-icons';
import { usePage } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';
import * as useAttendanceQuery from '@/api/queries/useAttendanceQuery';
import PoliciesManager from './Components/PoliciesManager';
import DateTimePicker from '@/Components/DateTimePicker';

/* ── map imports (Leaflet — untouched) ───────────────────── */
import { MapContainer, TileLayer, Marker, useMapEvents, useMap } from 'react-leaflet';
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

/* ── tiny map click handlers (unchanged logic) ────────────── */
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
                position: 'fixed', inset: 0, zIndex: 1000,
                background: 'rgba(0,0,0,0.5)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                padding: 16,
            }}
            onClick={e => { if (e.target === e.currentTarget) onClose(); }}
        >
            <Box
                style={{
                    background: 'var(--color-panel-solid)',
                    border: '1px solid var(--gray-a5)',
                    borderRadius: 'var(--radius-4)',
                    width: '100%', maxWidth: 720,
                    maxHeight: '90vh',
                    display: 'flex', flexDirection: 'column',
                    overflow: 'hidden',
                }}
            >
                {/* header */}
                <Flex
                    align="center" justify="between" px="4" py="3"
                    style={{ borderBottom: '1px solid var(--gray-a4)', flexShrink: 0 }}
                >
                    <Text size="4" weight="bold">{title}</Text>
                    <IconButton size="2" variant="ghost" color="gray" onClick={onClose}>
                        <CrossCircledIcon />
                    </IconButton>
                </Flex>

                {/* body - removed ScrollArea to fix Leaflet map rendering */}
                <Box style={{ flex: 1, overflow: 'auto', minHeight: 0, padding: '16px' }}>
                    {children}
                </Box>

                {/* footer */}
                {footer && (
                    <Flex
                        gap="3" justify="end" px="4" py="3"
                        style={{ borderTop: '1px solid var(--gray-a4)', flexShrink: 0 }}
                    >
                        {footer}
                    </Flex>
                )}
            </Box>
        </Box>
    );
};

/* ════════════════════════════════════════════════════════════
   WAYPOINT MAP MODAL
   ═══════════════════════════════════════════════════════════ */
const WaypointModal = ({ open, onClose, type, onSave }) => {
    const getPrimaryRoute = cfg => {
        const routes = Array.isArray(cfg?.routes) ? cfg.routes : [];
        return routes.find(r => r?.is_active !== false) || routes[0] || null;
    };

    const primaryRoute = getPrimaryRoute(type?.config || {});
    const [tolerance,  setTolerance]  = useState(primaryRoute?.tolerance || 150);
    const [waypoints,  setWaypoints]  = useState(primaryRoute?.waypoints || []);
    const [picking,    setPicking]    = useState(false);
    const [isMutating,     setisMutating]     = useState(false);

    // Sync waypoints when type changes
    useEffect(() => {
        const route = getPrimaryRoute(type?.config || {});
        setWaypoints(route?.waypoints || []);
        setTolerance(route?.tolerance || 150);
    }, [type]);
    const mapCenter = waypoints[0]?.lat
        ? [parseFloat(waypoints[0].lat), parseFloat(waypoints[0].lng)]
        : [23.8103, 90.4125];

    const addFromMap = coords => { setWaypoints(p => [...p, coords]); setPicking(false); };
    const remove     = i => setWaypoints(p => p.filter((_, idx) => idx !== i));
    const update     = (i, field, val) => setWaypoints(p => p.map((w, idx) => idx === i ? { ...w, [field]: val } : w));

    const handleSave = async () => {
        if (waypoints.filter(w => w.lat && w.lng).length < 2) {
            showToast.error('At least 2 valid waypoints required.'); return;
        }
        setisMutating(true);
        try {
            const cfg = type?.config || {};
            const existingRoutes = Array.isArray(cfg.routes) ? cfg.routes : [];
            const updated = {
                ...primaryRoute,
                id:        primaryRoute?.id || `route_${Date.now()}`,
                name:      primaryRoute?.name || 'Primary Route',
                waypoints: waypoints.filter(w => w.lat && w.lng),
                tolerance,
                is_active: primaryRoute?.is_active ?? true,
            };
            const remaining = existingRoutes.filter(r => r?.id !== primaryRoute?.id);
            const { waypoints: _w, tolerance: _t, ...rest } = cfg;
            const newConfig = { ...rest, routes: [updated, ...remaining] };
            const data = await updateAttendanceType.mutateAsync({ id: type.id, config: newConfig });
            onSave(data.attendanceType);
            showToast.success('Waypoints saved.');
            onClose();
        } catch { showToast.error('Failed to save waypoints.'); }
        finally { setisMutating(false); }
    };

    return (
        <Modal
            open={open} onClose={onClose}
            title={`Waypoints — ${type?.name || ''}`}
            footer={
                <>
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button color="blue" onClick={handleSave} disabled={isMutating}>
                        {isMutating ? <Spinner size="1" /> : null}
                        Save Waypoints ({waypoints.length})
                    </Button>
                </>
            }
        >
            <Flex direction="column" gap="4">
                {/* tolerance */}
                <Flex align="center" gap="3">
                    <Box style={{ flex: 1 }}>
                        <Text size="2" weight="medium" as="div" mb="1">Tolerance (meters)</Text>
                        <TextField.Root
                            type="number" size="2" min="10" max="1000"
                            value={tolerance}
                            onChange={e => setTolerance(Number(e.target.value))}
                            style={{ width: 140 }}
                        />
                    </Box>
                    <Badge color="blue" variant="soft">{waypoints.length} waypoint{waypoints.length !== 1 ? 's' : ''}</Badge>
                </Flex>

                {/* map */}
                <Box>
                    <Flex justify="between" align="center" mb="2">
                        <Text size="2" weight="medium">Map</Text>
                        <Button
                            size="1"
                            variant={picking ? 'solid' : 'soft'}
                            color={picking ? 'red' : 'blue'}
                            onClick={() => { setPicking(p => !p); if (!picking) showToast.info('Click map to add waypoint'); }}
                        >
                            <PlusIcon /> {picking ? 'Cancel' : 'Add Waypoint'}
                        </Button>
                    </Flex>
                    {picking && (
                        <Box mb="2" px="3" py="2" style={{ background: 'var(--blue-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--blue-a6)' }}>
                            <Text size="2" color="blue">📍 Click the map to place a waypoint</Text>
                        </Box>
                    )}
                    <Box style={{ height: 340, borderRadius: 'var(--radius-3)', overflow: 'hidden', border: '1px solid var(--gray-a4)' }}>
                        <MapContainer center={mapCenter} zoom={13} style={{ height: '100%', width: '100%' }}>
                            <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
                            {waypoints.map((w, i) => w.lat && w.lng && (
                                <Marker
                                    key={i}
                                    position={[parseFloat(w.lat), parseFloat(w.lng)]}
                                    icon={L.divIcon({
                                        html: `<div style="background:var(--blue-9,#3b82f6);color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:11px;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,.3)">${i + 1}</div>`,
                                        className: '', iconSize: [26, 26], iconAnchor: [13, 13],
                                    })}
                                />
                            ))}
                            <MapClickHandler active={picking} onPick={addFromMap} />
                        </MapContainer>
                    </Box>
                </Box>

                {/* list */}
                {waypoints.length > 0 && (
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="medium">Waypoints</Text>
                        {waypoints.map((w, i) => (
                            <Flex key={i} align="center" gap="2">
                                <Badge color="blue" variant="soft" size="1" style={{ flexShrink: 0 }}>{i + 1}</Badge>
                                <TextField.Root size="1" type="number" step="any" value={w.lat} onChange={e => update(i, 'lat', e.target.value)} style={{ flex: 1 }}>
                                    <TextField.Slot><Text size="1" color="gray">Lat</Text></TextField.Slot>
                                </TextField.Root>
                                <TextField.Root size="1" type="number" step="any" value={w.lng} onChange={e => update(i, 'lng', e.target.value)} style={{ flex: 1 }}>
                                    <TextField.Slot><Text size="1" color="gray">Lng</Text></TextField.Slot>
                                </TextField.Root>
                                <IconButton size="1" variant="ghost" color="red" onClick={() => remove(i)}>
                                    <TrashIcon />
                                </IconButton>
                            </Flex>
                        ))}
                    </Flex>
                )}
            </Flex>
        </Modal>
    );
};

/* ════════════════════════════════════════════════════════════
   POLYGON MAP MODAL
   ═══════════════════════════════════════════════════════════ */
const PolygonModal = ({ open, onClose, type, onSave }) => {
    const getPrimaryPolygon = cfg => {
        const polys = Array.isArray(cfg?.polygons) ? cfg.polygons : [];
        return polys.find(p => p?.is_active !== false) || polys[0] || null;
    };

    const primaryPoly = getPrimaryPolygon(type?.config || {});
    const [points,  setPoints]  = useState(primaryPoly?.points || []);
    const [picking, setPicking] = useState(false);
    const [isMutating,  setisMutating]  = useState(false);

    // Sync points when type changes
    useEffect(() => {
        const poly = getPrimaryPolygon(type?.config || {});
        setPoints(poly?.points || []);
    }, [type]);
    const mapCenter = points[0]?.lat
        ? [parseFloat(points[0].lat), parseFloat(points[0].lng)]
        : [23.8103, 90.4125];

    const addFromMap = coords => { setPoints(p => [...p, coords]); setPicking(false); };
    const remove     = i => setPoints(p => p.filter((_, idx) => idx !== i));
    const update     = (i, field, val) => setPoints(p => p.map((pt, idx) => idx === i ? { ...pt, [field]: val } : pt));

    const valid = points.filter(p => p.lat && p.lng);

    const handleSave = async () => {
        if (valid.length < 3) { showToast.error('Minimum 3 valid points required.'); return; }
        setisMutating(true);
        try {
            const cfg  = type?.config || {};
            const polys = Array.isArray(cfg.polygons) ? cfg.polygons : [];
            const updated = {
                ...primaryPoly,
                id:        primaryPoly?.id || `polygon_${Date.now()}`,
                name:      primaryPoly?.name || 'Primary Location',
                points:    valid,
                is_active: primaryPoly?.is_active ?? true,
            };
            const remaining = polys.filter(p => p?.id !== primaryPoly?.id);
            const { polygon: _p, ...rest } = cfg;
            const newConfig = { ...rest, polygons: [updated, ...remaining] };
            const data = await updateAttendanceType.mutateAsync({ id: type.id, config: newConfig });
            onSave(data.attendanceType);
            showToast.success('Polygon saved.');
            onClose();
        } catch { showToast.error('Failed to save polygon.'); }
        finally { setisMutating(false); }
    };

    return (
        <Modal
            open={open} onClose={onClose}
            title={`Geo Polygon — ${type?.name || ''}`}
            footer={
                <>
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button
                        color="amber" onClick={handleSave}
                        disabled={isMutating || valid.length < 3}
                    >
                        {isMutating ? <Spinner size="1" /> : null}
                        Save Polygon ({valid.length}/3+ pts)
                    </Button>
                </>
            }
        >
            <Flex direction="column" gap="4">
                <Flex align="center" gap="2">
                    <Badge color={valid.length >= 3 ? 'green' : 'red'} variant="soft">
                        {points.length} point{points.length !== 1 ? 's' : ''}
                        {valid.length >= 3 ? ' — valid' : ' — need 3+'}
                    </Badge>
                </Flex>

                {/* map */}
                <Box>
                    <Flex justify="between" align="center" mb="2">
                        <Text size="2" weight="medium">Map</Text>
                        <Button
                            size="1"
                            variant={picking ? 'solid' : 'soft'}
                            color={picking ? 'red' : 'amber'}
                            onClick={() => { setPicking(p => !p); if (!picking) showToast.info('Click map to add point'); }}
                        >
                            <PlusIcon /> {picking ? 'Cancel' : 'Add Point'}
                        </Button>
                    </Flex>
                    {picking && (
                        <Box mb="2" px="3" py="2" style={{ background: 'var(--amber-a3)', borderRadius: 'var(--radius-2)', border: '1px solid var(--amber-a6)' }}>
                            <Text size="2" color="amber">📍 Click the map to place a polygon point</Text>
                        </Box>
                    )}
                    <Box style={{ height: 340, borderRadius: 'var(--radius-3)', overflow: 'hidden', border: '1px solid var(--gray-a4)' }}>
                        <MapContainer center={mapCenter} zoom={13} style={{ height: '100%', width: '100%' }}>
                            <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
                            {points.map((pt, i) => pt.lat && pt.lng && (
                                <Marker
                                    key={i}
                                    position={[parseFloat(pt.lat), parseFloat(pt.lng)]}
                                    icon={L.divIcon({
                                        html: `<div style="background:var(--amber-9,#f5a524);color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:11px;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,.3)">${i + 1}</div>`,
                                        className: '', iconSize: [26, 26], iconAnchor: [13, 13],
                                    })}
                                />
                            ))}
                            <MapClickHandler active={picking} onPick={addFromMap} />
                        </MapContainer>
                    </Box>
                </Box>

                {/* list */}
                {points.length > 0 && (
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="medium">Points</Text>
                        {points.map((pt, i) => (
                            <Flex key={i} align="center" gap="2">
                                <Badge color="amber" variant="soft" size="1" style={{ flexShrink: 0 }}>{i + 1}</Badge>
                                <TextField.Root size="1" type="number" step="any" value={pt.lat} onChange={e => update(i, 'lat', e.target.value)} style={{ flex: 1 }}>
                                    <TextField.Slot><Text size="1" color="gray">Lat</Text></TextField.Slot>
                                </TextField.Root>
                                <TextField.Root size="1" type="number" step="any" value={pt.lng} onChange={e => update(i, 'lng', e.target.value)} style={{ flex: 1 }}>
                                    <TextField.Slot><Text size="1" color="gray">Lng</Text></TextField.Slot>
                                </TextField.Root>
                                <IconButton size="1" variant="ghost" color="red" onClick={() => remove(i)}>
                                    <TrashIcon />
                                </IconButton>
                            </Flex>
                        ))}
                    </Flex>
                )}
            </Flex>
        </Modal>
    );
};

/* ════════════════════════════════════════════════════════════
   TYPE EDIT MODAL
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
            showToast.success(editingType?.id ? 'Type updated.' : 'Type created.');
            onClose();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to save.');
        } finally { setisMutating(false); }
    };

    const primaryIp    = getPrimaryIpLoc(config);
    const primaryRoute = getPrimaryRoute(config);

    return (
        <Modal
            open={open} onClose={onClose}
            title={editingType?.id ? `Edit — ${editingType.name}` : 'Create Attendance Type'}
            footer={
                <>
                    <Button variant="soft" color="gray" onClick={onClose}>Cancel</Button>
                    <Button color="accent" onClick={handleSave} disabled={isMutating}>
                        {isMutating ? <Spinner size="1" /> : null}
                        {editingType?.id ? 'Update' : 'Create'}
                    </Button>
                </>
            }
        >
            <Flex direction="column" gap="4">
                {/* name */}
                <Box>
                    <Text size="2" weight="medium" as="div" mb="1">Name *</Text>
                    <TextField.Root
                        size="2" placeholder="e.g. Office WiFi"
                        value={form.name}
                        onChange={e => setForm(p => ({ ...p, name: e.target.value }))}
                    />
                </Box>

                {/* description */}
                <Box>
                    <Text size="2" weight="medium" as="div" mb="1">Description</Text>
                    <TextField.Root
                        size="2" placeholder="Optional description"
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
                    <Text size="2">{form.is_active ? 'Active' : 'Inactive'}</Text>
                </Flex>

                <Separator size="4" />

                {/* type-specific config */}
                {slug === 'wifi_ip' && (
                    <Flex direction="column" gap="3">
                        <Text size="3" weight="bold">Network Configuration</Text>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Allowed IPs (comma-separated)</Text>
                            <TextField.Root
                                size="2" placeholder="192.168.1.1, 10.0.0.1"
                                value={config._tmpAllowedIps ?? (primaryIp?.allowed_ips || []).join(', ')}
                                onChange={e => setConfig(p => ({ ...p, _tmpAllowedIps: e.target.value }))}
                            />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Allowed IP Ranges (CIDR, comma-separated)</Text>
                            <TextField.Root
                                size="2" placeholder="192.168.1.0/24"
                                value={config._tmpAllowedRanges ?? (primaryIp?.allowed_ranges || []).join(', ')}
                                onChange={e => setConfig(p => ({ ...p, _tmpAllowedRanges: e.target.value }))}
                            />
                        </Box>
                    </Flex>
                )}

                {slug === 'route_waypoint' && (
                    <Flex direction="column" gap="3">
                        <Text size="3" weight="bold">Route Configuration</Text>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Tolerance (meters)</Text>
                            <TextField.Root
                                size="2" type="number" min="10" max="1000"
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
                            Waypoints: {primaryRoute?.waypoints?.length || 0} configured — use the map button in the type list to edit.
                        </Text>
                    </Flex>
                )}

                {slug === 'qr_code' && (
                    <Flex direction="column" gap="3">
                        <Text size="3" weight="bold">QR Code Configuration</Text>
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Max Distance (meters)</Text>
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
                            <Text size="2">Require location when scanning</Text>
                        </Flex>
                    </Flex>
                )}

                {slug === 'geo_polygon' && (
                    <Flex direction="column" gap="3">
                        <Text size="3" weight="bold">Geofence Configuration</Text>
                        <Text size="2" color="gray">
                            Polygon zones: {config.polygons?.length || 0} configured — use the map button in the type list to edit.
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
    const { attendanceSettings: initSettings, attendanceTypes: initTypes } = usePage().props;

    const [activeSubTab, setActiveSubTab] = useState('general');
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
                        <PoliciesManager />
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














