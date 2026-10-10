import React, { useEffect, useMemo, useRef, useState } from 'react';

import { formatChainage } from './geo.js';

const PAD = 14;

/**
 * The corridor as a K0..K<length> ruler: a km grid, every visible chainage feature as a tick or span in its tone,
 * and a cursor readout. Choosing a tick selects that feature on the map.
 */
export default function ChainageRuler({ alignment, layers, selectedId, onSelect, onFocusChainage }) {
    const box = useRef(null);
    const [w, setW] = useState(0);
    const [cursor, setCursor] = useState(null);
    useEffect(() => {
        const el = box.current;
        if (!el) return undefined;
        const read = () => setW(el.clientWidth);
        read();
        if (typeof ResizeObserver === 'undefined') return undefined;
        const ro = new ResizeObserver(read);
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    const length = alignment?.length_m ?? 0;
    const x = (m) => PAD + (m / Math.max(length, 1)) * Math.max(w - PAD * 2, 1);
    const marks = useMemo(() => layers.flatMap((l) => l.features
        .filter((f) => Number.isFinite(f.chainage_m))
        .map((f) => ({ f, l, from: f.from_m ?? f.chainage_m, to: f.to_m ?? f.chainage_m }))).slice(0, 1500), [layers]);
    if (!length) return null;

    const labelEvery = w < 520 ? 10 : 5;
    const kms = Array.from({ length: Math.floor(length / 1000) + 1 }, (_, i) => i);

    return (
        <div className="cy-ruler" ref={box}>
            <svg
                role="img" aria-label={`Chainage ruler, K0 to ${formatChainage(length)}, ${marks.length} features`}
                viewBox={`0 0 ${Math.max(w, 1)} 44`} width={w} height="44"
                onPointerMove={(e) => {
                    const r = e.currentTarget.getBoundingClientRect();
                    const m = Math.round(((e.clientX - r.left - PAD) / Math.max(w - PAD * 2, 1)) * length);
                    setCursor(m >= 0 && m <= length ? m : null);
                }}
                onPointerLeave={() => setCursor(null)}
                onClick={() => { if (cursor !== null) onFocusChainage?.(cursor); }}
            >
                <line className="cy-ruler__bar" x1={x(0)} x2={x(length)} y1="30" y2="30" />
                {kms.map((k) => (
                    <g key={k}>
                        <line className="cy-ruler__tick" x1={x(k * 1000)} x2={x(k * 1000)} y1="30" y2={k % labelEvery === 0 ? 37 : 34} />
                        {k % labelEvery === 0 && <text className="cy-ruler__label" x={x(k * 1000)} y="43">{`K${k}`}</text>}
                    </g>
                ))}
                {marks.map(({ f, l, from, to }) => (
                    <g key={f.id} data-tone={f.tone}>
                        {to > from
                            ? <line className="cy-ruler__span" x1={x(from)} x2={Math.max(x(Math.min(to, length)), x(from) + 2)} y1="22" y2="22" />
                            : <line className="cy-ruler__mark" x1={x(from)} x2={x(from)} y1={selectedId === f.id ? 6 : 14} y2="28" onClick={(e) => { e.stopPropagation(); onSelect(f, l); }}><title>{`${f.title} - ${formatChainage(from)}`}</title></line>}
                    </g>
                ))}
                {cursor !== null && <line className="cy-ruler__cursor" x1={x(cursor)} x2={x(cursor)} y1="4" y2="30" />}
            </svg>
            <span className="cy-ruler__readout" aria-hidden="true">{cursor !== null ? formatChainage(cursor) : `K0 - ${formatChainage(length)}`}</span>
        </div>
    );
}
