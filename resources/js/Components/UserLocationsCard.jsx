import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Panel } from '@/Components/ui/Panel';
import { Box, Flex, Text, Heading, Spinner, Button } from '@radix-ui/themes';
import { GlobeIcon, ReloadIcon } from '@radix-ui/react-icons';
import { MapStatsRibbon } from './TeamMap/MapStatsRibbon';
import { MapHudControls } from './TeamMap/MapHudControls';
import { MapContainerView } from './TeamMap/MapContainerView';
import { MapGeofenceLayers } from './TeamMap/MapGeofenceLayers';
import { MapLivingMarkers } from './TeamMap/MapLivingMarkers';
import { MapTeamRosterDrawer } from './TeamMap/MapTeamRosterDrawer';
import { OfficerDetailModal } from './TeamMap/OfficerDetailModal';
import { PhotoTelemetryLightbox } from './TeamMap/PhotoTelemetryLightbox';
import { AUTO_POLL_INTERVAL_SEC } from './TeamMap/mapConstants';

export const UserLocationsCard = React.memo(({ selectedDate, updateMap }) => {
    // Data State
    const [users, setUsers] = useState([]);
    const [attendanceTypeConfigs, setAttendanceTypeConfigs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [lastUpdate, setLastUpdate] = useState(null);

    // Filter & HUD State
    const [searchQuery, setSearchQuery] = useState('');
    /* The status filter lives in the URL under a `loc_` prefix (this card sits
       inside the Attendance timesheet, where every tab stays mounted). */
    const f = useQueryFilters({ mode: 'client', debounceKeys: [], defaults: { loc_status: 'all' } });
    const statusFilter = f.values.loc_status; // 'all', 'active', 'completed'
    const setStatusFilter = (v) => f.set('loc_status', v);
    const [currentTileId, setCurrentTileId] = useState(() => {
        return localStorage.getItem('guardian_map_tile_id') || 'voyager';
    });
    const [layerVisibility, setLayerVisibility] = useState({
        geofences: true,
        waypoints: true,
        trajectories: true
    });

    // Interactive Navigation & Modal State
    const [isDrawerOpen, setIsDrawerOpen] = useState(true);
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [selectedUserId, setSelectedUserId] = useState(null);
    const [inspectingOfficer, setInspectingOfficer] = useState(null);
    const [activeLightboxPhoto, setActiveLightboxPhoto] = useState(null);
    const [fitBoundsTrigger, setFitBoundsTrigger] = useState(0);
    const [flyToCoords, setFlyToCoords] = useState(null);

    // Live Polling State
    const [isPolling, setIsPolling] = useState(true);
    const [secondsLeft, setSecondsLeft] = useState(AUTO_POLL_INTERVAL_SEC);
    const containerRef = useRef(null);
    const prevUpdateRef = useRef(null);

    // Save preferred tile to localStorage
    const handleTileChange = useCallback((tileId) => {
        setCurrentTileId(tileId);
        localStorage.setItem('guardian_map_tile_id', tileId);
    }, []);

    // Toggle overlay layers
    const handleToggleLayer = useCallback((layerKey) => {
        setLayerVisibility(prev => ({
            ...prev,
            [layerKey]: !prev[layerKey]
        }));
    }, []);

    // Fetch team location data
    const fetchLocationsData = useCallback(async (isSilent = false) => {
        if (!selectedDate) return;

        if (!isSilent) setLoading(true);
        else setIsRefreshing(true);

        try {
            const endpoint = route('getUserLocationsForDate', {
                date: selectedDate.split('T')[0],
                _t: Date.now()
            });

            const response = await fetch(endpoint);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: Failed to fetch user locations`);
            }

            const data = await response.json();
            const locationList = Array.isArray(data.locations) ? data.locations : [];
            const configs = Array.isArray(data.attendance_type_configs) ? data.attendance_type_configs : [];

            setUsers(locationList);
            setAttendanceTypeConfigs(configs);
            setLastUpdate(new Date());
            setSecondsLeft(AUTO_POLL_INTERVAL_SEC);
        } catch (error) {
            console.error('Failed to load team locations:', error);
        } finally {
            setLoading(false);
            setIsRefreshing(false);
        }
    }, [selectedDate]);

    // Initial load and on selectedDate/updateMap changes
    useEffect(() => {
        fetchLocationsData(false);
    }, [selectedDate, updateMap, fetchLocationsData]);

    // Live Polling Ticker
    useEffect(() => {
        if (!isPolling) return;

        const timer = setInterval(() => {
            setSecondsLeft(prev => {
                if (prev <= 1) {
                    fetchLocationsData(true);
                    return AUTO_POLL_INTERVAL_SEC;
                }
                return prev - 1;
            });
        }, 1000);

        return () => clearInterval(timer);
    }, [isPolling, fetchLocationsData]);

    // Derived Statistics
    const stats = useMemo(() => {
        const total = users.length;
        let active = 0;
        let completed = 0;

        users.forEach(u => {
            if (u.status === 'active') active++;
            else completed++;
        });

        return { total, checkedIn: active, active, completed };
    }, [users]);

    // Filtered users based on search and status chip
    const filteredUsers = useMemo(() => {
        return users.filter(u => {
            // Status match
            if (statusFilter === 'active' && u.status !== 'active') return false;
            if (statusFilter === 'completed' && u.status === 'active') return false;

            // Search query match
            if (searchQuery) {
                const q = searchQuery.toLowerCase();
                const matchName = u.name?.toLowerCase().includes(q);
                const matchId = u.employee_id?.toLowerCase().includes(q);
                const matchDesig = u.designation?.toLowerCase().includes(q);
                const matchDept = u.department?.toLowerCase().includes(q);
                if (!matchName && !matchId && !matchDesig && !matchDept) return false;
            }

            return true;
        });
    }, [users, statusFilter, searchQuery]);

    // Format last update time string
    const lastUpdateText = useMemo(() => {
        if (!lastUpdate) return null;
        return lastUpdate.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
    }, [lastUpdate]);

    // Format header date
    const formattedDate = useMemo(() => {
        if (!selectedDate) return 'Invalid Date';
        try {
            return new Date(selectedDate).toLocaleString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        } catch (error) {
            return selectedDate;
        }
    }, [selectedDate]);

    // Handlers for Officer Actions
    const handleSelectOfficer = useCallback((officer) => {
        setSelectedUserId(officer.user_id);
        const loc = officer.punchin_location || officer.punchout_location || officer.location;
        if (loc && loc.lat && loc.lng) {
            setFlyToCoords([parseFloat(loc.lat), parseFloat(loc.lng)]);
        }
    }, []);

    const handleFocusCoords = useCallback((coords) => {
        setFlyToCoords(coords);
    }, []);

    const handleFitBounds = useCallback(() => {
        setFitBoundsTrigger(prev => prev + 1);
    }, []);

    const handleToggleFullscreen = useCallback(() => {
        if (!containerRef.current) return;
        if (!document.fullscreenElement) {
            containerRef.current.requestFullscreen().catch(err => {
                console.warn('Fullscreen error:', err);
            });
            setIsFullscreen(true);
        } else {
            document.exitFullscreen();
            setIsFullscreen(false);
        }
    }, []);

    // Listen for fullscreen change events
    useEffect(() => {
        const handleFullscreenChange = () => {
            setIsFullscreen(!!document.fullscreenElement);
        };
        document.addEventListener('fullscreenchange', handleFullscreenChange);
        return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
    }, []);

    return (
        <Box>
            <Panel mb="4">
                {/* Top Header */}
                <Box p="4" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                    <Flex justify="between" align="center" gap="3" wrap="wrap">
                        <Flex align="center" gap="3">
                            <Box
                                style={{
                                    padding: 10,
                                    borderRadius: 'var(--radius-3)',
                                    background: 'linear-gradient(135deg, var(--blue-a3), var(--blue-a4))',
                                    border: '1px solid var(--blue-a6)',
                                    width: 44,
                                    height: 44,
                                    flexShrink: 0,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    boxShadow: '0 2px 8px rgba(0,0,0,0.06)'
                                }}
                            >
                                <GlobeIcon style={{ color: 'var(--blue-9)', width: 22, height: 22 }} />
                            </Box>
                            <Box>
                                <Heading size="4" style={{ letterSpacing: '-0.02em' }}>
                                    Team Locations & Live GIS Command Center
                                </Heading>
                                <Text size="2" color="gray">{formattedDate}</Text>
                            </Box>
                        </Flex>

                        <Button
                            variant="surface"
                            size="1"
                            color="blue"
                            onClick={() => fetchLocationsData(false)}
                            disabled={loading || isRefreshing}
                            style={{ cursor: 'pointer' }}
                        >
                            <ReloadIcon className={loading || isRefreshing ? 'animate-spin' : ''} />
                            Refresh Live Feed
                        </Button>
                    </Flex>
                </Box>

                {/* KPI Metric Stats Ribbon */}
                <MapStatsRibbon
                    stats={stats}
                    lastUpdateText={lastUpdateText}
                    isPolling={isPolling}
                    secondsLeft={secondsLeft}
                />

                {/* Map Area */}
                <Box p="4">
                    {loading ? (
                        <Flex
                            align="center"
                            justify="center"
                            style={{
                                height: '72vh',
                                border: '1px solid var(--gray-a4)',
                                borderRadius: 'var(--radius-3)',
                                background: 'var(--gray-a2)'
                            }}
                        >
                            <Flex direction="column" align="center" gap="3">
                                <Spinner size="3" />
                                <Text size="2" weight="medium" color="gray">
                                    Loading team coordinates & GIS boundaries...
                                </Text>
                            </Flex>
                        </Flex>
                    ) : users.length === 0 ? (
                        <Flex
                            direction="column"
                            align="center"
                            justify="center"
                            gap="3"
                            p="6"
                            style={{
                                height: '72vh',
                                border: '1px solid var(--gray-a4)',
                                borderRadius: 'var(--radius-3)',
                                background: 'var(--gray-a2)'
                            }}
                        >
                            <GlobeIcon style={{ width: 64, height: 64, color: 'var(--gray-7)' }} />
                            <Heading size="4">No Team Location Records Found</Heading>
                            <Text size="2" color="gray" align="center" style={{ maxWidth: 420 }}>
                                No check-in or patrol coordinates recorded for {formattedDate}.
                                Ensure team members have logged attendance via mobile GPS or check a different date.
                            </Text>
                            <Button variant="outline" onClick={() => fetchLocationsData(false)}>
                                <ReloadIcon /> Refresh Data
                            </Button>
                        </Flex>
                    ) : (
                        <Box
                            ref={containerRef}
                            style={{
                                position: 'relative',
                                height: isFullscreen ? '100vh' : '72vh',
                                borderRadius: isFullscreen ? 0 : 'var(--radius-3)',
                                overflow: 'hidden',
                                border: isFullscreen ? 'none' : '1px solid var(--gray-a5)',
                                boxShadow: '0 8px 30px rgba(0,0,0,0.12)'
                            }}
                        >
                            {/* Floating HUD Controls */}
                            <MapHudControls
                                searchQuery={searchQuery}
                                onSearchChange={setSearchQuery}
                                statusFilter={statusFilter}
                                onStatusFilterChange={setStatusFilter}
                                stats={stats}
                                currentTileId={currentTileId}
                                onTileChange={handleTileChange}
                                layerVisibility={layerVisibility}
                                onToggleLayer={handleToggleLayer}
                                onFitBounds={handleFitBounds}
                                onRefresh={() => fetchLocationsData(true)}
                                isRefreshing={isRefreshing}
                                isDrawerOpen={isDrawerOpen}
                                onToggleDrawer={() => setIsDrawerOpen(prev => !prev)}
                                isFullscreen={isFullscreen}
                                onToggleFullscreen={handleToggleFullscreen}
                            />

                            {/* Core Map View */}
                            <MapContainerView
                                currentTileId={currentTileId}
                                users={filteredUsers}
                                attendanceTypeConfigs={attendanceTypeConfigs}
                                fitBoundsTrigger={fitBoundsTrigger}
                                flyToCoords={flyToCoords}
                            >
                                {/* Geofence Polygons & Waypoints Layer */}
                                <MapGeofenceLayers
                                    attendanceTypeConfigs={attendanceTypeConfigs}
                                    users={filteredUsers}
                                    layerVisibility={layerVisibility}
                                />

                                {/* Living Radar Markers & Trajectories Layer */}
                                <MapLivingMarkers
                                    users={filteredUsers}
                                    selectedUserId={selectedUserId}
                                    onSelectOfficer={handleSelectOfficer}
                                    onOpenTelemetry={setInspectingOfficer}
                                    onOpenPhoto={setActiveLightboxPhoto}
                                    layerVisibility={layerVisibility}
                                />
                            </MapContainerView>

                            {/* Interactive Slide-Over Team Roster Drawer */}
                            <MapTeamRosterDrawer
                                isOpen={isDrawerOpen}
                                onClose={() => setIsDrawerOpen(false)}
                                users={filteredUsers}
                                selectedUserId={selectedUserId}
                                onSelectOfficer={handleSelectOfficer}
                                onOpenTelemetry={setInspectingOfficer}
                                onOpenPhoto={setActiveLightboxPhoto}
                            />
                        </Box>
                    )}
                </Box>
            </Panel>

            {/* Officer Telemetry Detail Modal */}
            {inspectingOfficer && (
                <OfficerDetailModal
                    officer={inspectingOfficer}
                    selectedDate={selectedDate}
                    onClose={() => setInspectingOfficer(null)}
                    onOpenPhoto={setActiveLightboxPhoto}
                    onFocusMap={handleFocusCoords}
                />
            )}

            {/* Fullscreen HD Photo Lightbox with GPS HUD */}
            {activeLightboxPhoto && (
                <PhotoTelemetryLightbox
                    photoData={activeLightboxPhoto}
                    onClose={() => setActiveLightboxPhoto(null)}
                />
            )}
        </Box>
    );
});

UserLocationsCard.displayName = 'UserLocationsCard';
export default UserLocationsCard;