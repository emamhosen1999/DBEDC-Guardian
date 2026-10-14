import React, { useCallback, useMemo, useState } from 'react';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { Card } from '@/Components/Cyber';
import { ChainageRuler, CorridorSvgMap, MapPopup } from '@/Components/Cyber/Map';
import { useStaticMapContext } from './useStaticMapContext.js';

/** The jurisdiction bands on the corridor map: centreline, structures, ruler, in-charge avatars. Hover summarises, a click opens the detail. */
export default function JurisdictionMapCard({ map, coverage, onSelectJurisdiction, asOfLabel }) {
    const ctx = useStaticMapContext();
    const [focus, setFocus] = useState(null);
    const layers = map?.layers ?? [];
    // The popup belongs to what was clicked on the map, not to the list's selection: picking from the list must not open it.
    const [picked, setPicked] = useState(null);
    const featureId = picked;

    const select = useCallback((f) => {
        setPicked(f ? f.id : null);
        if (f?.jurisdiction_id) onSelectJurisdiction(f.jurisdiction_id);
    }, [onSelectJurisdiction]);

    const popup = useCallback(({ feature, layer, close }) => <MapPopup feature={feature} layer={layer} onClose={close} />, []);
    const footer = useMemo(() => (
        <>
            <span className="cy-fresh">{[asOfLabel, map?.alignment?.source?.label].filter(Boolean).join(' · ')}</span>
            <span className="cy-fresh">{coverage.gaps.length || coverage.overlaps.length ? 'Gaps or overlaps are listed under Coverage.' : 'Every metre of K0+000 to K48+999 belongs to exactly one jurisdiction.'}{map?.alignment?.length_m < coverage.corridor.end_m ? ` The measured centreline ends at K${Math.floor(map.alignment.length_m / 1000)}+${String(map.alignment.length_m % 1000).padStart(3, '0')}; the last stretch is not drawn.` : ''}</span>
        </>
    ), [asOfLabel, map, coverage]);

    return (
        <ErrorBoundary>
            <Card id="jurisdictions:map" title="Jurisdiction map" className="jr-map" flush footer={footer}>
                {!map || !map.alignment?.points?.length ? (
                    <p className="jr-empty" role="status">The corridor centreline is not available yet.</p>
                ) : (
                    <>
                        <div className="cy-map-stage">
                            <CorridorSvgMap
                                alignment={map.alignment} layers={layers} groups={map.groups} districts={ctx.districts} context={ctx.context}
                                selectedId={featureId} onSelect={select} renderPopup={popup} focus={focus} label="Jurisdiction map"
                            />
                        </div>
                        <ChainageRuler
                            alignment={map.alignment} layers={layers} selectedId={featureId}
                            onSelect={select}
                            onFocusChainage={(m) => {
                                const pts = map.alignment.points; const i = pts.findIndex((p) => p[2] >= m);
                                if (i > 0) setFocus({ lat: pts[i][0], lng: pts[i][1], z: 6, n: Date.now() });
                            }}
                        />
                    </>
                )}
            </Card>
        </ErrorBoundary>
    );
}
