import { Panel } from '@/Components/ui/Panel';
import React, { useEffect, useRef, useState } from 'react';
import { Box, Flex, Text } from '@radix-ui/themes';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';

function AnalogFace({ time, size = 96 }) {
    const cx = size / 2;
    const cy = size / 2;
    const r = size / 2 - 4;

    const h = time.getHours() % 12;
    const m = time.getMinutes();
    const s = time.getSeconds();

    const hourAngle   = ((h + m / 60) / 12) * 360 - 90;
    const minuteAngle = ((m + s / 60) / 60) * 360 - 90;
    const secondAngle = (s / 60) * 360 - 90;

    const ticks = Array.from({ length: 12 }, (_, i) => {
        const a = ((i / 12) * 360 - 90) * (Math.PI / 180);
        const inner = r - 6;
        return (
            <line
                key={i}
                x1={cx + r * Math.cos(a)} y1={cy + r * Math.sin(a)}
                x2={cx + inner * Math.cos(a)} y2={cy + inner * Math.sin(a)}
                stroke={i % 3 === 0 ? 'var(--gray-a8)' : 'var(--gray-a5)'} 
                strokeWidth={i % 3 === 0 ? 2 : 1}
            />
        );
    });

    return (
        <svg width={size} height={size} style={{ flexShrink: 0 }}>
            {/* Outer metallic ring */}
            <circle cx={cx} cy={cy} r={r} fill="var(--color-panel-solid)" stroke="var(--gray-a6)" strokeWidth={1.5} />
            <circle cx={cx} cy={cy} r={r - 2} fill="none" stroke="var(--gray-a3)" strokeWidth={0.5} />
            
            {/* Ticks */}
            {ticks}
            
            {/* Hour hand */}
            <line
                x1={cx} y1={cy}
                x2={cx} y2={cy - r * 0.48}
                stroke="var(--gray-12)" strokeWidth={3} strokeLinecap="round"
                style={{
                    transform: `rotate(${hourAngle + 90}deg)`,
                    transformOrigin: `${cx}px ${cy}px`,
                }}
            />
            {/* Minute hand */}
            <line
                x1={cx} y1={cy}
                x2={cx} y2={cy - r * 0.68}
                stroke="var(--gray-11)" strokeWidth={2} strokeLinecap="round"
                style={{
                    transform: `rotate(${minuteAngle + 90}deg)`,
                    transformOrigin: `${cx}px ${cy}px`,
                }}
            />
            {/* Second hand with mechanical spring bounce effect */}
            <line
                x1={cx} y1={cy}
                x2={cx} y2={cy - r * 0.78}
                stroke="var(--accent-9)" strokeWidth={1.2} strokeLinecap="round"
                style={{
                    transform: `rotate(${secondAngle + 90}deg)`,
                    transformOrigin: `${cx}px ${cy}px`,
                    transition: 'transform 0.2s cubic-bezier(0.18, 0.89, 0.32, 1.28)',
                }}
            />
            {/* Center cap */}
            <circle cx={cx} cy={cy} r={3.5} fill="var(--accent-9)" stroke="var(--color-panel-solid)" strokeWidth={1} />
        </svg>
    );
}

export default function ClockWidget() {
    const [time, setTime] = useState(new Date());
    const rafRef = useRef(null);
    const isSmall = useMediaQuery('(max-width: 640px)');
    const clockSize = isSmall ? 64 : 88;

    useEffect(() => {
        const tick = () => {
            setTime(new Date());
            rafRef.current = setTimeout(tick, 1000);
        };
        rafRef.current = setTimeout(tick, 1000);
        return () => clearTimeout(rafRef.current);
    }, []);

    const pad = (n) => String(n).padStart(2, '0');
    const hh = pad(time.getHours());
    const mm = pad(time.getMinutes());
    const ss = pad(time.getSeconds());

    const dateStr = time.toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
    });

    return (
        <Panel tinted style={{ height: '100%' }}>
            <Flex align="center" gap={{ initial: '2', sm: '3', md: '4' }} style={{ height: '100%' }}>
                <AnalogFace time={time} size={clockSize} />
                <Flex direction="column" gap="0" style={{ minWidth: 0, flex: 1, justifyContent: 'center' }}>
                    <Text style={{
                        fontFamily: 'monospace',
                        fontSize: isSmall ? 20 : 26,
                        fontWeight: 700,
                        letterSpacing: '-0.02em',
                        lineHeight: 1,
                        color: 'var(--gray-12)',
                        fontVariantNumeric: 'tabular-nums',
                    }}>
                        {hh}<span style={{ opacity: 0.4, animation: 'blink 1s step-end infinite' }}>:</span>{mm}
                        <Text size="3" color="gray" style={{ fontFamily: 'monospace', marginLeft: 6, fontVariantNumeric: 'tabular-nums' }}>{ss}</Text>
                    </Text>
                    <Text size="1" color="gray" style={{ marginTop: 6 }} truncate>{dateStr}</Text>
                </Flex>
            </Flex>
        </Panel>
    );
}
