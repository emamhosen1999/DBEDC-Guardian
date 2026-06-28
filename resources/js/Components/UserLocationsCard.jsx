import React, { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import { MapContainer, TileLayer, Marker, Popup, useMap } from 'react-leaflet';
import {
    Box, Card, Flex, Grid, Text, Heading, Badge, Separator, Spinner, Button,
} from '@radix-ui/themes';
import {
    GlobeIcon,
    ClockIcon,
    PersonIcon,
    DrawingPinIcon,
    ReloadIcon,
    CheckCircledIcon,
    Cross2Icon,
} from '@radix-ui/react-icons';

import 'leaflet/dist/leaflet.css';
import 'leaflet-routing-machine';
import 'leaflet-fullscreen/dist/Leaflet.fullscreen.js';
import 'leaflet-fullscreen/dist/leaflet.fullscreen.css';
import L from 'leaflet';
import { usePage } from "@inertiajs/react";

// --- leaflet-routing-machine safety guard (runs once on import) ---
// When an async OSRM route request resolves AFTER its control was detached (component re-render /
// unmount — e.g. an attendance data refetch), this._map is null and LRM still tries to mutate it:
//   _clearLines()    -> this._map.removeLayer(...)   (clear old polylines)
//   _routeSelected() -> _updateLines() -> this._map.addLayer(...) / fitBounds (draw new route)
// Both throw on a null map. Make them no-ops once the control is detached.
if (L?.Routing?.Control && !L.Routing.Control.prototype.__detachGuarded) {
    const proto = L.Routing.Control.prototype;
    ['_clearLines', '_routeSelected'].forEach((method) => {
        const original = proto[method];
        if (typeof original === 'function') {
            proto[method] = function (...args) {
                if (!this._map) return; // control detached — nothing to draw/clear
                return original.apply(this, args);
            };
        }
    });
    proto.__detachGuarded = true;
}

// --- leaflet core safety guard (runs once on import) ---
// _onZoomTransitionEnd -> _move -> _getMapPanePos reads this._mapPane._leaflet_pos. If the map
// container was removed/hidden (tab switch, component unmount) DURING a zoom animation, _mapPane is
// undefined and it throws an unhandled error after the map is already gone. Skip the settle step.
if (L?.Map?.prototype && !L.Map.prototype.__zoomEndGuarded) {
    const _origZoomTransitionEnd = L.Map.prototype._onZoomTransitionEnd;
    if (typeof _origZoomTransitionEnd === 'function') {
        L.Map.prototype._onZoomTransitionEnd = function (...args) {
            if (!this._mapPane) return; // map removed mid-zoom — nothing to settle
            return _origZoomTransitionEnd.apply(this, args);
        };
    }
    L.Map.prototype.__zoomEndGuarded = true;
}

// Utility function to replace MUI's alpha function
const alpha = (color, opacity) => {
    if (color.startsWith('var(')) {
        // Use CSS variable with opacity via color-mix
        return `color-mix(in srgb, ${color} ${opacity * 100}%, transparent)`;
    }
    if (color.startsWith('#')) {
        const hex = color.replace('#', '');
        const r = parseInt(hex.substr(0, 2), 16);
        const g = parseInt(hex.substr(2, 2), 16);
        const b = parseInt(hex.substr(4, 2), 16);
        return `rgba(${r}, ${g}, ${b}, ${opacity})`;
    }
    return color.replace(/[\d.]+\)$/g, `${opacity})`);
};



// Constants following ISO standards
const MAP_CONFIG = {
    DEFAULT_ZOOM: 12,
    MIN_ZOOM: 8,
    MAX_ZOOM: 19,
    POSITION_THRESHOLD: 0.0001,
    OFFSET_MULTIPLIER: 0.0001,
    MARKER_SIZE: [40, 40],
    POPUP_MAX_WIDTH: 160,
    UPDATE_INTERVAL: 30000 // 30 seconds
};

// Default center if no attendance type configs
const DEFAULT_CENTER = { lat: 23.8103, lng: 90.4125 }; // Dhaka, Bangladesh

