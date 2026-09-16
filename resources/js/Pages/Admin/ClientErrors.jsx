import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Box, Flex, Grid, Text, Heading, Button, Separator, Dialog,
  Select, TextField, Badge, Table, Tooltip, Spinner, IconButton, Code,
} from '@radix-ui/themes';
import {
  MagnifyingGlassIcon, ReloadIcon, ExclamationTriangleIcon,
  CheckCircledIcon, CounterClockwiseClockIcon, MobileIcon,
  ChevronLeftIcon, ChevronRightIcon, Cross2Icon, GlobeIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { format, formatDistanceToNow } from 'date-fns';
import App from '@/Layouts/App.jsx';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';
import { Panel } from '@/Components/ui/Panel';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { showToast } from '@/utils/toastUtils';
import StatsCards from '@/Components/StatsCards';
import PageToolbar from '@/Components/PageToolbar';
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

const SEVERITY_COLOR = { fatal: 'red', error: 'orange', warning: 'amber' };

/* ── stream identity: one board, three sources ── */
const SOURCE_META = {
  mobile: { label: 'Mobile', color: 'violet', Icon: MobileIcon },
  server: { label: 'Server', color: 'cyan', Icon: GlobeIcon },
  web: { label: 'Web', color: 'blue', Icon: GlobeIcon },
};

const SourceBadge = ({ source }) => {
  const meta = SOURCE_META[source] ?? SOURCE_META.mobile;
  const { Icon } = meta;

  return (
    <Badge size="1" variant="soft" color={meta.color}>
      <Icon width="11" height="11" /> {meta.label}
    </Badge>
  );
};

/* ── header metric ── */
const Metric = ({ label, value, color = 'gray' }) => (
  <Panel tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: '16px 14px', background: 'var(--aero-surface, var(--color-background))' }}>
    <Text as="div" size="1" weight="bold" style={{ color: 'var(--aero-color-subtle, var(--gray-9))', fontSize: 11, letterSpacing: '0.05em', textTransform: 'uppercase' }}>{label}</Text>
    <Heading size="6" color={color === 'gray' ? undefined : color} mt="1" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontVariantNumeric: 'tabular-nums', fontWeight: 800 }}>{value}</Heading>
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

