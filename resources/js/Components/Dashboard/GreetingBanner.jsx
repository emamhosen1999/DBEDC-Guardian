import React, { useMemo } from 'react';
import { Box, Card, Flex, Text } from '@radix-ui/themes';

const GREETINGS = [
    { range: [5,  12], text: 'Good morning',  emoji: '🌅' },
    { range: [12, 17], text: 'Good afternoon', emoji: '☀️' },
    { range: [17, 21], text: 'Good evening',   emoji: '🌇' },
    { range: [21, 24], text: 'Good night',     emoji: '🌙' },
    { range: [0,   5], text: 'Good night',     emoji: '🌙' },
];

function getGreeting(hour) {
    return GREETINGS.find(({ range: [a, b] }) =>
        b <= 24 ? hour >= a && hour < b : hour >= a
    ) ?? GREETINGS[0];
}

export default function GreetingBanner({ user }) {
    const { text, emoji } = useMemo(() => getGreeting(new Date().getHours()), []);

    const firstName = user?.name?.split(' ')?.[0] ?? 'there';

    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
    });

    return (
        <Card style={{ height: '100%', background: 'var(--accent-a3)', border: '1px solid var(--accent-a6)' }}>
            <Flex direction="column" justify="center" gap="1" style={{ height: '100%' }}>
                <Flex align="center" gap="2">
                    <Text style={{ fontSize: 'clamp(16px, 3vw, 26px)', lineHeight: 1 }}>{emoji}</Text>
                    <Text size={{ initial: '4', xs: '5', md: '6' }} weight="bold" style={{ lineHeight: 1.2 }}>
                        {text}, {firstName}!
                    </Text>
                </Flex>
                <Text size={{ initial: '1', sm: '2' }} color="gray" mt="1" truncate>{today}</Text>
            </Flex>
        </Card>
    );
}
