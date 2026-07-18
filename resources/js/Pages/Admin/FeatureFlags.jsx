import React, { useCallback, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Box, Flex, Grid, Text, Heading, Button, Separator, Dialog,
  Select, TextField, TextArea, Badge, Table, Switch, Spinner, Code, Tooltip,
} from '@radix-ui/themes';
import {
  ReloadIcon, PlusIcon, Pencil1Icon, TrashIcon, MixerHorizontalIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { format } from 'date-fns';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { showToast } from '@/utils/toastUtils';

/* Sentinel: Radix Select cannot hold an empty-string value, so "global" stands
   in for role === null across the whole form. */
const GLOBAL = '__global__';

const EMPTY_FORM = {
  id: null,
  key: '',
  value_json: '',
  description: '',
  is_enabled: true,
  role: GLOBAL,
};

const absolute = (value) => {
  if (!value) return '—';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? '—' : format(parsed, 'dd MMM yyyy, HH:mm');
};

const Metric = ({ label, value, color = 'gray' }) => (
  <Panel tinted>
    <Text as="div" size="1" color="gray">{label}</Text>
    <Heading size="6" color={color === 'gray' ? undefined : color} mt="1">{value}</Heading>
  </Panel>
);

const FeatureFlags = ({ flags = [], roles = [], summary = {} }) => {
  const [form, setForm] = useState(null);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const [busyId, setBusyId] = useState(null);
  const [target, setTarget] = useState(null);

  const setField = useCallback((field, value) => {
    setForm((current) => (current ? { ...current, [field]: value } : current));
  }, []);

  const openCreate = useCallback(() => {
    setErrors({});
    setForm({ ...EMPTY_FORM });
  }, []);

  const openEdit = useCallback((flag) => {
    setErrors({});
    setForm({
      id: flag.id,
      key: flag.key ?? '',
      value_json: flag.value_json ?? '',
      description: flag.description ?? '',
      is_enabled: Boolean(flag.is_enabled),
      role: flag.role ?? GLOBAL,
    });
  }, []);

  const save = useCallback(async () => {
    if (!form) return;

    setSaving(true);
    setErrors({});

    const payload = {
      key: form.key.trim(),
      value: form.value_json.trim(),
      description: form.description.trim(),
      is_enabled: form.is_enabled,
      role: form.role === GLOBAL ? null : form.role,
    };

    try {
      const { data } = form.id
        ? await axios.put(route('admin.feature-flags.update', { flag: form.id }), payload)
        : await axios.post(route('admin.feature-flags.store'), payload);

      showToast.success(data?.message ?? 'Flag saved.');
      setForm(null);
      router.reload({ preserveScroll: true });
    } catch (error) {
      const responseErrors = error?.response?.data?.errors;

      if (responseErrors) {
        setErrors(responseErrors);
      } else {
        showToast.error(error?.response?.data?.message ?? 'Failed to save this flag.');
      }
    } finally {
      setSaving(false);
    }
  }, [form]);

  /* One-click on/off — the incident action, no editor round trip. */
  const toggle = useCallback(async (flag) => {
    setBusyId(flag.id);

    try {
      const { data } = await axios.post(route('admin.feature-flags.toggle', { flag: flag.id }));
      showToast.success(data?.message ?? 'Flag updated.');
      router.reload({ preserveScroll: true });
    } catch (error) {
      showToast.error(error?.response?.data?.message ?? 'Failed to toggle this flag.');
    } finally {
      setBusyId(null);
    }
  }, []);

  const remove = useCallback(async () => {
    if (!target) return;

    setBusyId(target.id);

    try {
      const { data } = await axios.delete(route('admin.feature-flags.destroy', { flag: target.id }));
      showToast.success(data?.message ?? 'Flag deleted.');
      setTarget(null);
      router.reload({ preserveScroll: true });
    } catch (error) {
      showToast.error(error?.response?.data?.message ?? 'Failed to delete this flag.');
    } finally {
      setBusyId(null);
    }
  }, [target]);

  /* Group by key so a global row and its role overrides read as one unit. */
  const grouped = useMemo(() => {
    const map = new Map();

    flags.forEach((flag) => {
      if (!map.has(flag.key)) map.set(flag.key, []);
      map.get(flag.key).push(flag);
    });

    return Array.from(map.entries());
  }, [flags]);

  const firstError = (field) => (Array.isArray(errors[field]) ? errors[field][0] : errors[field]);

  return (
    <App>
      <Head title="Feature Flags" />

      <ErrorBoundary>
        <Box p={{ initial: '3', md: '5' }}>
          {/* ── masthead ── */}
          <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
            <Box>
              <Heading size="6" weight="medium">Feature Flags &amp; Remote Config</Heading>
              <Text as="p" size="2" color="gray" mt="1">
                Server-controlled switches the mobile app reads at runtime. Changes reach devices on
                their next foreground — no app-store release required.
              </Text>
            </Box>
            <Flex gap="2">
              <Button variant="soft" onClick={() => router.reload({ preserveScroll: true })}>
                <ReloadIcon /> Refresh
              </Button>
              <Button onClick={openCreate}>
                <PlusIcon /> New flag
              </Button>
            </Flex>
          </Flex>

          {/* ── counters ── */}
          <Grid columns={{ initial: '2', md: '4' }} gap="3" mb="5">
            <Metric label="Total rows" value={summary.total ?? 0} />
            <Metric label="Enabled" value={summary.enabled ?? 0} color="green" />
            <Metric label="Disabled" value={summary.disabled ?? 0} color="red" />
            <Metric label="Role-scoped" value={summary.role_scoped ?? 0} color="blue" />
          </Grid>

          <Panel variant="surface">
            <Flex align="center" justify="between" gap="3" wrap="wrap" mb="4">
              <Flex align="center" gap="2">
                <MixerHorizontalIcon />
                <Text size="2" weight="medium">Effective configuration</Text>
              </Flex>
              <Text size="1" color="gray">
                A role row overrides the global row for users holding that role.
              </Text>
            </Flex>

            <Separator size="4" mb="4" />

            <Box style={{ overflowX: 'auto' }}>
              <Table.Root variant="ghost">
                <Table.Header>
                  <Table.Row>
                    <Table.ColumnHeaderCell>Key / scope</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Value</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>State</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell>Updated</Table.ColumnHeaderCell>
                    <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                  </Table.Row>
                </Table.Header>

                <Table.Body>
                  {grouped.length === 0 ? (
                    <Table.Row>
                      <Table.Cell colSpan={5}>
                        <Flex direction="column" align="center" gap="2" py="6">
                          <MixerHorizontalIcon width="22" height="22" color="gray" />
                          <Text size="2" color="gray">No flags yet. Create one to start steering the fleet.</Text>
                        </Flex>
                      </Table.Cell>
                    </Table.Row>
                  ) : grouped.flatMap(([key, rows]) => rows.map((flag) => (
                    <Table.Row key={flag.id}>
                      <Table.Cell>
                        <Text as="div" size="2" weight="medium">{key}</Text>
                        <Flex align="center" gap="2" mt="1">
                          <Badge color={flag.role ? 'blue' : 'gray'} variant="soft">
                            {flag.role ?? 'Global'}
                          </Badge>
                          {flag.description ? (
                            <Tooltip content={flag.description}>
                              <Text size="1" color="gray" style={{
                                maxWidth: 340,
                                display: 'inline-block',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                              }}
                              >
                                {flag.description}
                              </Text>
                            </Tooltip>
                          ) : null}
                        </Flex>
                      </Table.Cell>

                      <Table.Cell>
                        {flag.value_json
                          ? <Code size="1">{flag.value_json}</Code>
                          : <Text size="1" color="gray">on/off only</Text>}
                      </Table.Cell>

                      <Table.Cell>
                        <Flex align="center" gap="2">
                          <Switch
                            checked={Boolean(flag.is_enabled)}
                            disabled={busyId === flag.id}
                            onCheckedChange={() => toggle(flag)}
                          />
                          <Text size="1" color={flag.is_enabled ? 'green' : 'red'}>
                            {flag.is_enabled ? 'Enabled' : 'Disabled'}
                          </Text>
                        </Flex>
                      </Table.Cell>

                      <Table.Cell>
                        <Text size="1" color="gray">{absolute(flag.updated_at)}</Text>
                      </Table.Cell>

                      <Table.Cell align="right">
                        <Flex gap="2" justify="end">
                          <Button size="1" variant="soft" onClick={() => openEdit(flag)}>
                            <Pencil1Icon /> Edit
                          </Button>
                          <Button size="1" variant="soft" color="red" onClick={() => setTarget(flag)}>
                            <TrashIcon /> Delete
                          </Button>
                        </Flex>
                      </Table.Cell>
                    </Table.Row>
                  )))}
                </Table.Body>
              </Table.Root>
            </Box>
          </Panel>
        </Box>

        {/* ── editor ── */}
        <Dialog.Root open={Boolean(form)} onOpenChange={(open) => (open ? null : setForm(null))}>
          <Dialog.Content maxWidth="520px">
            <Dialog.Title>{form?.id ? 'Edit flag' : 'New flag'}</Dialog.Title>
            <Dialog.Description size="2" color="gray" mb="4">
              Keys are dotted and lower-case, e.g. <Code size="1">mobile.offline_sync_push_enabled</Code>.
            </Dialog.Description>

            <Flex direction="column" gap="3">
              <Box>
                <Text as="label" size="2" weight="medium">Key</Text>
                <TextField.Root
                  mt="1"
                  value={form?.key ?? ''}
                  placeholder="mobile.feature_name"
                  onChange={(event) => setField('key', event.target.value)}
                />
                {firstError('key') ? <Text size="1" color="red">{firstError('key')}</Text> : null}
              </Box>

              <Box>
                <Text as="label" size="2" weight="medium">Value (JSON, optional)</Text>
                <TextArea
                  mt="1"
                  rows={3}
                  value={form?.value_json ?? ''}
                  placeholder={'Leave blank for a pure on/off flag, or e.g. 300 or {"mode":"lite"}'}
                  onChange={(event) => setField('value_json', event.target.value)}
                />
                {firstError('value') ? <Text size="1" color="red">{firstError('value')}</Text> : null}
              </Box>

              <Box>
                <Text as="label" size="2" weight="medium">Description</Text>
                <TextArea
                  mt="1"
                  rows={2}
                  value={form?.description ?? ''}
                  placeholder="What does flipping this actually change?"
                  onChange={(event) => setField('description', event.target.value)}
                />
                {firstError('description') ? <Text size="1" color="red">{firstError('description')}</Text> : null}
              </Box>

              <Box>
                <Text as="label" size="2" weight="medium">Scope</Text>
                <Box mt="1">
                  <Select.Root value={form?.role ?? GLOBAL} onValueChange={(value) => setField('role', value)}>
                    <Select.Trigger />
                    <Select.Content>
                      <Select.Item value={GLOBAL}>Global (everyone)</Select.Item>
                      {roles.map((role) => (
                        <Select.Item key={role} value={role}>{role}</Select.Item>
                      ))}
                    </Select.Content>
                  </Select.Root>
                </Box>
                {firstError('role') ? <Text size="1" color="red">{firstError('role')}</Text> : null}
              </Box>

              <Flex align="center" gap="2">
                <Switch
                  checked={Boolean(form?.is_enabled)}
                  onCheckedChange={(checked) => setField('is_enabled', checked)}
                />
                <Text size="2">Enabled</Text>
              </Flex>
            </Flex>

            <Flex gap="3" justify="end" mt="4">
              <Dialog.Close>
                <Button variant="soft" color="gray" disabled={saving}>Cancel</Button>
              </Dialog.Close>
              <Button onClick={save} disabled={saving}>
                {saving ? <><Spinner size="1" /> Saving…</> : 'Save flag'}
              </Button>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>

        {/* ── delete confirm ── */}
        <Dialog.Root open={Boolean(target)} onOpenChange={(open) => (open ? null : setTarget(null))}>
          <Dialog.Content maxWidth="440px">
            <Dialog.Title>Delete this flag?</Dialog.Title>

            <Panel tinted mb="3">
              <Text as="p" size="2">
                <Code size="1">{target?.key}</Code> ({target?.role ?? 'Global'}) will be removed.
                Devices fall back to the value compiled into the app.
              </Text>
            </Panel>

            <Flex gap="3" justify="end">
              <Dialog.Close>
                <Button variant="soft" color="gray">Cancel</Button>
              </Dialog.Close>
              <Button color="red" onClick={remove} disabled={busyId === target?.id}>
                {busyId === target?.id ? <><Spinner size="1" /> Deleting…</> : 'Delete flag'}
              </Button>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>
      </ErrorBoundary>
    </App>
  );
};

export default FeatureFlags;
