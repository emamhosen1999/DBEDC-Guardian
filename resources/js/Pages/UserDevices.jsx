import { Panel } from '@/Components/ui/Panel';
import React, { useMemo, useState } from 'react';
import { Head, Link } from "@inertiajs/react";
import { Box, Flex, Grid, Text, Heading, Button, IconButton, Separator, Dialog, Select, TextField, TextArea, Switch, Badge, Spinner, Table, Tooltip } from '@radix-ui/themes';
import {
  ArrowLeftIcon, ReloadIcon, CheckCircledIcon, ClockIcon, DesktopIcon,
  MobileIcon, ExclamationTriangleIcon, EyeOpenIcon, MixerHorizontalIcon,
  LockClosedIcon, LockOpen1Icon, MagnifyingGlassIcon, TrashIcon,
  CrossCircledIcon, StarFilledIcon, InfoCircledIcon
} from '@radix-ui/react-icons';
import axios from 'axios';
import { format, formatDistanceToNow } from 'date-fns';
import App from "@/Layouts/App.jsx";
import { showToast } from '@/utils/toastUtils';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import * as useUserDevicesQuery from '@/api/queries/useUserDevicesQuery';

// Reusing your disclosure hook
const useDisclosure = () => {
  const [isOpen, setIsOpen] = useState(false);
  const onOpen = () => setIsOpen(true);
  const onClose = () => setIsOpen(false);
  return { isOpen, onOpen, onClose };
};

