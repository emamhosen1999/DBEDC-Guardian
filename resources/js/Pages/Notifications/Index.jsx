import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import App from '@/Layouts/App';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import TablePagination from '@/Components/TablePagination.jsx';
import {
    useNotificationsList,
    useUnreadCount,
    useMarkRead,
    useMarkAllRead,
} from '@/api/queries/useNotificationsQuery';
import PageHeader from '@/Components/PageHeader';
import { Box, Flex, Text, Button, Badge, Spinner } from '@radix-ui/themes';
import { BellIcon, CheckCircledIcon } from '@radix-ui/react-icons';

const NotificationsIndex = ({ title }) => {
    /* Pagination is server state, so it lives in the URL: a refresh or a copied
       link opens the same page of notifications. Rows come from react-query, so
       paging updates the URL client-side without a server round trip. */
    const f = useQueryFilters({
        mode: 'client',
        defaults: { page: 1, per_page: 20 },
        debounceKeys: [],
    });
    const page = f.values.page;
    const perPage = f.values.per_page;

    const { data, isLoading, isError } = useNotificationsList({ page, per_page: perPage });
    // Same query (and cache) as the header bell.
    const { data: unreadCount } = useUnreadCount();
    const markReadMutation = useMarkRead();
    const markAllReadMutation = useMarkAllRead();
    useClampPage(f, data?.pagination?.last_page);

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
            <PageHeader
                title="Notifications"
                subtitle={unreadTotal > 0 ? `${unreadTotal} unread on this page` : 'All caught up'}
                chips={[
                    { value: unreadCount, label: 'Unread', tone: unreadCount > 0 ? 'danger' : 'success' },
                    { value: isLoading ? undefined : apiPagination.total, label: 'Total', tone: 'default' },
                ]}
                actions={
                    <Button size="2" variant="outline" onClick={handleMarkAllRead} disabled={markAllReadMutation.isPending}>
                        <CheckCircledIcon /> Mark all read
                    </Button>
                }
            />
            <ErrorBoundary>
                <section className="dl-card dl-card--page" aria-labelledby="notifications-list-title">
                        <header className="dl-card__header">
                            <h2 className="dl-card__title" id="notifications-list-title">All notifications</h2>
                            <span className="dl-hud-line" aria-hidden="true" />
                        </header>

                        {isLoading && (
                            <Flex justify="center" py="6">
                                <Spinner size="3" />
                            </Flex>
                        )}

                        {isError && (
                            <Flex justify="center" py="6">
                                <Text color="red" size="2" role="alert">Failed to load notifications. Please refresh.</Text>
                            </Flex>
                        )}

                        {!isLoading && !isError && items.length === 0 && (
                            <Flex align="center" justify="center" direction="column" gap="1" py="6">
                                <BellIcon style={{ width: 20, height: 20, color: 'var(--gray-a9)' }} aria-hidden="true" />
                                <Text size="2" color="gray">All caught up</Text>
                            </Flex>
                        )}

                        {!isLoading && !isError && items.length > 0 && (
                            <ul className="dl-list" aria-label="Notifications">
                                {items.map((n) => (
                                    <li key={n.id}>
                                        <button
                                            type="button"
                                            className={`dl-list__item${n.read_at ? '' : ' dl-list__item--unread'}`}
                                            onClick={() => handleItemClick(n)}
                                        >
                                            <Flex align="start" justify="between" gap="3">
                                                <Flex direction="column" gap="1" style={{ minWidth: 0, flex: 1 }}>
                                                    <Text size="2" weight={n.read_at ? 'regular' : 'bold'}>
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
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <Box px="4" py="2" className="dl-card__foot">
                            <TablePagination
                                pagination={pagination}
                                loading={isLoading}
                                onPageChange={f.setPage}
                                onRowsPerPageChange={f.setPerPage}
                            />
                        </Box>
                </section>
            </ErrorBoundary>
        </>
    );
};

NotificationsIndex.layout = (page) => <App>{page}</App>;
export default NotificationsIndex;
