import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Box, Flex, Grid, Text, Heading, Button, Separator, Dialog,
  Select, TextField, Badge, Table, Tooltip, Spinner, IconButton, Code,
} from '@radix-ui/themes';
import {
  MagnifyingGlassIcon, ReloadIcon, ExclamationTriangleIcon,
  CheckCircledIcon, CounterClockwiseClockIcon, MobileIcon,
  ChevronLeftIcon, ChevronRightIcon, Cross2Icon,
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

const SEVERITY_COLOR = { fatal: 'red', error: 'orange', warning: 'amber' };

/* ── header metric ── */
const Metric = ({ label, value, color = 'gray' }) => (
  <Panel tinted>
    <Text as="div" size="1" color="gray">{label}</Text>
    <Heading size="6" color={color === 'gray' ? undefined : color} mt="1">{value}</Heading>
  </Panel>
);

/* ── one labelled fact in the detail drawer ── */
const Fact = ({ label, value }) => (
  <Box>
    <Text as="div" size="1" color="gray">{label}</Text>
    <Text as="div" size="2">{value || '—'}</Text>
  </Box>
);

/* ── platform tally, e.g. "android 9 · ios 3" ── */
const PlatformBreakdown = ({ counts = {} }) => {
  const entries = Object.entries(counts || {});
  if (entries.length === 0) return <Text size="1" color="gray">—</Text>;

  return (
    <Flex gap="1" wrap="wrap">
      {entries.map(([platform, n]) => (
        <Badge key={platform} size="1" variant="soft" color="gray">{platform} {n}</Badge>
      ))}
    </Flex>
  );
};

const ClientErrors = ({
  errors = [], pagination = {}, filters = {}, options = {}, summary = {}, can = {},
}) => {
  const [search, setSearch] = useState(filters.search ?? '');
  const [detail, setDetail] = useState(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [resolvingId, setResolvingId] = useState(null);

  /* The URL is the single source of truth for every filter. */
  const query = useCallback((overrides = {}) => ({
    search,
    status: filters.status ?? 'unresolved',
    severity: filters.severity ?? 'all',
    platform: filters.platform ?? '',
    app_version: filters.app_version ?? '',
    screen: filters.screen ?? '',
    from: filters.from ?? '',
    to: filters.to ?? '',
    ...overrides,
  }), [search, filters]);

  const apply = useCallback((overrides) => {
    router.get(route('admin.client-errors.index'), query(overrides), {
      preserveState: true, preserveScroll: true, replace: true,
    });
  }, [query]);

  /* Debounced server-side search. */
  useEffect(() => {
    if ((filters.search ?? '') === search) return undefined;

    const timer = setTimeout(() => apply({ search }), 350);

    return () => clearTimeout(timer);
  }, [search, filters.search, apply]);

  const goToPage = useCallback((page) => {
    router.get(route('admin.client-errors.index'), query({ page }), {
      preserveState: true, preserveScroll: true,
    });
  }, [query]);

  const openDetail = useCallback(async (row) => {
    setLoadingDetail(true);
    // Seed the drawer with the row we already have so it paints instantly,
    // then fill in stack/breadcrumbs from the detail endpoint.
    setDetail(row);

    try {
      const { data } = await axios.get(route('admin.client-errors.show', { error: row.id }));
      setDetail(data?.error ?? row);
    } catch {
      showToast.error('Failed to load the full error detail.');
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  const toggleResolved = useCallback(async (row, resolved) => {
    setResolvingId(row.id);

    try {
      const { data } = await axios.post(
        route('admin.client-errors.resolve', { error: row.id }),
        { resolved },
      );
      showToast.success(data?.message ?? 'Updated.');
      setDetail(null);
      router.reload({ preserveScroll: true });
    } catch (error) {
      showToast.error(error?.response?.data?.message ?? 'Failed to update this error.');
    } finally {
      setResolvingId(null);
    }
  }, []);

  const occurrencesOnPage = useMemo(
    () => errors.reduce((total, row) => total + (row.count ?? 0), 0),
    [errors],
  );

  const currentPage = pagination.current_page ?? 1;
  const lastPage = pagination.last_page ?? 1;

  return (
    <App>
      <Head title="Client Diagnostics" />

      <ErrorBoundary>
        <Box p={{ initial: '3', md: '5' }}>
          {/* ── masthead ── */}
          <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
            <Box>
              <Heading size="6" weight="medium">Client Diagnostics</Heading>
              <Text as="p" size="2" color="gray" mt="1">
                Crashes and errors reported by the mobile app, grouped by fingerprint
                so each row is one bug rather than one occurrence.
              </Text>
            </Box>
            <Button variant="soft" onClick={() => router.reload({ preserveScroll: true })}>
              <ReloadIcon /> Refresh
            </Button>
          </Flex>

          {/* ── fleet counters ── */}
          <Grid columns={{ initial: '2', md: '5' }} gap="3" mb="5">
            <Metric label="Error groups" value={summary.total_groups ?? 0} />
            <Metric label="Unresolved" value={summary.unresolved ?? 0} color="orange" />
            <Metric label="Fatal unresolved" value={summary.fatal_unresolved ?? 0} color="red" />
            <Metric label="Total occurrences" value={summary.total_occurrences ?? 0} />
            <Metric label="Occurrences (24h)" value={summary.occurrences_last_24h ?? 0} color="blue" />
          </Grid>

          {/* ── controls + table ── */}
          <Panel variant="surface">
            <Flex align="center" justify="between" gap="3" wrap="wrap" mb="3">
              <Flex align="center" gap="3" wrap="wrap">
                <TextField.Root
                  placeholder="Search message, type, screen…"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  style={{ minWidth: 240 }}
                >
                  <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>

                <Select.Root
                  value={filters.status ?? 'unresolved'}
                  onValueChange={(value) => apply({ status: value })}
                >
                  <Select.Trigger placeholder="Status" />
                  <Select.Content>
                    <Select.Item value="unresolved">Unresolved</Select.Item>
                    <Select.Item value="resolved">Resolved</Select.Item>
                    <Select.Item value="all">All statuses</Select.Item>
                  </Select.Content>
                </Select.Root>

                <Select.Root
                  value={filters.severity ?? 'all'}
                  onValueChange={(value) => apply({ severity: value })}
                >
                  <Select.Trigger placeholder="Severity" />
                  <Select.Content>
                    <Select.Item value="all">All severities</Select.Item>
                    {(options.severities ?? []).map((severity) => (
                      <Select.Item key={severity} value={severity}>
                        {severity.charAt(0).toUpperCase() + severity.slice(1)}
                      </Select.Item>
                    ))}
                  </Select.Content>
                </Select.Root>

                <Select.Root
                  value={filters.platform || 'all'}
                  onValueChange={(value) => apply({ platform: value === 'all' ? '' : value })}
                >
                  <Select.Trigger placeholder="Platform" />
                  <Select.Content>
                    <Select.Item value="all">All platforms</Select.Item>
                    {(options.platforms ?? []).map((platform) => (
                      <Select.Item key={platform} value={platform}>{platform}</Select.Item>
                    ))}
                  </Select.Content>
                </Select.Root>

                <Select.Root
                  value={filters.app_version || 'all'}
                  onValueChange={(value) => apply({ app_version: value === 'all' ? '' : value })}
                >
                  <Select.Trigger placeholder="App version" />
                  <Select.Content>
                    <Select.Item value="all">All versions</Select.Item>
                    {(options.app_versions ?? []).map((version) => (
                      <Select.Item key={version} value={version}>v{version}</Select.Item>
                    ))}
                  </Select.Content>
                </Select.Root>
              </Flex>
            </Flex>

            <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
              <Flex align="center" gap="3" wrap="wrap">
                <TextField.Root
                  placeholder="Screen"
                  defaultValue={filters.screen ?? ''}
                  onBlur={(event) => {
                    if ((filters.screen ?? '') !== event.target.value) {
                      apply({ screen: event.target.value });
                    }
                  }}
                  style={{ maxWidth: 160 }}
                />
                <Flex align="center" gap="2">
                  <Text size="1" color="gray">Last seen</Text>
                  <TextField.Root
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(event) => apply({ from: event.target.value })}
                  />
                  <Text size="1" color="gray">to</Text>
                  <TextField.Root
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(event) => apply({ to: event.target.value })}
                  />
                </Flex>
              </Flex>

              <Text size="1" color="gray">
                {occurrencesOnPage} occurrence{occurrencesOnPage === 1 ? '' : 's'} on this page
              </Text>
            </Flex>

            <Separator size="4" mb="4" />

            <Box style={{ overflowX: 'auto' }}>
              <Table.Root variant="ghost">
                <Table.Header>
                  <Table.Row>
                    <Table.ColumnHeaderCell>Error</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Severity</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell align="right">Count</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Affected</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Platforms</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Seen</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell align="right">Action</Table.ColumnHeaderCell>
                  </Table.Row>
                </Table.Header>

                <Table.Body>
                  {errors.length === 0 ? (
                    <Table.Row>
                      <Table.Cell colSpan={7}>
                        <Flex direction="column" align="center" gap="2" py="6">
                          <MobileIcon width="22" height="22" color="gray" />
                          <Text size="2" color="gray">No client errors match these filters.</Text>
                        </Flex>
                      </Table.Cell>
                    </Table.Row>
                  ) : errors.map((row) => (
                    <Table.Row key={row.id}>
                      <Table.Cell>
                        <Text
                          as="div"
                          size="2"
                          weight="medium"
                          style={{ cursor: 'pointer', maxWidth: 420 }}
                          onClick={() => openDetail(row)}
                        >
                          {row.message}
                        </Text>
                        <Text as="div" size="1" color="gray">
                          {[row.error_type, row.screen, row.app_version ? `v${row.app_version}` : null]
                            .filter(Boolean).join(' • ') || '—'}
                        </Text>
                      </Table.Cell>

                      <Table.Cell>
                        <Flex direction="column" gap="1" align="start">
                          <Badge size="1" color={SEVERITY_COLOR[row.severity] ?? 'gray'}>
                            {row.severity}
                          </Badge>
                          {row.is_resolved ? (
                            <Badge size="1" color="green"><CheckCircledIcon /> Resolved</Badge>
                          ) : null}
                        </Flex>
                      </Table.Cell>

                      <Table.Cell align="right">
                        <Text size="2" weight="medium">{row.count}</Text>
                      </Table.Cell>

                      <Table.Cell>
                        <Text as="div" size="1">{row.affected_devices} device(s)</Text>
                        <Text as="div" size="1" color="gray">{row.affected_users} user(s)</Text>
                      </Table.Cell>

                      <Table.Cell>
                        <PlatformBreakdown counts={row.platform_counts} />
                      </Table.Cell>

                      <Table.Cell>
                        <Tooltip content={`First seen ${absolute(row.first_seen_at)}`}>
                          <Text as="div" size="2">{relative(row.last_seen_at)}</Text>
                        </Tooltip>
                        <Text as="div" size="1" color="gray">
                          first {relative(row.first_seen_at)}
                        </Text>
                      </Table.Cell>

                      <Table.Cell align="right">
                        <Flex gap="2" justify="end">
                          <Button size="1" variant="soft" onClick={() => openDetail(row)}>
                            Details
                          </Button>
                          {can.resolve ? (
                            <Button
                              size="1"
                              variant="soft"
                              color={row.is_resolved ? 'gray' : 'green'}
                              disabled={resolvingId === row.id}
                              onClick={() => toggleResolved(row, !row.is_resolved)}
                            >
                              {row.is_resolved ? 'Reopen' : 'Resolve'}
                            </Button>
                          ) : null}
                        </Flex>
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

        {/* ── detail drawer ── */}
        <Dialog.Root open={detail !== null} onOpenChange={(open) => { if (!open) setDetail(null); }}>
          <Dialog.Content maxWidth="720px">
            <Flex align="start" justify="between" gap="3" mb="2">
              <Dialog.Title mb="0">
                <Flex align="center" gap="2">
                  <ExclamationTriangleIcon
                    color={`var(--${SEVERITY_COLOR[detail?.severity] ?? 'gray'}-9)`}
                  />
                  {detail?.error_type || 'Client error'}
                </Flex>
              </Dialog.Title>
              <Dialog.Close>
                <IconButton size="1" variant="ghost" color="gray"><Cross2Icon /></IconButton>
              </Dialog.Close>
            </Flex>

            <Dialog.Description size="2" color="gray" mb="3">
              {detail?.message}
            </Dialog.Description>

            {loadingDetail ? (
              <Flex align="center" gap="2" mb="3">
                <Spinner size="1" /><Text size="1" color="gray">Loading full detail…</Text>
              </Flex>
            ) : null}

            <Box style={{ maxHeight: '62vh', overflowY: 'auto' }}>
              {/* blast radius */}
              <Panel tinted mb="3">
                <Grid columns={{ initial: '2', sm: '4' }} gap="3">
                  <Fact label="Occurrences" value={detail?.count} />
                  <Fact label="Devices" value={detail?.affected_devices} />
                  <Fact label="Users" value={detail?.affected_users} />
                  <Fact
                    label="Severity"
                    value={<Badge size="1" color={SEVERITY_COLOR[detail?.severity] ?? 'gray'}>{detail?.severity}</Badge>}
                  />
                </Grid>
              </Panel>

              {/* context */}
              <Panel.Section title="Latest sample" first>
                <Grid columns={{ initial: '2', sm: '3' }} gap="3">
                  <Fact label="Screen" value={detail?.screen} />
                  <Fact label="Platform" value={detail?.platform} />
                  <Fact label="OS version" value={detail?.os_version} />
                  <Fact label="Device model" value={detail?.device_model} />
                  <Fact label="App version" value={detail?.app_version ? `v${detail.app_version}` : null} />
                  <Fact label="Build" value={detail?.build} />
                  <Fact label="Device id" value={detail?.device_id} />
                  <Fact label="Session id" value={detail?.session_id} />
                  <Fact label="User" value={detail?.latest_user?.name} />
                  <Fact label="First seen" value={absolute(detail?.first_seen_at)} />
                  <Fact label="Last seen" value={absolute(detail?.last_seen_at)} />
                  <Fact label="Fingerprint" value={<Code size="1">{detail?.short_fingerprint}</Code>} />
                </Grid>
              </Panel.Section>

              {/* stack */}
              <Panel.Section title="Stack trace">
                {detail?.stack ? (
                  <Box
                    style={{
                      maxHeight: 260,
                      overflow: 'auto',
                      background: 'var(--gray-2)',
                      borderRadius: 'var(--radius-3)',
                      padding: 'var(--space-3)',
                    }}
                  >
                    <Text as="div" size="1" style={{ whiteSpace: 'pre-wrap', fontFamily: 'var(--code-font-family)' }}>
                      {detail.stack}
                    </Text>
                  </Box>
                ) : (
                  <Text size="1" color="gray">No stack trace was reported.</Text>
                )}
              </Panel.Section>

              {/* breadcrumbs */}
              <Panel.Section title="Breadcrumbs">
                {(detail?.breadcrumbs ?? []).length > 0 ? (
                  <Flex direction="column" gap="2">
                    {detail.breadcrumbs.map((crumb, index) => (
                      <Flex key={index} align="start" gap="2">
                        <CounterClockwiseClockIcon color="gray" style={{ marginTop: 3, flexShrink: 0 }} />
                        <Box>
                          <Text as="div" size="1">
                            {crumb.type ? <Badge size="1" variant="soft" mr="2">{crumb.type}</Badge> : null}
                            {crumb.message}
                          </Text>
                          {crumb.at ? (
                            <Text as="div" size="1" color="gray">{absolute(crumb.at)}</Text>
                          ) : null}
                        </Box>
                      </Flex>
                    ))}
                  </Flex>
                ) : (
                  <Text size="1" color="gray">No breadcrumbs were reported.</Text>
                )}
              </Panel.Section>

              {/* affected users */}
              {(detail?.affected_user_list ?? []).length > 0 ? (
                <Panel.Section title="Affected users">
                  <Flex gap="2" wrap="wrap">
                    {detail.affected_user_list.map((user) => (
                      <Badge key={user.id} size="1" variant="soft" color="gray">
                        {user.name}
                      </Badge>
                    ))}
                  </Flex>
                </Panel.Section>
              ) : null}
            </Box>

            <Separator size="4" my="3" />

            <Flex gap="3" justify="between" align="center" wrap="wrap">
              <Text size="1" color="gray">
                {detail?.is_resolved
                  ? `Resolved ${relative(detail?.resolved_at)}${detail?.resolved_by ? ` by ${detail.resolved_by}` : ''}`
                  : 'A resolved error reopens automatically if it happens again.'}
              </Text>
              <Flex gap="3">
                <Dialog.Close>
                  <Button variant="soft" color="gray">Close</Button>
                </Dialog.Close>
                {can.resolve && detail ? (
                  <Button
                    color={detail.is_resolved ? 'gray' : 'green'}
                    disabled={resolvingId === detail.id}
                    onClick={() => toggleResolved(detail, !detail.is_resolved)}
                  >
                    {resolvingId === detail.id
                      ? <><Spinner size="1" /> Saving…</>
                      : (detail.is_resolved ? 'Reopen error' : 'Mark resolved')}
                  </Button>
                ) : null}
              </Flex>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>
      </ErrorBoundary>
    </App>
  );
};

export default ClientErrors;
