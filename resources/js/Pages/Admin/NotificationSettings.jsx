import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { showToast } from '@/utils/toastUtils';
import {
    useNotificationTypes,
    useUpdateNotificationType,
} from '@/api/queries/useNotificationSettingsQuery';
import {
    Box,
    Flex,
    Text,
    Heading,
    Table,
    Badge,
    Switch,
    Checkbox,
    Spinner,
} from '@radix-ui/themes';
import { BellIcon } from '@radix-ui/react-icons';

const CHANNELS = [
    { key: 'database', label: 'In-app' },
    { key: 'push', label: 'Push' },
    { key: 'mail', label: 'Email' },
];

const ALL_ROLES = ['Employee', 'Manager', 'Super Administrator', 'Administrator', 'HR Manager'];

function NotificationTypeRow({ type }) {
    const [localType, setLocalType] = useState(type);
    const updateMutation = useUpdateNotificationType();

    const save = (patch) => {
        const next = { ...localType, ...patch };
        setLocalType(next);
        updateMutation.mutate(
            {
                id: next.id,
                default_channels: next.default_channels,
                locked_channels: next.locked_channels,
                recipient_roles: next.recipient_roles,
                is_active: next.is_active,
            },
            {
                onSuccess: () => showToast.success(`${next.label} updated.`),
                onError: () => {
                    setLocalType(localType);
                    showToast.error('Failed to save changes.');
                },
            }
        );
    };

    const toggleChannel = (channelKey) => {
        const isLocked = (localType.locked_channels || []).includes(channelKey);
        if (isLocked) return; // locked channels cannot be toggled off
        const current = localType.default_channels || [];
        const next = current.includes(channelKey)
            ? current.filter((c) => c !== channelKey)
            : [...current, channelKey];
        save({ default_channels: next });
    };

    const toggleRole = (role) => {
        const current = localType.recipient_roles || [];
        const next = current.includes(role)
            ? current.filter((r) => r !== role)
            : [...current, role];
        save({ recipient_roles: next });
    };

    return (
        <Table.Row>
            <Table.Cell>
                <Flex direction="column" gap="1">
                    <Text size="2" weight="medium">{localType.label}</Text>
                    {localType.description && (
                        <Text size="1" color="gray">{localType.description}</Text>
                    )}
                </Flex>
            </Table.Cell>

            {CHANNELS.map(({ key, label }) => {
                const isLocked = (localType.locked_channels || []).includes(key);
                const isChecked = isLocked || (localType.default_channels || []).includes(key);
                return (
                    <Table.Cell key={key}>
                        <Flex align="center" gap="1">
                            <Checkbox
                                checked={isChecked}
                                disabled={isLocked || updateMutation.isPending}
                                onCheckedChange={() => toggleChannel(key)}
                            />
                            {isLocked && (
                                <Text size="1" color="gray" title="Required channel">(req)</Text>
                            )}
                        </Flex>
                    </Table.Cell>
                );
            })}

            <Table.Cell>
                <Flex direction="column" gap="1">
                    {ALL_ROLES.map((role) => (
                        <Flex key={role} align="center" gap="1">
                            <Checkbox
                                checked={(localType.recipient_roles || []).includes(role)}
                                disabled={updateMutation.isPending}
                                onCheckedChange={() => toggleRole(role)}
                            />
                            <Text size="1">{role}</Text>
                        </Flex>
                    ))}
                </Flex>
            </Table.Cell>

            <Table.Cell>
                <Switch
                    checked={localType.is_active}
                    disabled={updateMutation.isPending}
                    onCheckedChange={(checked) => save({ is_active: checked })}
                />
            </Table.Cell>
        </Table.Row>
    );
}

function CategorySection({ category, types }) {
    return (
        <Box mb="5">
            <Flex align="center" gap="2" mb="2">
                <Badge color="blue" variant="soft" style={{ textTransform: 'capitalize' }}>
                    {category}
                </Badge>
            </Flex>
            <Table.Root variant="surface" size="1">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell style={{ minWidth: 200 }}>Notification Type</Table.ColumnHeaderCell>
                        {CHANNELS.map(({ key, label }) => (
                            <Table.ColumnHeaderCell key={key}>{label}</Table.ColumnHeaderCell>
                        ))}
                        <Table.ColumnHeaderCell style={{ minWidth: 150 }}>Recipients</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Active</Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {types.map((type) => (
                        <NotificationTypeRow key={type.id} type={type} />
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}

const NotificationSettings = ({ title }) => {
    const { data, isLoading, isError } = useNotificationTypes();

    const types = data?.data ?? [];

    const grouped = types.reduce((acc, type) => {
        const cat = type.category || 'general';
        if (!acc[cat]) acc[cat] = [];
        acc[cat].push(type);
        return acc;
    }, {});

    return (
        <>
            <Head title={title ?? 'Notification Settings'} />
            <div className="p-4">
                <ErrorBoundary>
                    <Flex direction="column" gap="4">
                        <Flex align="center" gap="2">
                            <BellIcon width={22} height={22} />
                            <Heading size="5">Notification Settings</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            Configure which channels are active for each notification type and which roles receive them.
                            In-app (database) notifications marked as required cannot be disabled.
                        </Text>

                        {isLoading && (
                            <Flex justify="center" py="6">
                                <Spinner size="3" />
                            </Flex>
                        )}

                        {isError && (
                            <Text color="red" size="2">Failed to load notification types. Please refresh.</Text>
                        )}

                        {!isLoading && !isError && Object.keys(grouped).length === 0 && (
                            <Text color="gray" size="2">No notification types found. Run the NotificationTypeSeeder first.</Text>
                        )}

                        {!isLoading && !isError && Object.entries(grouped).map(([category, catTypes]) => (
                            <CategorySection key={category} category={category} types={catTypes} />
                        ))}
                    </Flex>
                </ErrorBoundary>
            </div>
        </>
    );
};

NotificationSettings.layout = (page) => <App>{page}</App>;
export default NotificationSettings;
