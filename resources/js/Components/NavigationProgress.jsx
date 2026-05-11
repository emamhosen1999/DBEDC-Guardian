import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

const STYLE = `
@keyframes nav-progress-crawl {
    0%   { width: 0% }
    20%  { width: 25% }
    50%  { width: 55% }
    75%  { width: 72% }
    100% { width: 82% }
}
@keyframes nav-progress-shimmer {
    0%   { transform: translateX(-100%) }
    100% { transform: translateX(400%) }
}
`;

export default function NavigationProgress() {
    const [state, setState] = useState({ visible: false, progress: null });
    const startTimer  = useRef(null);
    const finishTimer = useRef(null);

    useEffect(() => {
        const onStart = router.on('start', () => {
            clearTimeout(startTimer.current);
            clearTimeout(finishTimer.current);
            startTimer.current = setTimeout(() => {
                setState({ visible: true, progress: null });
            }, 80);
        });

        const onProgress = router.on('progress', (e) => {
            const pct = e.detail?.progress?.percentage;
            if (pct != null) {
                setState({ visible: true, progress: Math.min(Math.round(pct * 0.9), 85) });
            }
        });

        const onFinish = router.on('finish', () => {
            clearTimeout(startTimer.current);
            setState({ visible: true, progress: 100 });
            finishTimer.current = setTimeout(() => {
                setState({ visible: false, progress: null });
            }, 450);
        });

        return () => { onStart(); onProgress(); onFinish(); };
    }, []);

    const isIndeterminate = state.visible && state.progress === null;
    const isDone          = state.progress === 100;

    return (
        <>
            <style>{STYLE}</style>
            <div
                aria-hidden="true"
                style={{
                    position:      'fixed',
                    top:           0,
                    left:          0,
                    right:         0,
                    height:        2,
                    zIndex:        99999,
                    pointerEvents: 'none',
                    opacity:       state.visible ? 1 : 0,
                    transition:    'opacity 300ms ease',
                    background:    'var(--gray-a3)',
                    overflow:      'hidden',
                }}
            >
                {/* Bar */}
                <div
                    style={{
                        position:   'absolute',
                        inset:      0,
                        background: 'var(--accent-9)',
                        transformOrigin: 'left',
                        ...(isIndeterminate ? {
                            animation: 'nav-progress-crawl 6s ease-out forwards',
                        } : {
                            width:      `${state.progress ?? 0}%`,
                            transition: isDone
                                ? 'width 180ms ease-out'
                                : 'width 350ms ease-in-out',
                        }),
                    }}
                />
                {/* Shimmer highlight on the bar */}
                {isIndeterminate && (
                    <div
                        style={{
                            position:   'absolute',
                            top:        0,
                            bottom:     0,
                            width:      '30%',
                            background: 'linear-gradient(90deg, transparent, var(--accent-11), transparent)',
                            opacity:    0.6,
                            animation:  'nav-progress-shimmer 1.6s ease-in-out infinite',
                        }}
                    />
                )}
            </div>
        </>
    );
}
