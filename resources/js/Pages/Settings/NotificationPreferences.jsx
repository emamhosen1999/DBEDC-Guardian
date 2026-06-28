import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { showToast } from '@/utils/toastUtils';
import {
    useNotificationPreferences,
    useSaveNotificationPreferences,
} from '@/api/queries/useNotificationPreferencesQuery';
import {
    Box,
    Flex,
    Text,
    Heading,
    Table,
    Badge,
    Switch,
    Spinner,
} from '@radix-ui/themes';
import { BellIcon } from '@radix-ui/react-icons';

const CHANNELS = [
    { key: 'database', label: 'In-app' },
    { key: 'push', label: 'Push' },
    { key: 'mail', label: 'Email' },
];

/**
 * Build a local preference map: { 'category|channel': enabled }
 * Starts from fetched preferences, falls back to true (default on).
 */
function buildLocalMap(preferences) {
    const map = {};
    (preferences || []).forEach(({ category, channel, enabled }) => {
        map[`${category}|${channel}`] = enabled;
    });
    return map;
}

function CategoryRow({ category, lockedChannels, localMap, onToggle, isPending }) {
    return (
        <Table.Row>
            <Table.Cell>
                <Badge color="blue" variant="soft" style={{ textTransform: 'capitalize' }}>
                    {category}
                </Badge>
            </Table.Cell>

            {CHANNELS.map(({ key, label }) => {
                const isLocked = (lockedChannels || []).includes(key);
                // Locked channels are always on; others default to true if no preference stored
                const mapKey = `${category}|${key}`;
                const isOn = isLocked ? true : (localMap[mapKey] !== undefined ? localMap[mapKey] : true);

                return (
                    <Table.Cell key={key}>
                        <Flex align="center" gap="2">
                            <Switch
                                checked={isOn}
                                disabled={isLocked || isPending}
                                onCheckedChange={(checked) => onToggle(category, key, checked)}
                                aria-label={`${label} notifications for ${category}`}
                            />
                            {isLocked && (
                                <Text size="1" color="gray" title="Required — cannot be disabled">
                                    req
                                </Text>
                            )}
                        </Flex>
                    </Table.Cell>
                );
            })}
        </Table.Row>
    );
}

const NotificationPreferences = ({ title }) => {
    const { data, isLoading, isError } = useNotificationPreferences();
    const saveMutation = useSaveNotificationPreferences();

    // Local toggle state — { 'category|channel': bool }
    const [localMap, setLocalMap] = useState({});

    // Sync to fetched data once loaded
    useEffect(() => {
        if (data?.preferences) {
            setLocalMap(buildLocalMap(data.preferences));
        }
    }, [data?.preferences]);

    const categories = data?.categories ?? {};

    const handleToggle = (category, channel, enabled) => {
        const mapKey = `${category}|${channel}`;
        const nextMap = { ...localMap, [mapKey]: enabled };
        setLocalMap(nextMap);

        // Build payload from ALL current channel states for all known categories
        const preferences = Object.entries(categories).flatMap(([cat, { locked_channels }]) =>
            CHANNELS
                .filter(({ key }) => !(locked_channels || []).includes(key))
                .map(({ key }) => ({
                    category: cat,
                    channel: key,
                    enabled: nextMap[`${cat}|${key}`] !== undefined ? nextMap[`${cat}|${key}`] : true,
                }))
        );

        saveMutation.mutate(preferences, {
            onSuccess: () => showToast.success('Notification preferences saved.'),
            onError: () => {
                // Revert optimistic update
                setLocalMap(localMap);
                showToast.error('Failed to save preferences.');
            },
        });
    };

    return (
        <>
            <Head title={title ?? 'Notification Preferences'} />
            <div className="p-4">
                <ErrorBoundary>
                    <Flex direction="column" gap="4">
                        <Flex align="center" gap="2">
                            <BellIcon width={22} height={22} />
                            <Heading size="5">Notification Preferences</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            Choose which channels deliver notifications to you per category.
                            In-app (database) notifications marked as required cannot be disabled.
                        </Text>

                        {isLoading && (
                            <Flex justify="center" py="6">
                                <Spinner size="3" />
                            </Flex>
                        )}

                        {isError && (
                            <Text color="red" size="2">
                                Failed to load preferences. Please refresh.
                            </Text>
                        )}

                        {!isLoading && !isError && Object.keys(categories).length === 0 && (
                            <Text color="gray" size="2">
                                No notification categories found.
                            </Text>
                        )}

                        {!isLoading && !isError && Object.keys(categories).length > 0 && (
                            <Box>
                                <Table.Root variant="surface" size="1">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Category</Table.ColumnHeaderCell>
                                            {CHANNELS.map(({ key, label }) => (
                                                <Table.ColumnHeaderCell key={key}>{label}</Table.ColumnHeaderCell>
                                            ))}
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {Object.entries(categories).map(([category, { locked_channels }]) => (
                                            <CategoryRow
                                                key={category}
                                                category={category}
                                                lockedChannels={locked_channels}
                                                localMap={localMap}
                                                onToggle={handleToggle}
                                                isPending={saveMutation.isPending}
                                            />
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}
                    </Flex>
                </ErrorBoundary>
            </div>
        </>
    );
};

NotificationPreferences.layout = (page) => <App>{page}</App>;
export default NotificationPreferences;