// Component to render attendance type boundaries (polygons and routes)
const AttendanceTypeBoundaries = React.memo(({ attendanceTypeConfigs, theme }) => {
    const map = useMap();
    const routingControlsRef = useRef([]);
    
    useEffect(() => {
        if (!map || !attendanceTypeConfigs?.length) return;
        
        // Clear existing boundary layers
        map.eachLayer((layer) => {
            if (layer.options && (layer.options.isAttendanceBoundary || layer.options.isPolygon || layer.options.isRoute)) {
                map.removeLayer(layer);
            }
        });
        
        // Clear existing routing controls
        routingControlsRef.current.forEach(control => {
            try {
                map.removeControl(control);
            } catch (e) {
                console.warn('Error removing routing control:', e);
            }
        });
        routingControlsRef.current = [];
        
        // Remove routing UI elements
        map.getContainer().querySelectorAll('.leaflet-routing-container').forEach(el => el.remove());
        
        const allBounds = [];
        
        attendanceTypeConfigs.forEach((typeConfig, typeIndex) => {
            const { base_slug, config, name } = typeConfig;
            const primaryColor = theme?.customColors?.primary || 'var(--theme-primary, #3b82f6)';
            
            // Generate unique colors for different attendance types
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
            const typeColor = colors[typeIndex % colors.length];
            
            if (base_slug === 'geo_polygon' && config) {
                // Render polygon(s)
                const polygonPoints = config.polygon || [];
                const polygons = config.polygons || [];
                
                // Handle single polygon format
                if (polygonPoints.length >= 3) {
                    const validPoints = polygonPoints.filter(p => p.lat && p.lng);
                    if (validPoints.length >= 3) {
                        const coords = validPoints.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
                        
                        const polygon = L.polygon(coords, {
                            color: typeColor,
                            fillColor: typeColor,
                            fillOpacity: 0.15,
                            weight: 2,
                            opacity: 0.7,
                            isAttendanceBoundary: true,
                            isPolygon: true
                        }).addTo(map);
                        
                        polygon.bindPopup(`
                            <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                <strong style="color: ${typeColor};">${name}</strong><br>
                                <small>Geofence Zone</small><br>
                                <small>Points: ${validPoints.length}</small>
                            </div>
                        `);
                        
                        allBounds.push(polygon.getBounds());
                    }
                }
                
                // Handle multiple polygons format
                polygons.forEach((poly, polyIndex) => {
                    const points = poly.points || [];
                    if (points.length >= 3) {
                        const validPoints = points.filter(p => p.lat && p.lng);
                        if (validPoints.length >= 3) {
                            const coords = validPoints.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
                            
                            const polygon = L.polygon(coords, {
                                color: typeColor,
                                fillColor: typeColor,
                                fillOpacity: 0.15,
                                weight: 2,
                                opacity: 0.7,
                                isAttendanceBoundary: true,
                                isPolygon: true
                            }).addTo(map);
                            
                            polygon.bindPopup(`
                                <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                    <strong style="color: ${typeColor};">${name}</strong><br>
                                    <small>${poly.name || `Zone ${polyIndex + 1}`}</small>
                                </div>
                            `);
                            
                            allBounds.push(polygon.getBounds());
                        }
                    }
                });
            }
            
            if (base_slug === 'route_waypoint' && config) {
                // Render route(s)
                const waypoints = config.waypoints || [];
                const routes = config.routes || [];
                
                // Handle single route format
                if (waypoints.length >= 2) {
                    const validWaypoints = waypoints.filter(w => w.lat && w.lng);
                    if (validWaypoints.length >= 2) {
                        const routeWaypoints = validWaypoints.map(w => 
                            L.latLng(parseFloat(w.lat), parseFloat(w.lng))
                        );
                        
                        // Add waypoint markers
                        validWaypoints.forEach((wp, wpIndex) => {
                            const isFirst = wpIndex === 0;
                            const isLast = wpIndex === validWaypoints.length - 1;
                            
                            const markerHtml = `
                                <div style="
                                    width: 24px;
                                    height: 24px;
                                    border-radius: 50%;
                                    background: ${isFirst ? '#10b981' : isLast ? '#ef4444' : typeColor};
                                    border: 2px solid white;
                                    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    color: white;
                                    font-weight: bold;
                                    font-size: 11px;
                                ">
                                    ${wpIndex + 1}
                                </div>
                            `;
                            
                            const marker = L.marker([parseFloat(wp.lat), parseFloat(wp.lng)], {
                                icon: L.divIcon({
                                    html: markerHtml,
                                    className: 'route-waypoint-marker',
                                    iconSize: [24, 24],
                                    iconAnchor: [12, 12]
                                }),
                                isAttendanceBoundary: true
                            }).addTo(map);
                            
                            marker.bindPopup(`
                                <div style="font-family: var(--fontFamily, 'Inter'); text-align: center; padding: 4px;">
                                    <strong style="color: ${typeColor};">${name}</strong><br>
                                    <small>Waypoint ${wpIndex + 1}${wp.name ? `: ${wp.name}` : ''}</small>
                                </div>
                            `);
                        });
                        
                        // Create route using Leaflet Routing Machine
                        try {
                            const routingControl = L.Routing.control({
                                waypoints: routeWaypoints,
                                routeWhileDragging: false,
                                addWaypoints: false,
                                createMarker: () => null,
                                lineOptions: {
                                    styles: [{
                                        color: typeColor,
                                        weight: 4,
                                        opacity: 0.7,
                                        dashArray: '8, 4'
                                    }],
                                    extendToWaypoints: true,
                                    missingRouteTolerance: 0
                                },
                                show: false,
                                fitSelectedRoutes: false,
                                router: L.Routing.osrmv1({
                                    serviceUrl: 'https://router.project-osrm.org/route/v1'
                                })
                            }).addTo(map);
                            
                            routingControlsRef.current.push(routingControl);
                            
                            // Calculate bounds from waypoints
                            const bounds = L.latLngBounds(routeWaypoints);
                            allBounds.push(bounds);
                        } catch (e) {
                            console.warn('Error creating route:', e);
                            // Fallback: draw simple polyline
                            const polyline = L.polyline(routeWaypoints, {
                                color: typeColor,
                                weight: 3,
                                opacity: 0.6,
                                dashArray: '10, 10',
                                isAttendanceBoundary: true,
                                isRoute: true
                            }).addTo(map);
                            
                            allBounds.push(polyline.getBounds());
                        }
                    }
                }
                
                // Handle multiple routes format
                routes.forEach((routeData, routeIndex) => {
                    const routeWaypoints = routeData.waypoints || [];
                    if (routeWaypoints.length >= 2) {
                        const validWaypoints = routeWaypoints.filter(w => w.lat && w.lng);
                        if (validWaypoints.length >= 2) {
                            const waypts = validWaypoints.map(w => 
                                L.latLng(parseFloat(w.lat), parseFloat(w.lng))
                            );
                            
                            try {
                                const routingControl = L.Routing.control({
                                    waypoints: waypts,
                                    routeWhileDragging: false,
                                    addWaypoints: false,
                                    createMarker: () => null,
                                    lineOptions: {
                                        styles: [{
                                            color: typeColor,
                                            weight: 4,
                                            opacity: 0.7,
                                            dashArray: '8, 4'
                                        }]
                                    },
                                    show: false,
                                    fitSelectedRoutes: false,
                                    router: L.Routing.osrmv1({
                                        serviceUrl: 'https://router.project-osrm.org/route/v1'
                                    })
                                }).addTo(map);
                                
                                routingControlsRef.current.push(routingControl);
                                allBounds.push(L.latLngBounds(waypts));
                            } catch (e) {
                                console.warn('Error creating route:', e);
                            }
                        }
                    }
                });
            }
        });
        
        // Fit map to show all boundaries if we have any
        if (allBounds.length > 0) {
            const combinedBounds = allBounds.reduce((acc, bounds) => {
                return acc ? acc.extend(bounds) : bounds;
            }, null);
            
            if (combinedBounds && combinedBounds.isValid()) {
                setTimeout(() => {
                    try {
                        if (map && map._container) {
                            map.fitBounds(combinedBounds, { 
                                padding: [50, 50],
                                maxZoom: 14
                            });
                        }
                    } catch (e) {
                        // Ignore fitBounds errors if map is destroyed
                    }
                }, 500);
            }
        }
        
        return () => {
            // Cleanup
            routingControlsRef.current.forEach(control => {
                try {
                    if (control && map && map._container) {
                        map.removeControl(control);
                    }
                } catch (e) {
                    // Ignore cleanup errors - routing control may already be null
                }
            });
            routingControlsRef.current = [];
        };
    }, [map, attendanceTypeConfigs, theme]);
    
    return null;
});

