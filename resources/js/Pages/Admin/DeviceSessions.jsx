import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Box, Flex, Grid, Text, Heading, Button, Separator, Dialog,
  Select, TextField, Badge, Table, Tooltip, Spinner, IconButton,
} from '@radix-ui/themes';
import {
  MagnifyingGlassIcon, DesktopIcon, MobileIcon, ReloadIcon,
  ExclamationTriangleIcon, CheckCircledIcon, CrossCircledIcon,
  LockClosedIcon, StarFilledIcon, ChevronLeftIcon, ChevronRightIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { format, formatDistanceToNow } from 'date-fns';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { showToast } from '@/utils/toastUtils';

/* ── formatters ── */
const parseDate = (value) => {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const absolute = (value) => {
  const parsed = parseDate(value);
  return parsed ? format(parsed, 'dd MMM yyyy, HH:mm') : '—';
};

const relative = (value) => {
  const parsed = parseDate(value);
  if (!parsed) return 'Never';
  return `${formatDistanceToNow(parsed)} ago`;
};

const isMobilePlatform = (session) => {
  const platform = `${session.platform ?? ''} ${session.device_type ?? ''}`.toLowerCase();
  return ['android', 'ios', 'iphone', 'ipad', 'mobile', 'tablet'].some((token) => platform.includes(token));
};

/* ── header metric ── */
const Metric = ({ label, value, color = 'gray' }) => (
  <Panel tinted>
    <Text as="div" size="1" color="gray">{label}</Text>
    <Heading size="6" color={color === 'gray' ? undefined : color} mt="1">{value}</Heading>
  </Panel>
);

const DeviceSessions = ({ sessions = [], pagination = {}, filters = {}, summary = {} }) => {
  const [search, setSearch] = useState(filters.search ?? '');
  const [status, setStatus] = useState(filters.status ?? 'all');
  const [target, setTarget] = useState(null);
  const [revoking, setRevoking] = useState(false);

  /* Debounced server-side search; keeps the URL as the single source of truth. */
  useEffect(() => {
    if ((filters.search ?? '') === search) return undefined;

    const timer = setTimeout(() => {
      router.get(
        route('admin.device-sessions.index'),
        { search, status },
        { preserveState: true, preserveScroll: true, replace: true },
      );
    }, 350);

    return () => clearTimeout(timer);
  }, [search, status, filters.search]);

  const applyStatus = useCallback((next) => {
    setStatus(next);
    router.get(
      route('admin.device-sessions.index'),
      { search, status: next },
      { preserveState: true, preserveScroll: true, replace: true },
    );
  }, [search]);

  const goToPage = useCallback((page) => {
    router.get(
      route('admin.device-sessions.index'),
      { search, status, page },
      { preserveState: true, preserveScroll: true },
    );
  }, [search, status]);

  const revoke = useCallback(async () => {
    if (!target) return;

    setRevoking(true);

    try {
      const { data } = await axios.post(route('admin.device-sessions.revoke', { device: target.id }));
      showToast.success(data?.message ?? 'Device session revoked.');
      setTarget(null);
      router.reload({ preserveScroll: true });
    } catch (error) {
      showToast.error(
        error?.response?.data?.message ?? 'Failed to revoke this device session.',
      );
    } finally {
      setRevoking(false);
    }
  }, [target]);

  const liveCount = useMemo(
    () => sessions.filter((session) => session.has_live_session).length,
    [sessions],
  );

  const currentPage = pagination.current_page ?? 1;
  const lastPage = pagination.last_page ?? 1;

  return (
    <App>
      <Head title="Device Sessions" />

      <ErrorBoundary>
        <Box p={{ initial: '3', md: '5' }}>
          {/* ── masthead ── */}
          <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
            <Box>
              <Heading size="6" weight="medium">Device Sessions</Heading>
              <Text as="p" size="2" color="gray" mt="1">
                Every registered device across the fleet, the credentials still attached to it,
                and one-click revocation.
              </Text>
            </Box>
            <Button variant="soft" onClick={() => router.reload({ preserveScroll: true })}>
              <ReloadIcon /> Refresh
            </Button>
          </Flex>

          {/* ── fleet counters ── */}
          <Grid columns={{ initial: '2', md: '5' }} gap="3" mb="5">
            <Metric label="Total devices" value={summary.total_devices ?? 0} />
            <Metric label="Active devices" value={summary.active_devices ?? 0} color="green" />
            <Metric label="Inactive" value={summary.inactive_devices ?? 0} />
            <Metric label="Users with devices" value={summary.users_with_devices ?? 0} />
            <Metric label="Active refresh tokens" value={summary.active_refresh_tokens ?? 0} color="blue" />
          </Grid>

          {/* ── controls + table ── */}
          <Panel variant="surface">
            <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
              <Flex align="center" gap="3" wrap="wrap">
                <TextField.Root
                  placeholder="Search user, email, device…"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  style={{ minWidth: 260 }}
                >
                  <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>

                <Select.Root value={status} onValueChange={applyStatus}>
                  <Select.Trigger />
                  <Select.Content>
                    <Select.Item value="all">All devices</Select.Item>
                    <Select.Item value="active">Active only</Select.Item>
                    <Select.Item value="inactive">Inactive only</Select.Item>
                  </Select.Content>
                </Select.Root>
              </Flex>

              <Text size="1" color="gray">
                {liveCount} live {liveCount === 1 ? 'session' : 'sessions'} on this page
              </Text>
            </Flex>

            <Separator size="4" mb="4" />

            <Box style={{ overflowX: 'auto' }}>
              <Table.Root variant="ghost">
                <Table.Header>
                  <Table.Row>
                    <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Credentials</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Last activity</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell align="right">Action</Table.ColumnHeaderCell>
                  </Table.Row>
                </Table.Header>

                <Table.Body>
                  {sessions.length === 0 ? (
                    <Table.Row>
                      <Table.Cell colSpan={6}>
                        <Flex direction="column" align="center" gap="2" py="6">
                          <DesktopIcon width="22" height="22" color="gray" />
                          <Text size="2" color="gray">No device sessions match these filters.</Text>
                        </Flex>
                      </Table.Cell>
                    </Table.Row>
                  ) : sessions.map((session) => (
                    <Table.Row key={session.id}>
                      <Table.Cell>
                        <Text as="div" size="2" weight="medium">{session.user?.name ?? 'Unknown user'}</Text>
                        <Text as="div" size="1" color="gray">{session.user?.email ?? '—'}</Text>
                        {session.user?.single_device_login_enabled ? (
                          <Badge size="1" color="amber" mt="1">
                            <LockClosedIcon /> Single-device
                          </Badge>
                        ) : null}
                      </Table.Cell>

                      <Table.Cell>
                        <Flex align="center" gap="2">
                          {isMobilePlatform(session)
                            ? <MobileIcon color="gray" />
                            : <DesktopIcon color="gray" />}
                          <Box>
                            <Text as="div" size="2">{session.device_name || 'Unnamed device'}</Text>
                            <Text as="div" size="1" color="gray">
                              {[session.platform, session.device_model, session.os_version]
                                .filter(Boolean).join(' • ') || '—'}
                            </Text>
                          </Box>
                        </Flex>
                      </Table.Cell>

                      <Table.Cell>
                        <Flex direction="column" gap="1" align="start">
                          {session.is_active ? (
                            <Badge size="1" color="green"><CheckCircledIcon /> Active</Badge>
                          ) : (
                            <Badge size="1" color="gray"><CrossCircledIcon /> Inactive</Badge>
                          )}
                          {session.is_current_device ? (
                            <Tooltip content="This device holds the user's current active-device binding">
                              <Badge size="1" color="blue"><StarFilledIcon /> Bound</Badge>
                            </Tooltip>
                          ) : null}
                        </Flex>
                      </Table.Cell>

                      <Table.Cell>
                        <Flex direction="column" gap="1" align="start">
                          <Text size="1" color={session.access_tokens_active > 0 ? 'green' : 'gray'}>
                            {session.access_tokens_active} access token(s)
                          </Text>
                          <Text size="1" color={session.refresh_tokens_active > 0 ? 'blue' : 'gray'}>
                            {session.refresh_tokens_active} refresh token(s)
                          </Text>
                        </Flex>
                      </Table.Cell>

                      <Table.Cell>
                        <Tooltip content={absolute(session.last_activity_at ?? session.last_used_at)}>
                          <Text size="2">{relative(session.last_activity_at ?? session.last_used_at)}</Text>
                        </Tooltip>
                      </Table.Cell>

                      <Table.Cell align="right">
                        <Button
                          size="1"
                          color="red"
                          variant="soft"
                          disabled={!session.has_live_session && !session.is_active}
                          onClick={() => setTarget(session)}
                        >
                          Revoke
                        </Button>
                      </Table.Cell>
                    </Table.Row>
                  ))}
                </Table.Body>
              </Table.Root>
            </Box>

            {/* ── pagination ── */}
            <Separator size="4" my="4" />
            <Flex align="center" justify="between" gap="3" wrap="wrap">
              <Text size="1" color="gray">
                {pagination.total ? `Showing ${pagination.from}–${pagination.to} of ${pagination.total}` : 'No results'}
              </Text>
              <Flex align="center" gap="2">
                <IconButton
                  size="1"
                  variant="soft"
                  disabled={currentPage <= 1}
                  onClick={() => goToPage(currentPage - 1)}
                >
                  <ChevronLeftIcon />
                </IconButton>
                <Text size="1">Page {currentPage} of {lastPage}</Text>
                <IconButton
                  size="1"
                  variant="soft"
                  disabled={currentPage >= lastPage}
                  onClick={() => goToPage(currentPage + 1)}
                >
                  <ChevronRightIcon />
                </IconButton>
              </Flex>
            </Flex>
          </Panel>
        </Box>

        {/* ── revoke confirmation ── */}
        <Dialog.Root open={target !== null} onOpenChange={(open) => { if (!open) setTarget(null); }}>
          <Dialog.Content maxWidth="460px">
            <Dialog.Title>
              <Flex align="center" gap="2">
                <ExclamationTriangleIcon color="var(--red-9)" /> Revoke this device session?
              </Flex>
            </Dialog.Title>

            <Dialog.Description size="2" color="gray" mb="3">
              {target?.user?.name} will be signed out on{' '}
              <Text weight="medium">{target?.device_name || 'this device'}</Text> immediately.
            </Dialog.Description>

            <Panel tinted mb="3">
              <Text as="div" size="1" weight="medium" mb="2">This will:</Text>
              <Text as="div" size="1" color="gray">
                • delete {target?.access_tokens_active ?? 0} active access token(s)<br />
                • revoke {target?.refresh_tokens_active ?? 0} refresh token(s) in the chain<br />
                • mark the device inactive
                {target?.is_current_device ? <><br />• release the user's active-device binding</> : null}
              </Text>
            </Panel>

            <Text as="p" size="1" color="gray" mb="3">
              Other devices belonging to this user are not affected.
            </Text>

            <Flex gap="3" justify="end">
              <Dialog.Close>
                <Button variant="soft" color="gray" disabled={revoking}>Cancel</Button>
              </Dialog.Close>
              <Button color="red" onClick={revoke} disabled={revoking}>
                {revoking ? <><Spinner size="1" /> Revoking…</> : 'Revoke session'}
              </Button>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>
      </ErrorBoundary>
    </App>
  );
};

export default DeviceSessions;
