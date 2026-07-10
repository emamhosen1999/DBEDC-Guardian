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
        <Card style={{ 
            height: '100%', 
            background: 'linear-gradient(135deg, var(--accent-a3) 0%, var(--accent-a1) 100%)', 
            border: '1px solid var(--accent-a5)',
            boxShadow: 'var(--shadow-1)',
            backdropFilter: 'blur(8px)',
        }}>
            <Flex direction="column" justify="center" gap="2" style={{ height: '100%', padding: '4px 0' }}>
                <Flex align="center" gap="3">
                    <Text style={{ fontSize: 'clamp(20px, 3.5vw, 32px)', lineHeight: 1 }}>{emoji}</Text>
                    <Flex direction="column" gap="0">
                        <Text size={{ initial: '4', xs: '5', md: '6' }} weight="bold" style={{ lineHeight: 1.2, letterSpacing: '-0.01em' }}>
                            {text}, {firstName}!
                        </Text>
                        <Text size={{ initial: '1', sm: '2' }} color="gray" style={{ opacity: 0.85 }}>Ready to conquer the day?</Text>
                    </Flex>
                </Flex>
            </Flex>
        </Card>
    );
}