AttendanceTypeBoundaries.displayName = 'AttendanceTypeBoundaries';

// Enhanced Routing Machine Component (kept for backward compatibility but typically unused now)
const RoutingMachine = React.memo(({ startLocation, endLocation, theme }) => {
    const map = useMap();

    useEffect(() => {
        if (!map || !startLocation || !endLocation) return;

        const routingControl = L.Routing.control({
            waypoints: [
                L.latLng(startLocation.lat, startLocation.lng),
                L.latLng(endLocation.lat, endLocation.lng)
            ],
            routeWhileDragging: false,
            addWaypoints: false,
            createMarker: () => null, // Hide default markers
            lineOptions: {
                styles: [{
                    color: theme?.customColors?.primary || 'var(--theme-primary, #3b82f6)',
                    weight: 4,
                    opacity: 0.8
                }]
            },
            show: false // Hide turn-by-turn instructions
        }).addTo(map);

        return () => {
            if (map && routingControl && map._container) {
                try {
                    map.removeControl(routingControl);
                } catch (e) {
                    // Ignore cleanup errors
                }
            }
        };
    }, [map, startLocation, endLocation, theme]);

    return null;
});

RoutingMachine.displayName = 'RoutingMachine';


// Enhanced User Markers Component
const UserMarkers = React.memo(({ selectedDate, theme, users }) => {

    const map = useMap();

    // Utility functions
    const getAdjustedPosition = useCallback((position, index) => {
        const offset = MAP_CONFIG.OFFSET_MULTIPLIER * index;
        return {
            lat: position.lat + offset,
            lng: position.lng + offset
        };
    }, []);

    const arePositionsClose = useCallback((pos1, pos2) => {
        return (
            Math.abs(pos1.lat - pos2.lat) < MAP_CONFIG.POSITION_THRESHOLD &&
            Math.abs(pos1.lng - pos2.lng) < MAP_CONFIG.POSITION_THRESHOLD
        );
    }, []);

    const parseLocation = useCallback((locationData) => {
        if (!locationData) return null;
        
        // Handle object format: {lat: 23.8845952, lng: 90.4986624, address: "", timestamp: "..."}
        if (typeof locationData === 'object' && locationData.lat && locationData.lng) {
            const lat = parseFloat(locationData.lat);
            const lng = parseFloat(locationData.lng);
            
            if (isNaN(lat) || isNaN(lng)) return null;
            
            return { lat, lng };
        }
        
        // Handle string format (fallback for legacy data or direct JSON strings)
        if (typeof locationData === 'string') {
            // Try to parse as JSON first
            try {
                const parsed = JSON.parse(locationData);
                if (parsed.lat && parsed.lng) {
                    const lat = parseFloat(parsed.lat);
                    const lng = parseFloat(parsed.lng);
                    
                    if (isNaN(lat) || isNaN(lng)) return null;
                    
                    return { lat, lng };
                }
            } catch (error) {
                // If JSON parsing fails, try comma-separated coordinate format
                const coords = locationData.split(',');
                if (coords.length >= 2) {
                    const lat = parseFloat(coords[0].trim());
                    const lng = parseFloat(coords[1].trim());
                    
                    if (isNaN(lat) || isNaN(lng)) return null;
                    
                    return { lat, lng };
                }
            }
        }
        
        return null;
    }, []);

    const formatTime = useCallback((timeString) => {
        if (!timeString) return 'Not recorded';
        
        try {
            // Handle both time-only and full datetime strings
            let date;
            if (timeString.includes('T')) {
                date = new Date(timeString);
            } else {
                date = new Date(`${selectedDate}T${timeString}`);
            }
            
            if (isNaN(date.getTime())) return 'Invalid time';
            
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        } catch (error) {
            console.warn('Error formatting time:', error);
            return timeString;
        }
    }, [selectedDate]);

    const createUserIcon = useCallback((user, type = 'default') => {
        const primaryColor = 'var(--theme-primary, #3b82f6)';
        const secondaryColor = 'var(--theme-secondary, #8b5cf6)';
        const successColor = 'var(--theme-success, #17C964)';
        const dangerColor = 'var(--theme-danger, #ef4444)';
        
        // Use different colors based on marker type
        let gradientColors;
        let shadowColor;
        let indicator = '';
        
        if (type === 'punchin') {
            gradientColors = `${successColor}, #059669`;
            shadowColor = alpha(successColor, 0.4);
            indicator = `<span style="position: absolute; top: -4px; right: -4px; font-size: 10px;">▶</span>`;
        } else if (type === 'punchout') {
            gradientColors = `${dangerColor}, #dc2626`;
            shadowColor = alpha(dangerColor, 0.4);
            indicator = `<span style="position: absolute; top: -4px; right: -4px; font-size: 10px;">◼</span>`;
        } else {
            gradientColors = `${primaryColor}, ${secondaryColor}`;
            shadowColor = alpha(primaryColor, 0.4);
        }
        
        const iconHtml = `
            <div style="
                position: relative;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: linear-gradient(135deg, ${gradientColors});
                border: 3px solid white;
                box-shadow: 0 4px 12px ${shadowColor};
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                font-size: 14px;
            ">
                ${user.profile_image_url || user.profile_image ? 
                    `<img src="${user.profile_image_url || user.profile_image}" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${user.name?.charAt(0)?.toUpperCase() || '?'}';" />` :
                    user.name?.charAt(0)?.toUpperCase() || '?'
                }
                ${indicator}
            </div>
        `;
        return L.divIcon({
            html: iconHtml,
            className: 'user-marker-icon',
            iconSize: MAP_CONFIG.MARKER_SIZE,
            iconAnchor: [20, 20],
            popupAnchor: [0, -20]
        });
    }, []);

    const createPopupContent = useCallback((user, type = 'combined') => {
        const statusColor = user.punchout_time ? 'var(--theme-success, #17C964)' : 'var(--theme-warning, #F5A524)';
        const primaryColor = 'var(--theme-primary, #3b82f6)';
        const secondaryColor = 'var(--theme-secondary, #8b5cf6)';
        const successColor = 'var(--theme-success, #17C964)';
        const dangerColor = 'var(--theme-danger, #ef4444)';
        const backgroundColor = 'var(--theme-content1, #ffffff)';
        const textPrimary = 'var(--theme-foreground, #1f2937)';
        const textSecondary = 'var(--theme-content3, #6b7280)';
        
        // Determine which photo to show based on popup type
        let photoUrl = null;
        let photoLabel = '';
        if (type === 'punchin' && user.punchin_photo_url) {
            photoUrl = user.punchin_photo_url;
            photoLabel = 'Check In Photo';
        } else if (type === 'punchout' && user.punchout_photo_url) {
            photoUrl = user.punchout_photo_url;
            photoLabel = 'Check Out Photo';
        }
        
        // Build photo section HTML
        const photoSection = photoUrl ? `
            <div style="margin-top: 6px; margin-bottom: 6px; overflow: hidden; border-radius: 4px;">
                <div style="color: ${textSecondary}; font-size: 8px; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px;">
                    ${photoLabel}
                </div>
                <img 
                    src="${photoUrl}" 
                    data-fullscreen-photo="${photoUrl}"
                    style="
                        width: 100%; 
                        height: auto;
                        max-height: 100px;
                        object-fit: contain; 
                        border-radius: 4px;
                        border: 1px solid ${alpha(primaryColor, 0.2)};
                        display: block;
                        cursor: pointer;
                    " 
                    onmouseover="this.style.opacity='0.85'"
                    onmouseout="this.style.opacity='1'"
                    onerror="this.style.display='none';"
                    title="Click to view full screen"
                />
            </div>
        ` : '';
        
        // Customize header based on type
        let typeIndicator = '';
        let headerColor = primaryColor;
        if (type === 'punchin') {
            typeIndicator = `
                <div style="
                    display: inline-block;
                    padding: 1px 4px;
                    background: ${alpha(successColor, 0.1)};
                    color: ${successColor};
                    border-radius: 3px;
                    font-size: 7px;
                    font-weight: 600;
                    margin-left: 4px;
                    border: 1px solid ${alpha(successColor, 0.2)};
                ">CHECK IN</div>
            `;
            headerColor = successColor;
        } else if (type === 'punchout') {
            typeIndicator = `
                <div style="
                    display: inline-block;
                    padding: 1px 4px;
                    background: ${alpha(dangerColor, 0.1)};
                    color: ${dangerColor};
                    border-radius: 3px;
                    font-size: 7px;
                    font-weight: 600;
                    margin-left: 4px;
                    border: 1px solid ${alpha(dangerColor, 0.2)};
                ">CHECK OUT</div>
            `;
            headerColor = dangerColor;
        }
        
        // Build time section based on type
        let timeSection = '';
        if (type === 'punchin') {
            timeSection = `
                <div style="display: flex; align-items: center;">
                    <span style="color: ${successColor}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${textSecondary}; font-size: 9px;">
                        Time: ${formatTime(user.punchin_time)}
                    </span>
                </div>
            `;
        } else if (type === 'punchout') {
            timeSection = `
                <div style="display: flex; align-items: center;">
                    <span style="color: ${dangerColor}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${textSecondary}; font-size: 9px;">
                        Time: ${formatTime(user.punchout_time)}
                    </span>
                </div>
            `;
        } else {
            // Combined view (legacy/fallback)
            timeSection = `
                <div style="display: flex; align-items: center; margin-bottom: 4px;">
                    <span style="color: ${successColor}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${textSecondary}; font-size: 9px;">
                        Check In: ${formatTime(user.punchin_time)}
                    </span>
                </div>
                <div style="display: flex; align-items: center;">
                    <span style="color: ${dangerColor}; margin-right: 4px; font-size: 10px;">📍</span>
                    <span style="color: ${textSecondary}; font-size: 9px;">
                        Check Out: ${formatTime(user.punchout_time)}
                    </span>
                </div>
            `;
        }
        
        return `
            <div style="
                min-width: 140px;
                max-width: 160px;
                padding: 8px;
                background: linear-gradient(135deg, ${alpha(backgroundColor, 0.95)}, ${alpha(headerColor, 0.05)});
                border-radius: 8px;
                border: 1px solid ${alpha(headerColor, 0.2)};
                backdrop-filter: blur(20px);
                overflow: hidden;
            ">
                <div style="display: flex; align-items: center; margin-bottom: 6px;">
                    <div style="
                        width: 22px;
                        height: 22px;
                        min-width: 22px;
                        border-radius: 50%;
                        background: linear-gradient(135deg, ${type === 'punchin' ? successColor : type === 'punchout' ? dangerColor : primaryColor}, ${secondaryColor});
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-weight: bold;
                        font-size: 10px;
                        margin-right: 6px;
                    ">
                        ${user.profile_image_url || user.profile_image ? 
                            `<img src="${user.profile_image_url || user.profile_image}" style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='${user.name?.charAt(0)?.toUpperCase() || '?'}';" />` :
                            user.name?.charAt(0)?.toUpperCase() || '?'
                        }
                    </div>
                    <div style="flex: 1; min-width: 0; overflow: hidden;">
                        <div style="display: flex; align-items: center; flex-wrap: wrap;">
                            <span style="font-weight: 600; color: ${textPrimary}; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70px;">
                                ${user.name || 'Unknown'}
                            </span>
                            ${typeIndicator}
                        </div>
                        <div style="color: ${textSecondary}; font-size: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            ${user.designation || 'No designation'}
                        </div>
                    </div>
                </div>
                ${type === 'combined' ? `
                <div style="
                    display: inline-block;
                    padding: 2px 4px;
                    background: ${alpha(statusColor, 0.1)};
                    color: ${statusColor};
                    border-radius: 4px;
                    font-size: 8px;
                    font-weight: 600;
                    margin-bottom: 6px;
                    border: 1px solid ${alpha(statusColor, 0.2)};
                ">
                    ${user.punchout_time ? '✓ Completed' : '⏱ Active'}
                </div>` : ''}
                ${photoSection}
                <div style="space-y: 4px;">
                    ${timeSection}
                </div>
            </div>
        `;
    }, [formatTime]);

    useEffect(() => {
        if (!map || !users.length) return;

        // Clear existing markers and polylines
        map.eachLayer((layer) => {
            if ((layer instanceof L.Marker && layer.options.userData) ||
                (layer instanceof L.Polyline && layer.options.userRoute)) {
                map.removeLayer(layer);
            }
        });

        const processedPositions = [];
        
        // Helper to adjust position for overlapping
        const getAdjustedForOverlap = (location) => {
            let adjustedPosition = { ...location };
            let attempts = 0;
            const maxAttempts = 10;

            while (attempts < maxAttempts) {
                const isOverlapping = processedPositions.some(pos => 
                    arePositionsClose(adjustedPosition, pos)
                );

                if (!isOverlapping) break;

                adjustedPosition = getAdjustedPosition(location, attempts + 1);
                attempts++;
            }
            
            processedPositions.push(adjustedPosition);
            return adjustedPosition;
        };

        users.forEach((user) => {
            // Check if user has cycles data (new format)
            const cycles = user.cycles || [];
            
            if (cycles.length > 0) {
                // New format: iterate through cycles
                cycles.forEach((cycle, cycleIndex) => {
                    const punchinLocation = parseLocation(cycle.punchin_location);
                    const punchoutLocation = parseLocation(cycle.punchout_location);
                    
                    if (!punchinLocation && !punchoutLocation) return;
                    
                    const isCompleteCycle = punchinLocation && punchoutLocation && cycle.is_complete;
                    
                    // Build cycle data for popup (includes user info + cycle specifics)
                    const cycleData = {
                        ...user,
                        punchin_time: cycle.punchin_time,
                        punchout_time: cycle.punchout_time,
                        punchin_photo_url: cycle.punchin_photo_url,
                        punchout_photo_url: cycle.punchout_photo_url,
                    };
                    
                    if (isCompleteCycle) {
                        // Complete cycle: Show both markers with route line
                        const adjustedPunchin = getAdjustedForOverlap(punchinLocation);
                        const adjustedPunchout = getAdjustedForOverlap(punchoutLocation);
                        
                        // Create punch-in marker (green)
                        const punchinMarker = L.marker([adjustedPunchin.lat, adjustedPunchin.lng], {
                            icon: createUserIcon(user, 'punchin'),
                            userData: true
                        });
                        
                        punchinMarker.bindPopup(createPopupContent(cycleData, 'punchin'), {
                            maxWidth: MAP_CONFIG.POPUP_MAX_WIDTH,
                            className: 'custom-popup'
                        });
                        
                        punchinMarker.addTo(map);
                        
                        // Create punch-out marker (red)
                        const punchoutMarker = L.marker([adjustedPunchout.lat, adjustedPunchout.lng], {
                            icon: createUserIcon(user, 'punchout'),
                            userData: true
                        });
                        
                        punchoutMarker.bindPopup(createPopupContent(cycleData, 'punchout'), {
                            maxWidth: MAP_CONFIG.POPUP_MAX_WIDTH,
                            className: 'custom-popup'
                        });
                        
                        punchoutMarker.addTo(map);
                        
                        // Draw route line from punch-in to punch-out
                        const routeLine = L.polyline(
                            [
                                [adjustedPunchin.lat, adjustedPunchin.lng],
                                [adjustedPunchout.lat, adjustedPunchout.lng]
                            ],
                            {
                                color: 'var(--theme-primary, #3b82f6)',
                                weight: 3,
                                opacity: 0.7,
                                dashArray: '10, 10',
                                userRoute: true
                            }
                        );
                        
                        routeLine.addTo(map);
                    } else {
                        // Incomplete cycle: Only show punch-in marker
                        const location = punchinLocation || punchoutLocation;
                        const adjustedPosition = getAdjustedForOverlap(location);
                        
                        const markerType = punchinLocation ? 'punchin' : 'punchout';
                        const marker = L.marker([adjustedPosition.lat, adjustedPosition.lng], {
                            icon: createUserIcon(user, markerType),
                            userData: true
                        });

                        marker.bindPopup(createPopupContent(cycleData, markerType), {
                            maxWidth: MAP_CONFIG.POPUP_MAX_WIDTH,
                            className: 'custom-popup'
                        });

                        marker.addTo(map);
                    }
                });
            } else {
                // Legacy format: use direct punchin/punchout locations
                const punchinLocation = parseLocation(user.punchin_location);
                const punchoutLocation = parseLocation(user.punchout_location);
                
                if (!punchinLocation && !punchoutLocation) return;
                
                const isCompleteCycle = punchinLocation && punchoutLocation && user.punchout_time;
                
                if (isCompleteCycle) {
                    const adjustedPunchin = getAdjustedForOverlap(punchinLocation);
                    const adjustedPunchout = getAdjustedForOverlap(punchoutLocation);
                    
                    const punchinMarker = L.marker([adjustedPunchin.lat, adjustedPunchin.lng], {
                        icon: createUserIcon(user, 'punchin'),
                        userData: true
                    });
                    
                    punchinMarker.bindPopup(createPopupContent(user, 'punchin'), {
                        maxWidth: MAP_CONFIG.POPUP_MAX_WIDTH,
                        className: 'custom-popup'
                    });
                    
                    punchinMarker.addTo(map);
                    
                    const punchoutMarker = L.marker([adjustedPunchout.lat, adjustedPunchout.lng], {
                        icon: createUserIcon(user, 'punchout'),
                        userData: true
                    });
                    
                    punchoutMarker.bindPopup(createPopupContent(user, 'punchout'), {
                        maxWidth: MAP_CONFIG.POPUP_MAX_WIDTH,
                        className: 'custom-popup'
                    });
                    
                    punchoutMarker.addTo(map);
                    
                    const routeLine = L.polyline(
                        [
                            [adjustedPunchin.lat, adjustedPunchin.lng],
                            [adjustedPunchout.lat, adjustedPunchout.lng]
                        ],
                        {
                            color: 'var(--theme-primary, #3b82f6)',
                            weight: 3,
                            opacity: 0.7,
                            dashArray: '10, 10',
                            userRoute: true
                        }
                    );
                    
                    routeLine.addTo(map);
                } else {
                    const location = punchinLocation || punchoutLocation;
                    const adjustedPosition = getAdjustedForOverlap(location);
                    
                    const markerType = punchinLocation ? 'punchin' : 'punchout';
                    const marker = L.marker([adjustedPosition.lat, adjustedPosition.lng], {
                        icon: createUserIcon(user, markerType),
                        userData: true
                    });

                    marker.bindPopup(createPopupContent(user, markerType), {
                        maxWidth: MAP_CONFIG.POPUP_MAX_WIDTH,
                        className: 'custom-popup'
                    });

                    marker.addTo(map);
                }
            }
        });

    }, [map, users, theme, parseLocation, arePositionsClose, getAdjustedPosition, createUserIcon, createPopupContent]);

    return null;
});

UserMarkers.displayName = 'UserMarkers';

// Memoized user stats calculation
const useUserStats = (users) => {
    return useMemo(() => {
        const userGroups = users.reduce((acc, location) => {
            const userId = location.user_id;
            if (!acc[userId]) acc[userId] = [];
            acc[userId].push(location);
            return acc;
        }, {});

        const uniqueUsers = Object.keys(userGroups);
        const total = uniqueUsers.length;
        let checkedIn = 0;
        let completed = 0;

        uniqueUsers.forEach(userId => {
            const userLocations = userGroups[userId];
            userLocations.sort((a, b) => {
                if (!a.punchin_time) return 1;
                if (!b.punchin_time) return -1;
                return a.punchin_time.localeCompare(b.punchin_time);
            });

            const lastLocation = userLocations[userLocations.length - 1];
            const hasPunchIn = userLocations.some(loc => loc.punchin_time);
            
            if (hasPunchIn) {
                if (lastLocation.punchout_time) {
                    completed++;
                } else {
                    checkedIn++;
                }
            }
        });

        return { checkedIn, completed, total };
    }, [users]);
};

// Main Component
const UserLocationsCard = React.memo(({ updateMap, selectedDate }) => {
    const [users, setUsers] = useState([]);
    const [attendanceTypeConfigs, setAttendanceTypeConfigs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [fullscreenPhotoUrl, setFullscreenPhotoUrl] = useState(null);
    const [loadingInitialized, setLoadingInitialized] = useState(false);
    const [lastUpdate, setLastUpdate] = useState(null);
    const [isPolling, setIsPolling] = useState(true);
    const [mapKey, setMapKey] = useState(0);
    const [lastChecked, setLastChecked] = useState(new Date());
    const prevUsersRef = useRef([]);
    const prevUpdateRef = useRef(null);
    const mapContainerRef = useRef(null);
    
    // Handle click on popup photos for fullscreen view
    useEffect(() => {
        const handlePhotoClick = (e) => {
            const photoUrl = e.target.dataset?.fullscreenPhoto;
            if (photoUrl) {
                setFullscreenPhotoUrl(photoUrl);
            }
        };
        
        // Add event listener to document for delegated click handling
        document.addEventListener('click', handlePhotoClick);
        
        return () => {
            document.removeEventListener('click', handlePhotoClick);
        };
    }, []);
    
    const handleRefresh = useCallback(async () => {
        setLoading(true);
        try {
            const endpoint = route('getUserLocationsForDate', { 
                date: selectedDate,
                _t: Date.now()
            });
            if (!selectedDate) {
                setUsers([]);
                setAttendanceTypeConfigs([]);
                prevUsersRef.current = [];
                setMapKey(prev => prev + 1);
                setLastChecked(new Date());
                setLastUpdate(new Date());
                setLoadingInitialized(true);
                return;
            }
            const response = await fetch(endpoint);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: Failed to refresh user locations`);
            }
            const data = await response.json();
            const locations = Array.isArray(data.locations) ? data.locations : [];
            const typeConfigs = Array.isArray(data.attendance_type_configs) ? data.attendance_type_configs : [];
            setUsers(locations);
            setAttendanceTypeConfigs(typeConfigs);
            prevUsersRef.current = locations;
            setMapKey(prev => prev + 1);
            setLastChecked(new Date());
            setLastUpdate(new Date());
            setLoadingInitialized(true);
        } catch (error) {
            console.error('Error refreshing map:', error);
            setUsers([]);
            setAttendanceTypeConfigs([]);
            prevUsersRef.current = [];
            setLoadingInitialized(true);
        } finally {
            setLoading(false);
        }
    }, [selectedDate]);
    
    // Memoize the formatted date to prevent unnecessary recalculations
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
            return 'Invalid Date';
        }
    }, [selectedDate]);
    
    // Use the memoized user stats
    const userStats = useUserStats(users);

    // Function to check for updates
    const checkForUpdates = useCallback(async () => {
        if (!selectedDate) {
            setLoading(false);
            return;
        }

        try {
            const endpoint = route('check-user-locations-updates', { 
                date: selectedDate.split('T')[0] // Ensure YYYY-MM-DD format
            });
            
            const response = await fetch(endpoint);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: Failed to check for updates`);
            }

            const data = await response.json();
            
            // Only update if we have a new update timestamp
            if (data.success && data.last_updated !== prevUpdateRef.current) {
                if (data.last_updated) {
                    prevUpdateRef.current = data.last_updated;
                    handleRefresh();
                    setLastUpdate(new Date());
                }
            }
            
            setLastChecked(new Date());
        } catch (error) {
            console.error('Error checking for updates:', error);
            setLoading(false); // Ensure loading is set to false in case of error
        }
    }, [selectedDate, handleRefresh]);

    // Initial fetch when selectedDate changes
    useEffect(() => {
        handleRefresh();
    }, [selectedDate, handleRefresh]);

    // Set up polling for updates
    useEffect(() => {
        if (!isPolling) return;

        // Initial check
        checkForUpdates();
        
        // Set up interval for polling (every 5 seconds)
        const intervalId = setInterval(checkForUpdates, 5000);

        // Clean up on unmount or when dependencies change
        return () => clearInterval(intervalId);
    }, [isPolling, checkForUpdates]);

    // Automatically set loading to false when no users are available
    useEffect(() => {
        if (users.length === 0 && loading && loadingInitialized) {
            setLoading(false);
        }
    }, [users, loading, loadingInitialized]);

    // Failsafe to ensure loading stops after a certain time
    useEffect(() => {
        // If loading is true for more than 10 seconds, force it to false
        if (loading) {
            const timeoutId = setTimeout(() => {
                if (loading) {
                    setLoading(false);
                }
            }, 10000); // 10 seconds timeout
            
            return () => clearTimeout(timeoutId);
        }
    }, [loading]);

    // Format the last checked time for display
    const lastCheckedText = useMemo(() => {
        if (!lastChecked) return null;
        return lastChecked.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
    }, [lastChecked]);

    const handleUsersLoad = useCallback((loadedUsers) => {
        // Make sure loadedUsers is an array
        const usersArray = Array.isArray(loadedUsers) ? loadedUsers : [];
        
        // Only update if users have actually changed
        const usersChanged = JSON.stringify(usersArray) !== JSON.stringify(prevUsersRef.current);
        if (usersChanged) {
            setUsers(usersArray);
            prevUsersRef.current = usersArray;
        }
        
        // Mark that loading has been initialized and set loading to false
        setLoadingInitialized(true);
        setLoading(false);
    }, []);

   

    return (
        <Box>
            <Card mb="4">
                {/* Header */}
                <Box p="4" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                    <Flex justify="between" align="center" gap="3" wrap="wrap">
                        <Flex align="center" gap="3">
                            <Box style={{ padding: 10, borderRadius: 'var(--radius-3)', background: 'var(--accent-a3)', border: '1px solid var(--accent-a6)', width: 44, height: 44, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                <GlobeIcon style={{ color: 'var(--accent-9)', width: 22, height: 22 }} />
                            </Box>
                            <Box>
                                <Heading size="4">Team Locations</Heading>
                                <Text size="2" color="gray">{formattedDate}</Text>
                            </Box>
                        </Flex>
                        {lastCheckedText && (
                            <Text size="1" color="gray">Updated: {lastCheckedText}</Text>
                        )}
                    </Flex>
                </Box>

                {/* Stats */}
                <Box p="4" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                    <Grid columns="3" gap="3">
                        <Card variant="surface">
                            <Flex direction="column" align="center" p="3" gap="1">
                                <PersonIcon style={{ color: 'var(--accent-9)', width: 20, height: 20 }} />
                                <Text size="4" weight="bold" color="blue">{userStats.total}</Text>
                                <Text size="1" color="gray">Total</Text>
                            </Flex>
                        </Card>
                        <Card variant="surface">
                            <Flex direction="column" align="center" p="3" gap="1">
                                <ClockIcon style={{ color: 'var(--amber-9)', width: 20, height: 20 }} />
                                <Text size="4" weight="bold" color="amber">{userStats.checkedIn}</Text>
                                <Text size="1" color="gray">Active</Text>
                            </Flex>
                        </Card>
                        <Card variant="surface">
                            <Flex direction="column" align="center" p="3" gap="1">
                                <CheckCircledIcon style={{ color: 'var(--green-9)', width: 20, height: 20 }} />
                                <Text size="4" weight="bold" color="green">{userStats.completed}</Text>
                                <Text size="1" color="gray">Completed</Text>
                            </Flex>
                        </Card>
                    </Grid>
                </Box>

                {/* Map / Loading / Empty */}
                <Box p="4">
                    {users.length > 0 ? (
                        <Box style={{ position: 'relative', height: '70vh', borderRadius: 'var(--radius-3)', overflow: 'hidden', border: '1px solid var(--gray-a4)' }}>
                            {loading && (
                                <Flex align="center" justify="center" style={{ position: 'absolute', inset: 0, background: 'var(--gray-a3)', zIndex: 50 }}>
                                    <Flex direction="column" align="center" gap="2">
                                        <Spinner size="3" />
                                        <Text size="2" color="gray">Loading locations...</Text>
                                    </Flex>
                                </Flex>
                            )}
                            <MapContainer
                                key={`${updateMap}-${mapKey}`}
                                center={[DEFAULT_CENTER.lat, DEFAULT_CENTER.lng]}
                                zoom={MAP_CONFIG.DEFAULT_ZOOM}
                                minZoom={MAP_CONFIG.MIN_ZOOM}
                                maxZoom={MAP_CONFIG.MAX_ZOOM}
                                style={{ height: '100%', width: '100%' }}
                                scrollWheelZoom={true}
                                doubleClickZoom={true}
                                dragging={true}
                                touchZoom={true}
                                fullscreenControl={true}
                                attributionControl={false}
                                zoomControl={false}
                            >
                                <TileLayer
                                    url='https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
                                    maxZoom={MAP_CONFIG.MAX_ZOOM}
                                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                />
                                <AttendanceTypeBoundaries
                                    attendanceTypeConfigs={attendanceTypeConfigs}
                                    theme={null}
                                />
                                <UserMarkers
                                    users={users}
                                    selectedDate={selectedDate}
                                    theme={null}
                                />
                            </MapContainer>
                        </Box>
                    ) : loading ? (
                        <Flex align="center" justify="center" style={{ height: '70vh', border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)' }}>
                            <Flex direction="column" align="center" gap="2">
                                <Spinner size="3" />
                                <Text size="2" color="gray">Loading locations...</Text>
                            </Flex>
                        </Flex>
                    ) : (
                        <Flex direction="column" align="center" justify="center" gap="3" p="6" style={{ height: '70vh', border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)' }}>
                            <GlobeIcon style={{ width: 64, height: 64, color: 'var(--gray-6)' }} />
                            <Heading size="4">No Location Data Available</Heading>
                            <Text size="2" color="gray" align="center">
                                No team location data found for {formattedDate}.{' '}
                                {selectedDate && new Date(selectedDate) > new Date()
                                    ? 'This date is in the future.'
                                    : 'Try selecting a different date or refreshing the data.'}
                            </Text>
                            <Button variant="outline" onClick={handleRefresh}>
                                <Flex align="center" gap="2"><ReloadIcon /> Refresh Data</Flex>
                            </Button>
                        </Flex>
                    )}
                </Box>
            </Card>

            {/* Fullscreen Photo Overlay */}
            {fullscreenPhotoUrl && (
                <Box
                    style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.95)', zIndex: 99999, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                    onClick={() => setFullscreenPhotoUrl(null)}
                >
                    <button
                        style={{ position: 'absolute', top: 24, right: 24, padding: 10, borderRadius: '50%', background: 'rgba(255,255,255,0.15)', border: '2px solid rgba(255,255,255,0.3)', color: 'white', cursor: 'pointer', lineHeight: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                        onClick={(e) => { e.stopPropagation(); setFullscreenPhotoUrl(null); }}
                        aria-label="Close fullscreen"
                    >
                        <Cross2Icon style={{ width: 24, height: 24 }} />
                    </button>
                    <Box
                        style={{ maxWidth: '95vw', maxHeight: '95vh', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16 }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <img
                            src={fullscreenPhotoUrl}
                            alt="Attendance photo"
                            style={{ maxWidth: '90vw', maxHeight: '85vh', objectFit: 'contain', borderRadius: 'var(--radius-3)' }}
                        />
                        <Text size="2" style={{ color: 'rgba(255,255,255,0.7)' }}>Click anywhere to close</Text>
                    </Box>
                </Box>
            )}
        </Box>
    );
});

export default UserLocationsCard;