import React from 'react';
import { Head, router } from '@inertiajs/react';
import App from '@/Layouts/App';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import {
    useNotificationsList,
    useUnreadCount,
    useMarkRead,
    useMarkAllRead,
} from '@/api/queries/useNotificationsQuery';
import PageHeader from '@/Components/PageHeader';
import { Badge, Card, Icon, Pagination } from '@/Components/Cyber';

/* Cyber email_inbox composition: mailbox toolbar, one list row per message (sender line, title, two-line
   description), empty / error states in the mailbox-empty-message style, pagination under the list. */
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
                upper
                title="Notifications"
                muted="Inbox"
                chips={[
                    { value: unreadCount, label: 'Unread', tone: unreadCount > 0 ? 'danger' : 'success' },
                    { value: isLoading ? undefined : apiPagination.total, label: 'Total', tone: 'default' },
                ]}
            />
            <ErrorBoundary>
                <Card
                    id="notifications-list"
                    title="All notifications"
                    flush
                    footer={
                        <Pagination
                            pagination={pagination}
                            loading={isLoading}
                            onPageChange={f.setPage}
                            onRowsPerPageChange={f.setPerPage}
                            label="Notifications pagination"
                        />
                    }
                >
                    <div className="cy-mailbox-toolbar">
                        <span className="cy-mailbox-toolbar__text">Mailboxes</span>
                        <span className="cy-mailbox-toolbar__link is-active" aria-current="true">Inbox{unreadTotal > 0 ? ` (${unreadTotal})` : ''}</span>
                        <button
                            type="button"
                            className="cy-mailbox-toolbar__link"
                            onClick={handleMarkAllRead}
                            disabled={markAllReadMutation.isPending}
                        >
                            Mark all read <Icon name="check-all" />
                        </button>
                    </div>

                    {isLoading && (
                        <div className="cy-empty" role="status">
                            <p className="cy-empty__text">Loading notifications…</p>
                        </div>
                    )}

                    {isError && (
                        <div className="cy-empty cy-empty--error" role="alert">
                            <Icon name="exclamation-circle" className="cy-empty__icon" />
                            <p className="cy-empty__title">Failed to load notifications</p>
                            <p className="cy-empty__text">Please refresh the page.</p>
                        </div>
                    )}

                    {!isLoading && !isError && items.length === 0 && (
                        <div className="cy-empty">
                            <Icon name="bell" className="cy-empty__icon" />
                            <p className="cy-empty__title">All caught up</p>
                            <p className="cy-empty__text">New notifications will show up here.</p>
                        </div>
                    )}

                    {!isLoading && !isError && items.length > 0 && (
                        <ul className="cy-mailbox-list" aria-label="Notifications">
                            {items.map((n) => (
                                <li key={n.id}>
                                    <button
                                        type="button"
                                        className={`cy-mailbox-item${n.read_at ? '' : ' is-unread'}`}
                                        onClick={() => handleItemClick(n)}
                                    >
                                        <span className="cy-mailbox-item__sender">
                                            <span className="cy-mailbox-item__name">{n.data?.title || n.data?.message || 'Notification'}</span>
                                            {!n.read_at && <Badge color="danger">New</Badge>}
                                            {n.created_at && <span className="cy-mailbox-item__time">{new Date(n.created_at).toLocaleString()}</span>}
                                        </span>
                                        {n.data?.body && <span className="cy-mailbox-item__desc">{n.data.body}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </ErrorBoundary>
        </>
    );
};

NotificationsIndex.layout = (page) => <App>{page}</App>;
export default NotificationsIndex;
