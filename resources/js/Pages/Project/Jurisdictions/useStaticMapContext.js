import { useEffect, useState } from 'react';

/** District boundaries and road context are static, licensed files, loaded on demand so they never weigh on the first paint. */
export function useStaticMapContext() {
    const [ctx, setCtx] = useState({ districts: null, context: null });
    useEffect(() => {
        let alive = true;
        Promise.all([import('@/Components/Cyber/Map/data/districts.json'), import('@/Components/Cyber/Map/data/map-context.json')])
            .then(([d, c]) => { if (alive) setCtx({ districts: d.default, context: c.default }); })
            .catch(() => { /* the map still draws the centreline and the bands without the backdrop */ });
        return () => { alive = false; };
    }, []);
    return ctx;
}
