import React, { useEffect, useRef, useState } from 'react';
import { Box, Card, Flex, Text } from '@radix-ui/themes';
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

    const hand = (angle, len, width, color) => {
        const rad = (angle * Math.PI) / 180;
        return (
            <line
                x1={cx} y1={cy}
                x2={cx + len * Math.cos(rad)}
                y2={cy + len * Math.sin(rad)}
                stroke={color} strokeWidth={width} strokeLinecap="round"
            />
        );
    };

    const ticks = Array.from({ length: 12 }, (_, i) => {
        const a = ((i / 12) * 360 - 90) * (Math.PI / 180);
        const inner = r - 6;
        return (
            <line
                key={i}
                x1={cx + r * Math.cos(a)} y1={cy + r * Math.sin(a)}
                x2={cx + inner * Math.cos(a)} y2={cy + inner * Math.sin(a)}
                stroke="var(--gray-a7)" strokeWidth={i % 3 === 0 ? 2 : 1}
            />
        );
    });

    return (
        <svg width={size} height={size} style={{ flexShrink: 0 }}>
            <circle cx={cx} cy={cy} r={r} fill="var(--color-panel-solid)" stroke="var(--gray-a6)" strokeWidth={1.5} />
            {ticks}
            {hand(hourAngle,   r * 0.48, 3, 'var(--gray-12)')}
            {hand(minuteAngle, r * 0.68, 2, 'var(--gray-12)')}
            {hand(secondAngle, r * 0.76, 1.2, 'var(--accent-9)')}
            <circle cx={cx} cy={cy} r={3} fill="var(--accent-9)" />
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
        <Card style={{ height: '100%' }}>
            <Flex align="center" gap={{ initial: '2', sm: '3', md: '4' }} style={{ height: '100%' }}>
                <AnalogFace time={time} size={clockSize} />
                <Flex direction="column" gap="1" style={{ minWidth: 0, flex: 1 }}>
                    <Text style={{
                        fontFamily: 'monospace',
                        fontSize: isSmall ? 20 : 26,
                        fontWeight: 700,
                        letterSpacing: '-0.02em',
                        lineHeight: 1,
                        color: 'var(--gray-12)',
                    }}>
                        {hh}<span style={{ opacity: 0.4, animation: 'blink 1s step-end infinite' }}>:</span>{mm}
                        <Text size="3" color="gray" style={{ fontFamily: 'monospace', marginLeft: 4 }}>{ss}</Text>
                    </Text>
                    <Text size="1" color="gray" style={{ marginTop: 4 }} truncate>{dateStr}</Text>
                </Flex>
            </Flex>
        </Card>
    );
}