// `filters` still arrives from the controller but is not read here: the URL is
// the source of truth and useQueryFilters reads it directly.
const ClientErrors = ({
  errors = [], pagination = {}, options = {}, summary = {}, can = {},
}) => {
  /* The URL is the single source of truth for every filter. The two free-text
     fields are debounced so typing leaves one history entry, not one per key. */
  const f = useQueryFilters({
    routeName: 'admin.client-errors.index',
    defaults: {
      search: '',
      status: 'unresolved',
      severity: 'all',
      source: 'all',
      platform: '',
      app_version: '',
      screen: '',
      from: '',
      to: '',
      page: 1,
    },
    debounceKeys: ['search', 'screen'],
  });

  const search = f.draft.search;
  const setSearch = useCallback((value) => f.setDraft('search', value), [f.setDraft]);
  const goToPage = useCallback((page) => f.setPage(page), [f.setPage]);

  const [detail, setDetail] = useState(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [resolvingId, setResolvingId] = useState(null);

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
  useClampPage(f, pagination.last_page);

  return (
    <App>
      <Head title="Client Diagnostics" />

      <ErrorBoundary>
        <Flex justify="center" p="4">
          <Box style={{ width: '100%', maxWidth: 2000 }}>
            <Panel>
              {/* ── Page Header ── */}
              <Box mb="4">
                <Flex align={{ initial: 'start', sm: 'center' }} justify="between" gap="4" wrap="wrap">
                  <Flex align="center" gap="3">
                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      <ExclamationTriangleIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                    </Box>
                    <Box>
                      <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Diagnostics & Error Telemetry</Heading>
                      <Text as="p" size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                        Mobile app crashes and server exceptions on one board, grouped by fingerprint.
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
                  { key: 'groups', title: 'Error groups', value: summary.total_groups ?? 0, color: 'gray' },
                  { key: 'unresolved', title: 'Unresolved', value: summary.unresolved ?? 0, color: 'amber' },
                  { key: 'fatal', title: 'Fatal unresolved', value: summary.fatal_unresolved ?? 0, color: 'red' },
                  { key: 'mobile', title: 'Mobile unresolved', value: summary.mobile_unresolved ?? 0, color: 'violet' },
                  { key: 'server', title: 'Server unresolved', value: summary.server_unresolved ?? 0, color: 'cyan' },
                  { key: 'web', title: 'Web unresolved', value: summary.web_unresolved ?? 0, color: 'blue' },
                  { key: '24h', title: 'Occurrences (24h)', value: summary.occurrences_last_24h ?? 0, color: 'indigo' },
                ]}
                columns={{ initial: '2', sm: '3', md: '7' }}
                mb="4"
              />

              {/* ── controls + table ── */}
              <Box mb="4" p="3" style={{ background: 'var(--aero-surface, var(--color-background))', borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
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
                  value={f.values.status}
                  onValueChange={(value) => f.set('status', value)}
                >
                  <Select.Trigger placeholder="Status" />
                  <Select.Content>
                    <Select.Item value="unresolved">Unresolved</Select.Item>
                    <Select.Item value="resolved">Resolved</Select.Item>
                    <Select.Item value="all">All statuses</Select.Item>
                  </Select.Content>
                </Select.Root>

                <Select.Root
                  value={f.values.source}
                  onValueChange={(value) => f.set('source', value)}
                >
                  <Select.Trigger placeholder="Source" />
                  <Select.Content>
                    <Select.Item value="all">All sources</Select.Item>
                    {(options.sources ?? ['mobile', 'server']).map((source) => (
                      <Select.Item key={source} value={source}>
                        {SOURCE_META[source]?.label ?? source}
                      </Select.Item>
                    ))}
                  </Select.Content>
                </Select.Root>

                <Select.Root
                  value={f.values.severity}
                  onValueChange={(value) => f.set('severity', value)}
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
                  value={f.values.platform || 'all'}
                  onValueChange={(value) => f.set('platform', value === 'all' ? '' : value)}
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
                  value={f.values.app_version || 'all'}
                  onValueChange={(value) => f.set('app_version', value === 'all' ? '' : value)}
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
                  value={f.draft.screen}
                  onChange={(event) => f.setDraft('screen', event.target.value)}
                  style={{ maxWidth: 160 }}
                />
                <Flex align="center" gap="2">
                  <Text size="1" color="gray">Last seen</Text>
                  <TextField.Root
                    type="date"
                    value={f.values.from}
                    onChange={(event) => f.set('from', event.target.value)}
                  />
                  <Text size="1" color="gray">to</Text>
                  <TextField.Root
                    type="date"
                    value={f.values.to}
                    onChange={(event) => f.set('to', event.target.value)}
                  />
                </Flex>
              </Flex>

              <Text size="1" color="gray">
                {occurrencesOnPage} occurrence{occurrencesOnPage === 1 ? '' : 's'} on this page
              </Text>
            </Flex>

            <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
              <Table.Root size="2" style={{ minWidth: 800, width: '100%' }}>
                <Table.Header style={{
                  position: 'sticky',
                  top: 0,
                  zIndex: 2,
                  background: 'var(--aero-surface, var(--color-background))',
                  backdropFilter: 'blur(8px)',
                  boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                }}>
                  <Table.Row>
                    <Table.ColumnHeaderCell style={{ minWidth: 240, background: 'inherit' }}>Error</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell style={{ minWidth: 100, background: 'inherit' }}>Severity</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell align="right" style={{ minWidth: 80, background: 'inherit' }}>Count</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell style={{ minWidth: 100, background: 'inherit' }}>Affected</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell style={{ minWidth: 140, background: 'inherit' }}>Breakdown</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell style={{ minWidth: 140, background: 'inherit' }}>Seen</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell align="right" style={{ minWidth: 100, background: 'inherit' }}>Action</Table.ColumnHeaderCell>
                  </Table.Row>
                </Table.Header>

                <Table.Body>
                  {errors.length === 0 ? (
                    <Table.Row>
                      <Table.Cell colSpan={7}>
                        <Flex direction="column" align="center" gap="2" py="6">
                          <MobileIcon width="22" height="22" color="gray" />
                          <Text size="2" color="gray">No errors match these filters.</Text>
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
                        <Flex align="center" gap="2" mt="1" wrap="wrap">
                          <SourceBadge source={row.source} />
                          <Text as="span" size="1" color="gray">
                            {/* Server rows are located by endpoint + throw site;
                                mobile rows by screen + app build. */}
                            {(row.is_server
                              ? [
                                row.error_type,
                                row.route_name || row.path,
                                row.status_code ? `HTTP ${row.status_code}` : null,
                                row.file ? `${row.file}${row.line ? `:${row.line}` : ''}` : null,
                              ]
                              : [
                                row.error_type,
                                row.screen,
                                row.app_version ? `v${row.app_version}` : null,
                              ]
                            ).filter(Boolean).join(' • ') || '—'}
                          </Text>
                        </Flex>
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
                        {/* The blast-radius "device" slot holds endpoints for
                            server rows — same question, different axis. */}
                        <Text as="div" size="1">
                          {row.affected_devices} {row.is_server ? 'endpoint(s)' : 'device(s)'}
                        </Text>
                        <Text as="div" size="1" color="gray">{row.affected_users} user(s)</Text>
                      </Table.Cell>

                      <Table.Cell>
                        {/* Platform tally for mobile; HTTP-method tally for server. */}
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

        {/* ── detail drawer ── */}
        <Dialog.Root open={detail !== null} onOpenChange={(open) => { if (!open) setDetail(null); }}>
          <Dialog.Content maxWidth="720px">
            <Flex align="start" justify="between" gap="3" mb="2">
              <Dialog.Title mb="0">
                <Flex align="center" gap="2">
                  <ExclamationTriangleIcon
                    color={`var(--${SEVERITY_COLOR[detail?.severity] ?? 'gray'}-9)`}
                  />
                  {detail?.error_type || (detail?.is_server ? 'Server exception' : 'Client error')}
                  {detail ? <SourceBadge source={detail.source} /> : null}
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
                  <Fact
                    label={detail?.is_server ? 'Endpoints' : 'Devices'}
                    value={detail?.affected_devices}
                  />
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
                  {detail?.is_server ? (
                    <>
                      {/* Server rows: where in the app, and on which request. */}
                      <Fact label="Method" value={detail?.http_method} />
                      <Fact label="Path" value={detail?.path} />
                      <Fact label="Route" value={detail?.route_name} />
                      <Fact label="Status" value={detail?.status_code} />
                      <Fact
                        label="Throw site"
                        value={detail?.file ? `${detail.file}${detail.line ? `:${detail.line}` : ''}` : null}
                      />
                      <Fact
                        label="Request id"
                        value={detail?.request_id ? <Code size="1">{detail.request_id}</Code> : null}
                      />
                    </>
                  ) : (
                    <>
                      <Fact label="Screen" value={detail?.screen} />
                      <Fact label="Platform" value={detail?.platform} />
                      <Fact label="OS version" value={detail?.os_version} />
                      <Fact label="Device model" value={detail?.device_model} />
                      <Fact label="App version" value={detail?.app_version ? `v${detail.app_version}` : null} />
                      <Fact label="Build" value={detail?.build} />
                      <Fact label="Device id" value={detail?.device_id} />
                      <Fact label="Session id" value={detail?.session_id} />
                    </>
                  )}
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

              {/* Server rows carry endpoints instead of breadcrumbs. */}
              {detail?.is_server ? (
                (detail?.affected_device_ids ?? []).length > 0 ? (
                  <Panel.Section title="Affected endpoints">
                    <Flex gap="2" wrap="wrap">
                      {detail.affected_device_ids.map((path) => (
                        <Badge key={path} size="1" variant="soft" color="gray">{path}</Badge>
                      ))}
                    </Flex>
                  </Panel.Section>
                ) : null
              ) : (
              /* breadcrumbs */
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
              )}

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
