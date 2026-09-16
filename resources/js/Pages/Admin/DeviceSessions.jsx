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
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';
import { Panel } from '@/Components/ui/Panel';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { showToast } from '@/utils/toastUtils';
import StatsCards from '@/Components/StatsCards';
import SearchFilterBar from '@/Components/SearchFilterBar';

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
  <Panel tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '16px 14px', background: 'var(--aero-surface, var(--color-background))' }}>
    <Text as="div" size="1" weight="bold" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontSize: 11, letterSpacing: '0.05em', textTransform: 'uppercase' }}>{label}</Text>
    <Heading size="6" color={color === 'gray' ? undefined : color} mt="1" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums', fontWeight: 800 }}>{value}</Heading>
  </Panel>
);

// `filters` still arrives from the controller but is not read here: the URL is
// the source of truth and useQueryFilters reads it directly.
const DeviceSessions = ({ sessions = [], pagination = {}, summary = {} }) => {
  /* Search, status and page are server state, so they live in the URL. */
  const f = useQueryFilters({
    routeName: 'admin.device-sessions.index',
    defaults: { search: '', status: 'all', page: 1 },
  });

  const search = f.draft.search;
  const setSearch = useCallback((value) => f.setDraft('search', value), [f.setDraft]);
  const status = f.values.status;
  const applyStatus = useCallback((next) => f.set('status', next), [f.set]);
  const goToPage = useCallback((page) => f.setPage(page), [f.setPage]);

  const [target, setTarget] = useState(null);
  const [revoking, setRevoking] = useState(false);

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
  useClampPage(f, pagination.last_page);

  return (
    <App>
      <Head title="Device Sessions" />

      <ErrorBoundary>
        <Flex justify="center" p="4">
          <Box style={{ width: '100%', maxWidth: 2000 }}>
            <Panel>
              {/* ── Page Header ── */}
              <Box mb="4">
                <Flex align={{ initial: 'start', sm: 'center' }} justify="between" gap="4" wrap="wrap">
                  <Flex align="center" gap="3">
                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      <DesktopIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                    </Box>
                    <Box>
                      <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Fleet Device Sessions</Heading>
                      <Text as="p" size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                        Every registered device across the fleet, the credentials still attached to it, and one-click revocation.
                      </Text>
                    </Box>
                  </Flex>
                  <Button variant="soft" color="gray" onClick={() => router.reload({ preserveScroll: true })} style={{ borderRadius: 10 }}>
                    <ReloadIcon /> Refresh
                  </Button>
                </Flex>
              </Box>

              <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

              {/* ── fleet counters ── */}
              <StatsCards
                stats={[
                  { key: 'total', title: 'Total devices', value: summary.total_devices ?? 0, color: 'gray' },
                  { key: 'active', title: 'Active devices', value: summary.active_devices ?? 0, color: 'green' },
                  { key: 'inactive', title: 'Inactive', value: summary.inactive_devices ?? 0, color: 'amber' },
                  { key: 'users', title: 'Users with devices', value: summary.users_with_devices ?? 0, color: 'violet' },
                  { key: 'tokens', title: 'Active refresh tokens', value: summary.active_refresh_tokens ?? 0, color: 'blue' },
                ]}
                columns={{ initial: '2', sm: '3', md: '5' }}
                mb="4"
              />

              {/* ── controls + table ── */}
              <Box mb="4">
                <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
                  <Flex align="center" gap="3" wrap="wrap">
                    <SearchFilterBar
                      searchValue={search}
                      onSearchChange={setSearch}
                      searchPlaceholder="Search user, email, device…"
                      mb="0"
                    />

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

                <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                  <Table.Root size="2" style={{ minWidth: 720, width: '100%' }}>
                    <Table.Header style={{
                      position: 'sticky',
                      top: 0,
                      zIndex: 2,
                      background: 'var(--aero-surface, var(--color-background))',
                      backdropFilter: 'blur(8px)',
                      boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                    }}>
                      <Table.Row>
                        <Table.ColumnHeaderCell style={{ minWidth: 160, background: 'inherit' }}>User</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell style={{ minWidth: 180, background: 'inherit' }}>Device</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell style={{ minWidth: 110, background: 'inherit' }}>Status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell style={{ minWidth: 160, background: 'inherit' }}>Credentials</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell style={{ minWidth: 140, background: 'inherit' }}>Last activity</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell align="right" style={{ minWidth: 90, background: 'inherit' }}>Action</Table.ColumnHeaderCell>
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
                  aria-label="Previous page"
                  onClick={() => goToPage(currentPage - 1)}
                >
                  <ChevronLeftIcon />
                </IconButton>
                <Text size="1">Page {currentPage} of {lastPage}</Text>
                <IconButton
                  size="1"
                  variant="soft"
                  disabled={currentPage >= lastPage}
                  aria-label="Next page"
                  onClick={() => goToPage(currentPage + 1)}
                >
                  <ChevronRightIcon />
                </IconButton>
              </Flex>
            </Flex>
          </Box>
        </Panel>
      </Box>
    </Flex>

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
