import React, { useCallback, useRef, useState } from 'react';

const CORNER_NAMES = ['top left', 'top right', 'bottom right', 'bottom left'];

/**
 * Four-corner editor over a photo (the document scanner's "Adjust corners"). Corners are in the photo's own pixels, ordered
 * top-left, top-right, bottom-right, bottom-left; the handles sit in percentages so the editor scales with its container.
 * Pointer drag moves a handle, arrow keys nudge a focused one (Shift = bigger steps). `onChange(corners)`.
 */
export default function CornerEditor({ src, corners, onChange, alt = 'Photo of the document' }) {
    const stage = useRef(null);
    const drag = useRef(null);
    const [size, setSize] = useState({ w: 0, h: 0 });

    const move = useCallback((index, clientX, clientY) => {
        const r = stage.current.getBoundingClientRect();
        const x = Math.min(size.w, Math.max(0, ((clientX - r.left) / r.width) * size.w));
        const y = Math.min(size.h, Math.max(0, ((clientY - r.top) / r.height) * size.h));
        onChange(corners.map((c, i) => (i === index ? [Math.round(x), Math.round(y)] : c)));
    }, [corners, onChange, size]);

    const nudge = (index, e) => {
        const step = e.shiftKey ? 20 : 4;
        const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
        if (!d) return;
        e.preventDefault();
        const c = corners[index];
        onChange(corners.map((p, i) => (i === index ? [Math.min(size.w, Math.max(0, c[0] + d[0])), Math.min(size.h, Math.max(0, c[1] + d[1]))] : p)));
    };

    return (
        <div className="cy-corners" ref={stage}
            onPointerMove={(e) => { if (drag.current !== null) move(drag.current, e.clientX, e.clientY); }}
            onPointerUp={() => { drag.current = null; }}
            onPointerCancel={() => { drag.current = null; }}>
            <img className="cy-corners__img" src={src} alt={alt} draggable={false} onLoad={(e) => setSize({ w: e.currentTarget.naturalWidth, h: e.currentTarget.naturalHeight })} />
            {size.w > 0 && (
                <>
                    <svg className="cy-corners__outline" viewBox={`0 0 ${size.w} ${size.h}`} preserveAspectRatio="none" aria-hidden="true">
                        <polygon points={corners.map((c) => c.join(',')).join(' ')} vectorEffect="non-scaling-stroke" />
                    </svg>
                    {corners.map((c, i) => (
                        <button key={i} type="button" className="cy-corners__handle" data-conf={i === 0 ? 'corner-handle' : undefined}
                            style={{ left: `${(c[0] / size.w) * 100}%`, top: `${(c[1] / size.h) * 100}%` }}
                            aria-label={`Move the ${CORNER_NAMES[i]} corner`}
                            onPointerDown={(e) => { e.currentTarget.setPointerCapture(e.pointerId); drag.current = i; }}
                            onKeyDown={(e) => nudge(i, e)} />
                    ))}
                </>
            )}
        </div>
    );
}
