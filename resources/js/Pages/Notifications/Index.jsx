import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import App from '@/Layouts/App';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import TablePagination from '@/Components/TablePagination.jsx';
import {
    useNotificationsList,
    useMarkRead,
    useMarkAllRead,
} from '@/api/queries/useNotificationsQuery';
import {
    Box,
    Flex,
    Text,
    Heading,
    Button,
    Card,
    Badge,
    Spinner,
    Separator,
} from '@radix-ui/themes';
import { BellIcon, CheckCircledIcon } from '@radix-ui/react-icons';

const NotificationsIndex = ({ title }) => {
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);

    const { data, isLoading, isError } = useNotificationsList({ page, per_page: perPage });
    const markReadMutation = useMarkRead();
    const markAllReadMutation = useMarkAllRead();

    const items = data?.data ?? [];
    const apiPagination = data?.pagination ?? { current_page: 1, per_page: perPage, total: 0 };
    const pagination = {
        currentPage: apiPagination.current_page,
        perPage: apiPagination.per_page,
        total: apiPagination.total,
    };

    const unreadTotal = items.filter((n) => !n.read_at).length;

    const handleItemClick = (n) => {
        if (!n.read_at) {
            markReadMutation.mutate(n.id);
        }
        const url = n.data?.url;
        if (url) {
            router.visit(url);
        }
    };

    const handleMarkAllRead = () => {
        markAllReadMutation.mutate();
    };

    return (
        <>
            <Head title={title ?? 'Notifications'} />
            <div className="p-4">
                <ErrorBoundary>
                    <Flex direction="column" gap="4">
                        <Flex align="center" justify="between" wrap="wrap" gap="2">
                            <Flex align="center" gap="2">
                                <BellIcon width={22} height={22} />
                                <Heading size="5">Notifications</Heading>
                                {unreadTotal > 0 && (
                                    <Badge color="red" variant="soft" size="1">{unreadTotal} unread on this page</Badge>
                                )}
                            </Flex>
                            <Button
                                size="2"
                                variant="soft"
                                onClick={handleMarkAllRead}
                                disabled={markAllReadMutation.isPending}
                            >
                                <CheckCircledIcon /> Mark all read
                            </Button>
                        </Flex>

                        <Card>
                            {isLoading && (
                                <Flex justify="center" py="6">
                                    <Spinner size="3" />
                                </Flex>
                            )}

                            {isError && (
                                <Flex justify="center" py="6">
                                    <Text color="red" size="2">Failed to load notifications. Please refresh.</Text>
                                </Flex>
                            )}

                            {!isLoading && !isError && items.length === 0 && (
                                <Flex align="center" justify="center" direction="column" gap="1" py="6">
                                    <BellIcon style={{ width: 20, height: 20, color: 'var(--gray-7)' }} />
                                    <Text size="2" color="gray">All caught up</Text>
                                </Flex>
                            )}

                            {!isLoading && !isError && items.length > 0 && (
                                <Flex direction="column">
                                    {items.map((n, idx) => (
                                        <React.Fragment key={n.id}>
                                            {idx > 0 && <Separator size="4" />}
                                            <Box
                                                role="button"
                                                tabIndex={0}
                                                onClick={() => handleItemClick(n)}
                                                onKeyDown={(e) => e.key === 'Enter' && handleItemClick(n)}
                                                style={{
                                                    padding: '12px 8px',
                                                    cursor: 'pointer',
                                                    opacity: n.read_at ? 0.6 : 1,
                                                    background: n.read_at ? 'transparent' : 'var(--accent-a2)',
                                                    borderRadius: 'var(--radius-2)',
                                                }}
                                            >
                                                <Flex align="start" justify="between" gap="3">
                                                    <Flex direction="column" gap="1" style={{ minWidth: 0, flex: 1 }}>
                                                        <Text size="2" weight={n.read_at ? 'regular' : 'medium'}>
                                                            {n.data?.title || n.data?.message || 'Notification'}
                                                        </Text>
                                                        {n.data?.body && (
                                                            <Text size="1" color="gray">{n.data.body}</Text>
                                                        )}
                                                        {n.created_at && (
                                                            <Text size="1" color="gray">{new Date(n.created_at).toLocaleString()}</Text>
                                                        )}
                                                    </Flex>
                                                    {!n.read_at && (
                                                        <Badge color="red" variant="soft" size="1">New</Badge>
                                                    )}
                                                </Flex>
                                            </Box>
                                        </React.Fragment>
                                    ))}
                                </Flex>
                            )}

                            <TablePagination
                                pagination={pagination}
                                loading={isLoading}
                                onPageChange={setPage}
                                onRowsPerPageChange={(n) => { setPerPage(n); setPage(1); }}
                            />
                        </Card>
                    </Flex>
                </ErrorBoundary>
            </div>
        </>
    );
};

NotificationsIndex.layout = (page) => <App>{page}</App>;
export default NotificationsIndex;