const UserDevices = ({ user, devices, userState: initialUserState = null }) => {
  const [userState, setUserState] = useState({
    ...user,
    ...(initialUserState ?? {}),
  });
  const [deviceItems, setDeviceItems] = useState(devices ?? []);

  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [typeFilter, setTypeFilter] = useState('all');
  const [sortBy, setSortBy] = useState('recent');

  const [processing, setProcessing] = useState({
    refresh: false,
    toggle: false,
    reset: false,
    deactivateId: null,
  });

  const [resetReason, setResetReason] = useState('');
  const [selectedDevice, setSelectedDevice] = useState(null);
  const [deviceToDeactivate, setDeviceToDeactivate] = useState(null);

  const resetModal = useDisclosure();
  const detailsModal = useDisclosure();
  const deactivateModal = useDisclosure();

  // React Query hooks
  const { data: devicesData, isLoading: devicesLoading, refetch } = useUserDevicesQuery.useUserDevicesList(userState.id);
  const toggleSingleDeviceLogin = useUserDevicesQuery.useToggleSingleDeviceLogin();
  const resetDevices = useUserDevicesQuery.useResetDevices();
  const deactivateDevice = useUserDevicesQuery.useDeactivateDevice();

  /* ── Formatters ── */
  const parseDateValue = (value) => {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const formatRelative = (value) => {
    const date = parseDateValue(value);
    return date ? formatDistanceToNow(date, { addSuffix: true }) : 'Never';
  };

  const formatExact = (value) => {
    const date = parseDateValue(value);
    return date ? format(date, 'PPP p') : 'N/A';
  };

  const getTimestamp = (value) => {
    const date = parseDateValue(value);
    return date ? date.getTime() : 0;
  };

  const getSafeText = (value, fallback = 'N/A') => {
    const normalized = String(value ?? '').trim();
    return normalized === '' ? fallback : normalized;
  };

  const getShortText = (value, maxLength = 14) => {
    const normalized = getSafeText(value, '');
    if (normalized === '') return 'N/A';
    return normalized.length <= maxLength ? normalized : `${normalized.slice(0, maxLength)}...`;
  };

  const getSignaturePayload = (device) => {
    return device?.signature_payload && typeof device.signature_payload === 'object'
      ? device.signature_payload
      : {};
  };

  const getDeviceIcon = (device) => {
    const type = String(device?.device_type ?? '').toLowerCase();
    const platform = String(device?.platform ?? '').toLowerCase();

    if (type === 'mobile' || platform.includes('android') || platform.includes('ios') || platform.includes('iphone')) {
      return <MobileIcon style={{ width: 20, height: 20, color: 'var(--blue-9)' }} />;
    }

    if (type === 'tablet' || platform.includes('ipad') || platform.includes('tablet')) {
      return <MobileIcon style={{ width: 20, height: 20, color: 'var(--purple-9)' }} />;
    }

    return <DesktopIcon style={{ width: 20, height: 20, color: 'var(--gray-9)' }} />;
  };

  const getDeviceDetails = (device) => {
    const signaturePayload = getSignaturePayload(device);
    return {
      model: getSafeText(device?.device_model || signaturePayload.model),
      manufacturer: getSafeText(device?.device_manufacturer || signaturePayload.manufacturer),
      brand: getSafeText(device?.device_brand || signaturePayload.brand),
      osVersion: getSafeText(device?.os_version || signaturePayload.os_version),
      appVersion: getSafeText(device?.app_version || signaturePayload.app_version),
      buildVersion: getSafeText(device?.build_version || signaturePayload.build_version),
      hardwareId: getSafeText(device?.hardware_id || signaturePayload.hardware_id),
      macAddress: getSafeText(device?.mac_address || signaturePayload.mac_address),
      rawSignature: getSafeText(signaturePayload.signature),
      signatureHash: getSafeText(device?.signature_hash),
    };
  };

  /* ── Memos ── */
  const summary = useMemo(() => {
    const total = deviceItems.length;
    const active = deviceItems.filter((device) => Boolean(device.is_active)).length;
    const trusted = deviceItems.filter((device) => Boolean(device.is_trusted)).length;
    return { total, active, inactive: Math.max(total - active, 0), trusted };
  }, [deviceItems]);

  const lockedDevice = useMemo(() => {
    return [...deviceItems]
      .filter((device) => Boolean(device.is_active))
      .sort((a, b) => getTimestamp(b.last_used_at) - getTimestamp(a.last_used_at))[0] ?? null;
  }, [deviceItems]);

  const filteredDevices = useMemo(() => {
    const query = searchTerm.trim().toLowerCase();
    const filtered = deviceItems.filter((device) => {
      if (statusFilter === 'active' && !device.is_active) return false;
      if (statusFilter === 'inactive' && device.is_active) return false;
      if (typeFilter !== 'all' && String(device.device_type ?? '').toLowerCase() !== typeFilter) return false;

      if (query === '') return true;

      const details = getDeviceDetails(device);
      const searchable = [
        device.device_name, device.browser, device.platform, device.device_id, device.ip_address,
        details.model, details.manufacturer, details.brand, details.osVersion, details.appVersion,
      ].map((item) => String(item ?? '').toLowerCase()).join(' ');

      return searchable.includes(query);
    });

    return filtered.sort((a, b) => {
      if (sortBy === 'name') return String(a.device_name ?? '').localeCompare(String(b.device_name ?? ''));
      if (sortBy === 'oldest') return getTimestamp(a.created_at) - getTimestamp(b.created_at);
      return getTimestamp(b.last_used_at || b.created_at) - getTimestamp(a.last_used_at || a.created_at);
    });
  }, [deviceItems, searchTerm, statusFilter, typeFilter, sortBy]);

  /* ── Actions ── */
  const applyUserState = (nextUserState) => {
    if (!nextUserState || typeof nextUserState !== 'object') return;
    setUserState((previous) => ({ ...previous, ...nextUserState }));
  };

  const refreshDevices = async () => {
    setProcessing((prev) => ({ ...prev, refresh: true }));
    try {
      await refetch();
      showToast.success('Devices refreshed successfully');
    } catch (error) {
      showToast.error(error.response?.data?.message || 'Failed to refresh device history');
    } finally {
      setProcessing((prev) => ({ ...prev, refresh: false }));
    }
  };

  const handleToggleSingleDeviceLogin = async () => {
    setProcessing((prev) => ({ ...prev, toggle: true }));
    const previousState = { ...userState };
    setUserState((prev) => ({ ...prev, single_device_login_enabled: !prev.single_device_login_enabled }));

    try {
      const result = await toggleSingleDeviceLogin.mutateAsync(userState.id);
      if (result?.success) {
        applyUserState(result.user_state);
        if (result.user_state === undefined) {
          setUserState((prev) => ({ ...prev, single_device_login_enabled: Boolean(result.single_device_login_enabled) }));
        }
        showToast.success(result.message || 'Device lock setting updated');
      } else {
        setUserState(previousState);
        showToast.error('Failed to update device lock setting');
      }
    } catch (error) {
      setUserState(previousState);
      showToast.error(error.response?.data?.message || 'Failed to update device lock setting');
    } finally {
      setProcessing((prev) => ({ ...prev, toggle: false }));
    }
  };

  const handleResetDevices = async () => {
    setProcessing((prev) => ({ ...prev, reset: true }));
    const reason = resetReason.trim();
    const previousState = { ...userState };

    setUserState((prev) => ({
      ...prev,
      device_reset_at: new Date().toISOString(),
      device_reset_reason: reason || 'Admin reset via user device management',
    }));

    try {
      const result = await resetDevices.mutateAsync({ userId: userState.id, reason });
      if (result?.success) {
        applyUserState(result.user_state);
        if (result.user_state === undefined) {
          setUserState((prev) => ({
            ...prev,
            device_reset_at: new Date().toISOString(),
            device_reset_reason: reason || 'Admin reset via user device management',
          }));
        }
        setResetReason('');
        resetModal.onClose();
        showToast.success(result.message || 'Devices reset successfully');
        await refreshDevices();
      } else {
        setUserState(previousState);
        showToast.error('Failed to reset devices');
      }
    } catch (error) {
      setUserState(previousState);
      showToast.error(error.response?.data?.message || 'Failed to reset devices');
    } finally {
      setProcessing((prev) => ({ ...prev, reset: false }));
    }
  };

  const openDeactivateDialog = (device) => {
    setDeviceToDeactivate(device);
    deactivateModal.onOpen();
  };

  const handleDeactivateDevice = async () => {
    if (!deviceToDeactivate) return;
    setProcessing((prev) => ({ ...prev, deactivateId: deviceToDeactivate.id }));

    const previousDevices = [...deviceItems];
    setDeviceItems((prev) => prev.filter((d) => d.id !== deviceToDeactivate.id));

    try {
      const result = await deactivateDevice.mutateAsync({ userId: userState.id, deviceId: deviceToDeactivate.id });
      if (result?.success) {
        setDeviceToDeactivate(null);
        deactivateModal.onClose();
        showToast.success(result.message || 'Device deactivated successfully');
        await refreshDevices();
      } else {
        setDeviceItems(previousDevices);
        showToast.error('Failed to deactivate device');
      }
    } catch (error) {
      setDeviceItems(previousDevices);
      showToast.error(error.response?.data?.message || 'Failed to deactivate device');
    } finally {
      setProcessing((prev) => ({ ...prev, deactivateId: null }));
    }
  };

  const openDetailsModal = (device) => {
    setSelectedDevice(device);
    detailsModal.onOpen();
  };

  return (
    <App>
      <Head title={`Device Management - ${userState.name}`} />
      <ErrorBoundary>
        <Box p="4" style={{ maxWidth: 1280, margin: '0 auto', width: '100%' }}>

          {/* ── Page Header ── */}
          <Flex direction={{ initial: 'column', sm: 'row' }} justify="between" align={{ initial: 'start', sm: 'center' }} gap="4" mb="5">
            <Box>
              <Link href={route('users')}>
                <Button variant="ghost" color="gray" size="2" mb="2" style={{ marginLeft: '-10px' }}>
                  <ArrowLeftIcon /> Back to Users
                </Button>
              </Link>
              <Heading size="6">Device Management</Heading>
              <Text size="2" color="gray">
                {userState.name} ({userState.email})
              </Text>
            </Box>

            <Flex align="center" gap="3">
              <Badge
                size="2"
                variant="soft"
                color={userState.single_device_login_enabled ? 'amber' : 'gray'}
              >
                {userState.single_device_login_enabled ? <LockClosedIcon /> : <LockOpen1Icon />}
                {userState.single_device_login_enabled ? 'Lock: ON' : 'Lock: OFF'}
              </Badge>
              <Button
                variant="soft"
                color="blue"
                size="2"
                disabled={processing.refresh}
                onClick={refreshDevices}
              >
                {processing.refresh ? <Spinner size="1" /> : <ReloadIcon />}
                Refresh
              </Button>
            </Flex>
          </Flex>

          <Flex direction="column" gap="4">

            {/* ── Lock & Reset Controls Card ── */}
            <Panel variant="surface">
              <Flex direction={{ initial: 'column', lg: 'row' }} align={{ initial: 'start', lg: 'center' }} justify="between" gap="4" mb="4">
                <Box>
                  <Heading size="4" mb="1">Access & Security Controls</Heading>
                  <Text size="2" color="gray">Manage single-device enforcement and force-reset active sessions.</Text>
                </Box>
                <Flex align="center" gap="3">
                  <Button
                    color={userState.single_device_login_enabled ? 'gray' : 'amber'}
                    variant="soft"
                    disabled={processing.toggle}
                    onClick={handleToggleSingleDeviceLogin}
                  >
                    {processing.toggle ? <Spinner size="1" /> : (userState.single_device_login_enabled ? <LockOpen1Icon /> : <LockClosedIcon />)}
                    {userState.single_device_login_enabled ? 'Disable Lock' : 'Enable Lock'}
                  </Button>
                  <Button
                    color="red"
                    variant="soft"
                    disabled={summary.total === 0}
                    onClick={resetModal.onOpen}
                  >
                    <ReloadIcon /> Reset Devices
                  </Button>
                </Flex>
              </Flex>

              <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="3" mb="4">
                <Panel variant="surface" style={{ background: 'var(--gray-2)' }}>
                  <Text size="2" color="gray">Total Devices</Text>
                  <Heading size="6">{summary.total}</Heading>
                </Panel>
                <Panel variant="surface" style={{ background: 'var(--green-2)' }}>
                  <Text size="2" color="green">Active Devices</Text>
                  <Heading size="6" color="green">{summary.active}</Heading>
                </Panel>
                <Panel variant="surface" style={{ background: 'var(--gray-2)' }}>
                  <Text size="2" color="gray">Inactive Devices</Text>
                  <Heading size="6">{summary.inactive}</Heading>
                </Panel>
                <Panel variant="surface" style={{ background: 'var(--blue-2)' }}>
                  <Text size="2" color="blue">Trusted Devices</Text>
                  <Heading size="6" color="blue">{summary.trusted}</Heading>
                </Panel>
              </Grid>

              <Separator size="4" mb="3" />

              <Flex direction={{ initial: 'column', md: 'row' }} justify="between" align={{ initial: 'start', md: 'center' }} gap="3">
                <Flex gap="4" wrap="wrap">
                  <Text size="2" color="gray">
                    Last reset: <Text weight="medium" color="gray">{formatExact(userState.device_reset_at)}</Text>
                  </Text>
                  <Text size="2" color="gray">
                    Reason: <Text weight="medium" color="gray">{getSafeText(userState.device_reset_reason, 'No reset recorded')}</Text>
                  </Text>
                </Flex>
                {lockedDevice && userState.single_device_login_enabled && (
                  <Text size="2" color="gray">
                    Locked to: <Text weight="medium" color="amber">{getSafeText(lockedDevice.device_name)}</Text>
                  </Text>
                )}
              </Flex>
            </Panel>

            {/* ── Device History List ── */}
            <Panel variant="surface">
              <Box mb="4">
                <Heading size="4" mb="1">Device History</Heading>
                <Text size="2" color="gray">Track login devices with detailed hardware, app, and activity metadata.</Text>
              </Box>

              {/* Toolbar */}
              <Flex direction={{ initial: 'column', lg: 'row' }} align={{ initial: 'stretch', lg: 'center' }} justify="between" gap="3" mb="4">
                <Box style={{ flex: 1, maxWidth: 350 }}>
                  <TextField.Root
                    size="2"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    placeholder="Search by name, platform, model, IP..."
                  >
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                  </TextField.Root>
                </Box>
                <Flex gap="3" wrap="wrap" align="center">
                  <Box>
                    <Text size="1" color="gray" weight="medium" mb="1" as="div">Status</Text>
                    <Select.Root size="2" value={statusFilter} onValueChange={setStatusFilter}>
                      <Select.Trigger style={{ minWidth: 120 }} />
                      <Select.Content>
                        <Select.Item value="all">All Status</Select.Item>
                        <Select.Item value="active">Active</Select.Item>
                        <Select.Item value="inactive">Inactive</Select.Item>
                      </Select.Content>
                    </Select.Root>
                  </Box>
                  <Box>
                    <Text size="1" color="gray" weight="medium" mb="1" as="div">Device Type</Text>
                    <Select.Root size="2" value={typeFilter} onValueChange={setTypeFilter}>
                      <Select.Trigger style={{ minWidth: 120 }} />
                      <Select.Content>
                        <Select.Item value="all">All Types</Select.Item>
                        <Select.Item value="mobile">Mobile</Select.Item>
                        <Select.Item value="tablet">Tablet</Select.Item>
                        <Select.Item value="desktop">Desktop</Select.Item>
                      </Select.Content>
                    </Select.Root>
                  </Box>
                  <Box>
                    <Text size="1" color="gray" weight="medium" mb="1" as="div">Sort By</Text>
                    <Select.Root size="2" value={sortBy} onValueChange={setSortBy}>
                      <Select.Trigger style={{ minWidth: 130 }} />
                      <Select.Content>
                        <Select.Item value="recent">Most Recent</Select.Item>
                        <Select.Item value="oldest">Oldest First</Select.Item>
                        <Select.Item value="name">Device Name</Select.Item>
                      </Select.Content>
                    </Select.Root>
                  </Box>
                </Flex>
              </Flex>

              {filteredDevices.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="3">
                  <MobileIcon style={{ width: 40, height: 40, color: 'var(--gray-8)' }} />
                  <Text size="2" color="gray">No devices match your current filters.</Text>
                </Flex>
              ) : (
                <>
                  {/* Desktop Table View */}
                  <Box display={{ initial: 'none', lg: 'block' }}>
                    <Table.Root variant="surface">
                      <Table.Header>
                        <Table.Row>
                          <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                          <Table.ColumnHeaderCell>Fingerprint</Table.ColumnHeaderCell>
                          <Table.ColumnHeaderCell>Network & App</Table.ColumnHeaderCell>
                          <Table.ColumnHeaderCell>Status & History</Table.ColumnHeaderCell>
                          <Table.ColumnHeaderCell style={{ width: '130px' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                      </Table.Header>
                      <Table.Body>
                        {filteredDevices.map((device) => {
                          const details = getDeviceDetails(device);
                          return (
                            <Table.Row key={device.id}>
                              <Table.Cell>
                                <Flex gap="3" align="start">
                                  <Box style={{ marginTop: '2px' }}>{getDeviceIcon(device)}</Box>
                                  <Box>
                                    <Text size="2" weight="bold" as="div">{getSafeText(device.device_name)}</Text>
                                    <Text size="1" color="gray" as="div">{getSafeText(device.browser)} on {getSafeText(device.platform)}</Text>
                                    <Text size="1" color="gray" as="div">{details.brand} {details.model}</Text>
                                  </Box>
                                </Flex>
                              </Table.Cell>
                              <Table.Cell>
                                <Flex direction="column" gap="1">
                                  <Text size="1" as="div">HW: <Text style={{ fontFamily: 'monospace' }}>{getShortText(details.hardwareId, 16)}</Text></Text>
                                  <Tooltip content={details.signatureHash}>
                                    <Text size="1" as="div">Hash: <Text style={{ fontFamily: 'monospace' }}>{getShortText(details.signatureHash, 14)}</Text></Text>
                                  </Tooltip>
                                  <Tooltip content={device.device_id}>
                                    <Text size="1" as="div">ID: <Text style={{ fontFamily: 'monospace' }}>{getShortText(device.device_id, 16)}</Text></Text>
                                  </Tooltip>
                                </Flex>
                              </Table.Cell>
                              <Table.Cell>
                                <Flex direction="column" gap="1">
                                  <Text size="1" as="div">IP: {getSafeText(device.ip_address)}</Text>
                                  <Text size="1" as="div">OS: {details.osVersion}</Text>
                                  <Text size="1" as="div">App: {details.appVersion}</Text>
                                </Flex>
                              </Table.Cell>
                              <Table.Cell>
                                <Flex direction="column" gap="2">
                                  <Flex gap="2">
                                    <Badge size="1" variant="soft" color={device.is_active ? 'green' : 'gray'}>
                                      {device.is_active ? <CheckCircledIcon /> : <CrossCircledIcon />}
                                      {device.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                    {device.is_trusted && (
                                      <Badge size="1" variant="soft" color="blue">
                                        <StarFilledIcon /> Trusted
                                      </Badge>
                                    )}
                                  </Flex>
                                  <Box>
                                    <Text size="1" color="gray" as="div">
                                      <Flex align="center" gap="1"><ClockIcon /> Last used: {formatRelative(device.last_used_at)}</Flex>
                                    </Text>
                                    <Text size="1" color="gray" as="div" style={{ paddingLeft: '14px' }}>
                                      Registered: {formatRelative(device.created_at)}
                                    </Text>
                                  </Box>
                                </Flex>
                              </Table.Cell>
                              <Table.Cell>
                                <Flex direction="column" gap="2">
                                  <Button size="1" variant="soft" color="blue" onClick={() => openDetailsModal(device)}>
                                    <EyeOpenIcon /> Details
                                  </Button>
                                  <Button
                                    size="1"
                                    variant="soft"
                                    color="red"
                                    disabled={!device.is_active || processing.deactivateId === device.id}
                                    onClick={() => openDeactivateDialog(device)}
                                  >
                                    {processing.deactivateId === device.id ? <Spinner size="1" /> : <TrashIcon />}
                                    Deactivate
                                  </Button>
                                </Flex>
                              </Table.Cell>
                            </Table.Row>
                          );
                        })}
                      </Table.Body>
                    </Table.Root>
                  </Box>

                  {/* Mobile Cards View */}
                  <Box display={{ initial: 'block', lg: 'none' }}>
                    <Flex direction="column" gap="3">
                      {filteredDevices.map((device) => {
                        const details = getDeviceDetails(device);
                        return (
                          <Panel key={device.id} variant="surface">
                            <Flex justify="between" align="start" mb="3">
                              <Flex gap="2" align="start">
                                <Box style={{ marginTop: '2px' }}>{getDeviceIcon(device)}</Box>
                                <Box>
                                  <Text size="2" weight="bold" as="div">{getSafeText(device.device_name)}</Text>
                                  <Text size="1" color="gray" as="div">{getSafeText(device.browser)} on {getSafeText(device.platform)}</Text>
                                </Box>
                              </Flex>
                              <Badge size="1" variant="soft" color={device.is_active ? 'green' : 'gray'}>
                                {device.is_active ? 'Active' : 'Inactive'}
                              </Badge>
                            </Flex>

                            <Grid columns="2" gap="2" mb="3">
                              <Text size="1" color="gray">Model: <Text color="gray" weight="medium">{details.model}</Text></Text>
                              <Text size="1" color="gray">OS: <Text color="gray" weight="medium">{details.osVersion}</Text></Text>
                              <Text size="1" color="gray">IP: <Text color="gray" weight="medium">{getSafeText(device.ip_address)}</Text></Text>
                              <Text size="1" color="gray">App: <Text color="gray" weight="medium">{details.appVersion}</Text></Text>
                              <Box style={{ gridColumn: 'span 2' }}>
                                <Text size="1" color="gray">Last used: <Text color="gray" weight="medium">{formatRelative(device.last_used_at)}</Text></Text>
                              </Box>
                            </Grid>

                            <Flex gap="2">
                              <Button size="1" variant="soft" color="blue" style={{ flex: 1 }} onClick={() => openDetailsModal(device)}>
                                <EyeOpenIcon /> Details
                              </Button>
                              <Button
                                size="1"
                                variant="soft"
                                color="red"
                                style={{ flex: 1 }}
                                disabled={!device.is_active || processing.deactivateId === device.id}
                                onClick={() => openDeactivateDialog(device)}
                              >
                                {processing.deactivateId === device.id ? <Spinner size="1" /> : <TrashIcon />}
                                Deactivate
                              </Button>
                            </Flex>
                          </Panel>
                        );
                      })}
                    </Flex>
                  </Box>
                </>
              )}
            </Panel>
          </Flex>
        </Box>

        {/* ── Dialogs ── */}
        <Dialog.Root open={resetModal.isOpen} onOpenChange={o => !o && resetModal.onClose()}>
          <Dialog.Content style={{ maxWidth: 480 }}>
            <Dialog.Title>
              <Flex align="center" gap="2"><ExclamationTriangleIcon color="var(--red-9)" /> Reset All User Devices</Flex>
            </Dialog.Title>
            <Dialog.Description size="2" color="gray" mb="4">
              This will deactivate all devices for this user. On their next login attempt, a new device registration will be required.
            </Dialog.Description>

            <Box mb="4">
              <Text as="div" size="2" weight="medium" mb="2">Reset Reason (Optional)</Text>
              <TextArea
                size="2"
                placeholder="Optional reason shown in user device history"
                value={resetReason}
                onChange={e => setResetReason(e.target.value)}
                maxLength={255}
                rows={3}
              />
            </Box>

            <Flex gap="3" justify="end">
              <Button variant="soft" color="gray" onClick={resetModal.onClose} disabled={processing.reset}>
                Cancel
              </Button>
              <Button color="red" onClick={handleResetDevices} disabled={processing.reset}>
                {processing.reset ? <Spinner size="2" /> : <ReloadIcon />}
                Confirm Reset
              </Button>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>

        <Dialog.Root open={deactivateModal.isOpen} onOpenChange={o => !o && deactivateModal.onClose()}>
          <Dialog.Content style={{ maxWidth: 420 }}>
            <Dialog.Title>
              <Flex align="center" gap="2"><TrashIcon color="var(--red-9)" /> Deactivate Device</Flex>
            </Dialog.Title>
            <Dialog.Description size="2" color="gray" mb="4">
              Are you sure you want to deactivate <Text weight="bold">{getSafeText(deviceToDeactivate?.device_name, 'this device')}</Text>?
              <Text as="div" size="1" mt="2">Device ID: {getSafeText(deviceToDeactivate?.device_id)}</Text>
            </Dialog.Description>

            <Flex gap="3" justify="end">
              <Button variant="soft" color="gray" onClick={deactivateModal.onClose} disabled={processing.deactivateId !== null}>
                Cancel
              </Button>
              <Button color="red" onClick={handleDeactivateDevice} disabled={processing.deactivateId !== null}>
                {processing.deactivateId !== null && <Spinner size="2" />}
                Deactivate
              </Button>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>

        <Dialog.Root open={detailsModal.isOpen} onOpenChange={o => !o && detailsModal.onClose()}>
          <Dialog.Content style={{ maxWidth: 650 }}>
            <Dialog.Title mb="4">Device Diagnostics</Dialog.Title>

            {selectedDevice && (() => {
              const details = getDeviceDetails(selectedDevice);
              return (
                <Flex direction="column" gap="4">
                  <Flex align="center" gap="3">
                    {getDeviceIcon(selectedDevice)}
                    <Box>
                      <Heading size="3">{getSafeText(selectedDevice.device_name)}</Heading>
                      <Text size="2" color="gray">
                        {getSafeText(selectedDevice.browser)} on {getSafeText(selectedDevice.platform)}
                      </Text>
                    </Box>
                  </Flex>

                  <Grid columns={{ initial: '1', md: '2' }} gap="3">
                    <Panel variant="surface" size="1">
                      <Text size="1" color="gray" weight="bold" as="div" mb="2">IDENTITY</Text>
                      <Text size="1" as="div">Device ID: <Text style={{ fontFamily: 'monospace' }}>{getSafeText(selectedDevice.device_id)}</Text></Text>
                      <Text size="1" as="div">Type: {getSafeText(selectedDevice.device_type)}</Text>
                      <Text size="1" as="div">Model: {details.model}</Text>
                      <Text size="1" as="div">Manufacturer: {details.manufacturer}</Text>
                      <Text size="1" as="div">Brand: {details.brand}</Text>
                    </Panel>

                    <Panel variant="surface" size="1">
                      <Text size="1" color="gray" weight="bold" as="div" mb="2">ENVIRONMENT</Text>
                      <Text size="1" as="div">OS Version: {details.osVersion}</Text>
                      <Text size="1" as="div">App Version: {details.appVersion}</Text>
                      <Text size="1" as="div">Build Version: {details.buildVersion}</Text>
                      <Text size="1" as="div">IP Address: {getSafeText(selectedDevice.ip_address)}</Text>
                      <Text size="1" as="div">MAC Address: {details.macAddress}</Text>
                    </Panel>

                    <Panel variant="surface" size="1" style={{ gridColumn: '1 / -1' }}>
                      <Text size="1" color="gray" weight="bold" as="div" mb="2">SECURITY FINGERPRINTS</Text>
                      <Text size="1" as="div">Hardware ID: <Text style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{details.hardwareId}</Text></Text>
                      <Text size="1" as="div">Raw Signature: <Text style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{details.rawSignature}</Text></Text>
                      <Text size="1" as="div">Signature Hash: <Text style={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{details.signatureHash}</Text></Text>
                    </Panel>

                    <Panel variant="surface" size="1" style={{ gridColumn: '1 / -1' }}>
                      <Text size="1" color="gray" weight="bold" as="div" mb="2">LIFECYCLE</Text>
                      <Grid columns={{ initial: '1', sm: '2' }} gap="2">
                        <Text size="1" as="div">Status: {selectedDevice.is_active ? 'Active' : 'Inactive'}</Text>
                        <Text size="1" as="div">Trusted: {selectedDevice.is_trusted ? 'Yes' : 'No'}</Text>
                        <Text size="1" as="div">Last Used: {formatExact(selectedDevice.last_used_at)}</Text>
                        <Text size="1" as="div">Registered: {formatExact(selectedDevice.created_at)}</Text>
                        <Text size="1" as="div">Updated: {formatExact(selectedDevice.updated_at)}</Text>
                      </Grid>
                    </Panel>

                    <Panel variant="surface" size="1" style={{ gridColumn: '1 / -1' }}>
                      <Flex align="center" gap="1" mb="2">
                        <InfoCircledIcon color="var(--gray-9)" />
                        <Text size="1" color="gray" weight="bold">USER AGENT</Text>
                      </Flex>
                      <Text size="1" style={{ fontFamily: 'monospace', wordBreak: 'break-all', display: 'block' }}>
                        {getSafeText(selectedDevice.user_agent, 'No user agent recorded')}
                      </Text>
                    </Panel>
                  </Grid>
                </Flex>
              );
            })()}

            <Flex gap="3" justify="end" mt="4">
              <Dialog.Close>
                <Button variant="soft" color="blue">Close</Button>
              </Dialog.Close>
            </Flex>
          </Dialog.Content>
        </Dialog.Root>

      </ErrorBoundary>
    </App>
  );
};

export default UserDevices;