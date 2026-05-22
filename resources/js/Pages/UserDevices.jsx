import {
    Box, Flex, Grid, Text, Heading, Button, IconButton, Card, Separator,
    Dialog, AlertDialog, Select, TextField, TextArea, Checkbox, Switch,
    RadioGroup, Radio, Badge, Spinner, Skeleton, ScrollArea, Table,
    Tabs, Tooltip, DropdownMenu, Progress, Callout, Inset,
} from '@radix-ui/themes';
import React, { useMemo, useState } from 'react';
import { Head, Link } from "@inertiajs/react";
import {
  ArrowLeftIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  ClockIcon,
  ComputerDesktopIcon,
  DevicePhoneMobileIcon,
  DeviceTabletIcon,
  ExclamationTriangleIcon,
  EyeIcon,
  FunnelIcon,
  LockClosedIcon,
  LockOpenIcon,
  MagnifyingGlassIcon,
  ShieldCheckIcon,
  TrashIcon,
  XCircleIcon,
} from "@heroicons/react/24/outline";
import axios from 'axios';
import { format, formatDistanceToNow } from 'date-fns';
import App from "@/Layouts/App.jsx";
import { showToast } from '@/utils/toastUtils';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import * as useUserDevicesQuery from '@/api/queries/useUserDevicesQuery';

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

  const parseDateValue = (value) => {
    if (!value) {
      return null;
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const formatRelative = (value) => {
    const date = parseDateValue(value);

    if (!date) {
      return 'Never';
    }

    return formatDistanceToNow(date, { addSuffix: true });
  };

  const formatExact = (value) => {
    const date = parseDateValue(value);

    if (!date) {
      return 'N/A';
    }

    return format(date, 'PPP p');
  };

  const getTimestamp = (value) => {
    const date = parseDateValue(value);
    return date ? date.getTime() : 0;
  };

  const getDeviceIcon = (device) => {
    const type = String(device?.device_type ?? '').toLowerCase();
    const platform = String(device?.platform ?? '').toLowerCase();

    if (type === 'mobile' || platform.includes('android') || platform.includes('ios') || platform.includes('iphone')) {
      return <DevicePhoneMobileIcon className="w-5 h-5 text-primary" />;
    }

    if (type === 'tablet' || platform.includes('ipad') || platform.includes('tablet')) {
      return <DeviceTabletIcon className="w-5 h-5 text-secondary" />;
    }

    return <ComputerDesktopIcon className="w-5 h-5 text-default-500" />;
  };

  const getSafeText = (value, fallback = 'N/A') => {
    const normalized = String(value ?? '').trim();
    return normalized === '' ? fallback : normalized;
  };

  const getShortText = (value, maxLength = 14) => {
    const normalized = getSafeText(value, '');

    if (normalized === '') {
      return 'N/A';
    }

    if (normalized.length <= maxLength) {
      return normalized;
    }

    return `${normalized.slice(0, maxLength)}...`;
  };

  const getSignaturePayload = (device) => {
    if (device?.signature_payload && typeof device.signature_payload === 'object') {
      return device.signature_payload;
    }

    return {};
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

  const summary = useMemo(() => {
    const total = deviceItems.length;
    const active = deviceItems.filter((device) => Boolean(device.is_active)).length;
    const trusted = deviceItems.filter((device) => Boolean(device.is_trusted)).length;

    return {
      total,
      active,
      inactive: Math.max(total - active, 0),
      trusted,
    };
  }, [deviceItems]);

  const lockedDevice = useMemo(() => {
    return [...deviceItems]
      .filter((device) => Boolean(device.is_active))
      .sort((a, b) => getTimestamp(b.last_used_at) - getTimestamp(a.last_used_at))[0] ?? null;
  }, [deviceItems]);

  const filteredDevices = useMemo(() => {
    const query = searchTerm.trim().toLowerCase();

    const filtered = deviceItems.filter((device) => {
      if (statusFilter === 'active' && !device.is_active) {
        return false;
      }

      if (statusFilter === 'inactive' && device.is_active) {
        return false;
      }

      if (typeFilter !== 'all' && String(device.device_type ?? '').toLowerCase() !== typeFilter) {
        return false;
      }

      if (query === '') {
        return true;
      }

      const details = getDeviceDetails(device);
      const searchable = [
        device.device_name,
        device.browser,
        device.platform,
        device.device_id,
        device.ip_address,
        details.model,
        details.manufacturer,
        details.brand,
        details.osVersion,
        details.appVersion,
      ]
        .map((item) => String(item ?? '').toLowerCase())
        .join(' ');

      return searchable.includes(query);
    });

    return filtered.sort((a, b) => {
      if (sortBy === 'name') {
        return String(a.device_name ?? '').localeCompare(String(b.device_name ?? ''));
      }

      if (sortBy === 'oldest') {
        return getTimestamp(a.created_at) - getTimestamp(b.created_at);
      }

      return getTimestamp(b.last_used_at || b.created_at) - getTimestamp(a.last_used_at || a.created_at);
    });
  }, [deviceItems, searchTerm, statusFilter, typeFilter, sortBy]);

  const applyUserState = (nextUserState) => {
    if (!nextUserState || typeof nextUserState !== 'object') {
      return;
    }

    setUserState((previous) => ({
      ...previous,
      ...nextUserState,
    }));
  };

  const refreshDevices = async () => {
    setProcessing((previous) => ({ ...previous, refresh: true }));

    try {
      await refetch();
      showToast.success('Devices refreshed successfully');
    } catch (error) {
      showToast.error(error.response?.data?.message || 'Failed to refresh device history');
    } finally {
      setProcessing((previous) => ({ ...previous, refresh: false }));
    }
  };

  const handleToggleSingleDeviceLogin = async () => {
    setProcessing((previous) => ({ ...previous, toggle: true }));

    // Optimistic UI: update state immediately
    const previousState = { ...userState };
    const optimisticValue = !userState.single_device_login_enabled;
    setUserState((previous) => ({
      ...previous,
      single_device_login_enabled: optimisticValue,
    }));

    try {
      const result = await toggleSingleDeviceLogin.mutateAsync(userState.id);
      
      if (result?.success) {
        applyUserState(result.user_state);

        if (result.user_state === undefined) {
          setUserState((previous) => ({
            ...previous,
            single_device_login_enabled: Boolean(result.single_device_login_enabled),
          }));
        }

        showToast.success(result.message || 'Device lock setting updated');
      } else {
        // Revert on failure
        setUserState(previousState);
        showToast.error('Failed to update device lock setting');
      }
    } catch (error) {
      // Revert on error
      setUserState(previousState);
      showToast.error(error.response?.data?.message || 'Failed to update device lock setting');
    } finally {
      setProcessing((previous) => ({ ...previous, toggle: false }));
    }
  };

  const handleResetDevices = async () => {
    setProcessing((previous) => ({ ...previous, reset: true }));

    const reason = resetReason.trim();

    // Optimistic UI: update state immediately
    const previousState = { ...userState };
    setUserState((previous) => ({
      ...previous,
      device_reset_at: new Date().toISOString(),
      device_reset_reason: reason || 'Admin reset via user device management',
    }));

    try {
      const result = await resetDevices.mutateAsync({ userId: userState.id, reason });

      if (result?.success) {
        applyUserState(result.user_state);

        if (result.user_state === undefined) {
          setUserState((previous) => ({
            ...previous,
            device_reset_at: new Date().toISOString(),
            device_reset_reason: reason || 'Admin reset via user device management',
          }));
        }

        setResetReason('');
        resetModal.onClose();
        showToast.success(result.message || 'Devices reset successfully');
        await refreshDevices();
      } else {
        // Revert on failure
        setUserState(previousState);
        showToast.error('Failed to reset devices');
      }
    } catch (error) {
      // Revert on error
      setUserState(previousState);
      showToast.error(error.response?.data?.message || 'Failed to reset devices');
    } finally {
      setProcessing((previous) => ({ ...previous, reset: false }));
    }
  };

  const openDeactivateDialog = (device) => {
    setDeviceToDeactivate(device);
    deactivateModal.onOpen();
  };

  const handleDeactivateDevice = async () => {
    if (!deviceToDeactivate) {
      return;
    }

    setProcessing((previous) => ({ ...previous, deactivateId: deviceToDeactivate.id }));

    // Optimistic UI: remove device from local state immediately
    const previousDevices = [...deviceItems];
    setDeviceItems(prevItems => prevItems.filter(device => device.id !== deviceToDeactivate.id));

    try {
      const result = await deactivateDevice.mutateAsync({
        userId: userState.id,
        deviceId: deviceToDeactivate.id
      });

      if (result?.success) {
        setDeviceToDeactivate(null);
        deactivateModal.onClose();
        showToast.success(result.message || 'Device deactivated successfully');
        await refreshDevices();
      } else {
        // Revert on failure
        setDeviceItems(previousDevices);
        showToast.error('Failed to deactivate device');
      }
    } catch (error) {
      // Revert on error
      setDeviceItems(previousDevices);
      showToast.error(error.response?.data?.message || 'Failed to deactivate device');
    } finally {
      setProcessing((previous) => ({ ...previous, deactivateId: null }));
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

      <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <Link href={route('users')}>
              <Button
                variant="light"
                size="sm"
                startContent={<ArrowLeftIcon className="w-4 h-4" />}
                className="mb-2"
              >
                Back to Users
              </Button>
            </Link>

            <h1 className="text-2xl font-bold">Device Management and History</h1>
            <p className="mt-1 text-default-500">
              {userState.name} ({userState.email})
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Badge
              color={userState.single_device_login_enabled ? 'warning' : 'default'}
              variant="soft"
              startContent={
                userState.single_device_login_enabled
                  ? <LockClosedIcon className="h-4 w-4" />
                  : <LockOpenIcon className="h-4 w-4" />
              }
            >
              {userState.single_device_login_enabled ? 'Single Device Lock: ON' : 'Single Device Lock: OFF'}
            </Badge>

            <Button
              variant="soft"
              color="primary"
              loading={processing.refresh}
              startContent={!processing.refresh ? <ArrowPathIcon className="w-4 h-4" /> : null}
              onClick={refreshDevices}
            >
              Refresh
            </Button>
          </div>
        </div>

        <Card>
          <CardHeader className="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-lg font-semibold">Lock and Reset Controls</h2>
              <p className="text-sm text-default-500">Manage single-device enforcement and reset access for this user.</p>
            </div>

            <div className="flex items-center gap-2">
              <Button
                color={userState.single_device_login_enabled ? 'default' : 'warning'}
                variant="soft"
                loading={processing.toggle}
                onClick={handleToggleSingleDeviceLogin}
                startContent={!processing.toggle
                  ? (userState.single_device_login_enabled
                    ? <LockOpenIcon className="w-4 h-4" />
                    : <LockClosedIcon className="w-4 h-4" />)
                  : null}
              >
                {userState.single_device_login_enabled ? 'Disable Lock' : 'Enable Lock'}
              </Button>

              <Button
                color="red"
                variant="soft"
                disabled={summary.total === 0}
                onClick={resetModal.onOpen}
                startContent={<ArrowPathIcon className="w-4 h-4" />}
              >
                Reset Devices
              </Button>
            </div>
          </CardHeader>

          <CardBody className="grid grid-cols-1 gap-3 md:grid-cols-4">
            <Card className="border border-default-200">
              <CardBody className="py-3">
                <p className="text-xs text-default-500">Total Devices</p>
                <p className="text-2xl font-semibold">{summary.total}</p>
              </CardBody>
            </Card>

            <Card className="border border-success-200">
              <CardBody className="py-3">
                <p className="text-xs text-default-500">Active Devices</p>
                <p className="text-2xl font-semibold text-success">{summary.active}</p>
              </CardBody>
            </Card>

            <Card className="border border-default-200">
              <CardBody className="py-3">
                <p className="text-xs text-default-500">Inactive Devices</p>
                <p className="text-2xl font-semibold">{summary.inactive}</p>
              </CardBody>
            </Card>

            <Card className="border border-primary-200">
              <CardBody className="py-3">
                <p className="text-xs text-default-500">Trusted Devices</p>
                <p className="text-2xl font-semibold text-primary">{summary.trusted}</p>
              </CardBody>
            </Card>
          </CardBody>

          <CardBody className="pt-0 text-sm text-default-600">
            <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
              <p>
                Last reset: <span className="font-medium">{formatExact(userState.device_reset_at)}</span>
              </p>
              <p>
                Reason: <span className="font-medium">{getSafeText(userState.device_reset_reason, 'No reset recorded')}</span>
              </p>
            </div>
            {lockedDevice && userState.single_device_login_enabled && (
              <p className="mt-2">
                Locked device: <span className="font-medium">{getSafeText(lockedDevice.device_name)}</span> ({getSafeText(lockedDevice.platform)})
              </p>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader className="flex flex-col items-start gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h2 className="text-lg font-semibold">Device History</h2>
              <p className="text-sm text-default-500">Track login devices with detailed hardware, app, and activity metadata.</p>
            </div>

            <div className="flex w-full flex-col gap-2 lg:w-auto lg:flex-row lg:items-center">
              <TextField.Root
                value={searchTerm}
                onValueChange={setSearchTerm}
                placeholder="Search by name, platform, model, IP..."
                size="sm"
                startContent={<MagnifyingGlassIcon className="w-4 h-4 text-default-400" />}
                className="w-full lg:w-80"
              />

              <div className="flex items-center gap-2">
                <Badge
                  variant={statusFilter === 'all' ? 'solid' : 'flat'}
                  color={statusFilter === 'all' ? 'primary' : 'default'}
                  className="cursor-pointer"
                  onClick={() => setStatusFilter('all')}
                >
                  All
                </Badge>
                <Badge
                  variant={statusFilter === 'active' ? 'solid' : 'flat'}
                  color={statusFilter === 'active' ? 'success' : 'default'}
                  className="cursor-pointer"
                  onClick={() => setStatusFilter('active')}
                >
                  Active
                </Badge>
                <Badge
                  variant={statusFilter === 'inactive' ? 'solid' : 'flat'}
                  color={statusFilter === 'inactive' ? 'danger' : 'default'}
                  className="cursor-pointer"
                  onClick={() => setStatusFilter('inactive')}
                >
                  Inactive
                </Badge>
              </div>
            </div>
          </CardHeader>

          <CardBody className="pt-0">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <Badge
                startContent={<FunnelIcon className="h-3.5 w-3.5" />}
                variant={typeFilter === 'all' ? 'solid' : 'flat'}
                color={typeFilter === 'all' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('all')}
              >
                All Types
              </Badge>
              <Badge
                variant={typeFilter === 'mobile' ? 'solid' : 'flat'}
                color={typeFilter === 'mobile' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('mobile')}
              >
                Mobile
              </Badge>
              <Badge
                variant={typeFilter === 'tablet' ? 'solid' : 'flat'}
                color={typeFilter === 'tablet' ? 'secondary' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('tablet')}
              >
                Tablet
              </Badge>
              <Badge
                variant={typeFilter === 'desktop' ? 'solid' : 'flat'}
                color={typeFilter === 'desktop' ? 'default' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('desktop')}
              >
                Desktop
              </Badge>

              <div className="mx-1 h-5 w-px bg-default-200" />

              <Badge
                variant={sortBy === 'recent' ? 'solid' : 'flat'}
                color={sortBy === 'recent' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setSortBy('recent')}
              >
                Most Recent
              </Badge>
              <Badge
                variant={sortBy === 'oldest' ? 'solid' : 'flat'}
                color={sortBy === 'oldest' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setSortBy('oldest')}
              >
                Oldest
              </Badge>
              <Badge
                variant={sortBy === 'name' ? 'solid' : 'flat'}
                color={sortBy === 'name' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setSortBy('name')}
              >
                Name
              </Badge>
            </div>

            {filteredDevices.length === 0 ? (
              <div className="py-14 text-center">
                <DevicePhoneMobileIcon className="mx-auto mb-3 h-12 w-12 text-default-300" />
                <p className="text-default-500">No devices match your current filters.</p>
              </div>
            ) : (
              <>
                <div className="hidden lg:block">
                  <Table removeWrapper aria-label="Detailed device history table">
                    <TableHeader>
                      <TableColumn>DEVICE</TableColumn>
                      <TableColumn>FINGERPRINT</TableColumn>
                      <TableColumn>NETWORK</TableColumn>
                      <TableColumn>APP / OS</TableColumn>
                      <TableColumn>STATUS & HISTORY</TableColumn>
                      <TableColumn>ACTIONS</TableColumn>
                    </TableHeader>
                    <TableBody>
                      {filteredDevices.map((device) => {
                        const details = getDeviceDetails(device);

                        return (
                          <TableRow key={device.id}>
                            <TableCell>
                              <div className="flex items-start gap-3">
                                {getDeviceIcon(device)}
                                <div>
                                  <p className="font-semibold">{getSafeText(device.device_name)}</p>
                                  <p className="text-xs text-default-500">
                                    {getSafeText(device.browser)} on {getSafeText(device.platform)}
                                  </p>
                                  <p className="text-xs text-default-500">
                                    {details.brand} {details.model}
                                  </p>
                                </div>
                              </div>
                            </TableCell>

                            <TableCell>
                              <div className="space-y-1 text-xs">
                                <p>
                                  HW: <span className="font-mono">{getShortText(details.hardwareId, 18)}</span>
                                </p>
                                <Tooltip content={details.signatureHash}>
                                  <p>
                                    Signature Hash: <span className="font-mono">{getShortText(details.signatureHash, 18)}</span>
                                  </p>
                                </Tooltip>
                                <Tooltip content={device.device_id}>
                                  <p>
                                    Device ID: <span className="font-mono">{getShortText(device.device_id, 18)}</span>
                                  </p>
                                </Tooltip>
                              </div>
                            </TableCell>

                            <TableCell>
                              <div className="space-y-1 text-xs">
                                <p>IP: {getSafeText(device.ip_address)}</p>
                                <Tooltip content={getSafeText(device.user_agent, 'No user agent recorded')}>
                                  <p className="max-w-[220px] truncate">UA: {getSafeText(device.user_agent, 'No user agent')}</p>
                                </Tooltip>
                              </div>
                            </TableCell>

                            <TableCell>
                              <div className="space-y-1 text-xs">
                                <p>OS: {details.osVersion}</p>
                                <p>App: {details.appVersion}</p>
                                <p>Build: {details.buildVersion}</p>
                              </div>
                            </TableCell>

                            <TableCell>
                              <div className="space-y-2">
                                <div className="flex flex-wrap gap-2">
                                  <Badge
                                    size="sm"
                                    variant="soft"
                                    color={device.is_active ? 'success' : 'default'}
                                    startContent={device.is_active ? <CheckCircleIcon className="h-3 w-3" /> : <XCircleIcon className="h-3 w-3" />}
                                  >
                                    {device.is_active ? 'Active' : 'Inactive'}
                                  </Badge>

                                  {device.is_trusted && (
                                    <Badge
                                      size="sm"
                                      variant="soft"
                                      color="primary"
                                      startContent={<ShieldCheckIcon className="h-3 w-3" />}
                                    >
                                      Trusted
                                    </Badge>
                                  )}
                                </div>

                                <div className="space-y-1 text-xs text-default-600">
                                  <p className="flex items-center gap-1">
                                    <ClockIcon className="h-3.5 w-3.5" />
                                    Last used: {formatRelative(device.last_used_at)}
                                  </p>
                                  <p>Registered: {formatRelative(device.created_at)}</p>
                                </div>
                              </div>
                            </TableCell>

                            <TableCell>
                              <div className="flex flex-wrap gap-2">
                                <Button
                                  size="sm"
                                  variant="soft"
                                  color="primary"
                                  startContent={<EyeIcon className="h-4 w-4" />}
                                  onClick={() => openDetailsModal(device)}
                                >
                                  Details
                                </Button>

                                <Button
                                  size="sm"
                                  variant="soft"
                                  color="red"
                                  disabled={!device.is_active || processing.deactivateId === device.id}
                                  loading={processing.deactivateId === device.id}
                                  startContent={processing.deactivateId !== device.id ? <TrashIcon className="h-4 w-4" /> : null}
                                  onClick={() => openDeactivateDialog(device)}
                                >
                                  Deactivate
                                </Button>
                              </div>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </div>

                <div className="space-y-3 lg:hidden">
                  {filteredDevices.map((device) => {
                    const details = getDeviceDetails(device);

                    return (
                      <Card key={device.id} className="border border-default-200">
                        <CardBody className="space-y-3">
                          <div className="flex items-start justify-between gap-3">
                            <div className="flex items-start gap-2">
                              {getDeviceIcon(device)}
                              <div>
                                <p className="font-semibold">{getSafeText(device.device_name)}</p>
                                <p className="text-xs text-default-500">{getSafeText(device.browser)} on {getSafeText(device.platform)}</p>
                              </div>
                            </div>

                            <Badge
                              size="sm"
                              color={device.is_active ? 'success' : 'default'}
                              variant="soft"
                            >
                              {device.is_active ? 'Active' : 'Inactive'}
                            </Badge>
                          </div>

                          <div className="grid grid-cols-2 gap-2 text-xs">
                            <p>Model: {details.model}</p>
                            <p>OS: {details.osVersion}</p>
                            <p>IP: {getSafeText(device.ip_address)}</p>
                            <p>App: {details.appVersion}</p>
                            <p className="col-span-2">Last used: {formatRelative(device.last_used_at)}</p>
                          </div>

                          <div className="flex gap-2">
                            <Button
                              size="sm"
                              variant="soft"
                              color="primary"
                              className="flex-1"
                              startContent={<EyeIcon className="h-4 w-4" />}
                              onClick={() => openDetailsModal(device)}
                            >
                              Details
                            </Button>
                            <Button
                              size="sm"
                              variant="soft"
                              color="red"
                              className="flex-1"
                              disabled={!device.is_active || processing.deactivateId === device.id}
                              loading={processing.deactivateId === device.id}
                              onClick={() => openDeactivateDialog(device)}
                            >
                              Deactivate
                            </Button>
                          </div>
                        </CardBody>
                      </Card>
                    );
                  })}
                </div>
              </>
            )}
          </CardBody>
        </Card>
      </div>

      <Dialog open={resetModal.isOpen} onClose={resetModal.onClose} size="lg">
        <Dialog.Content>
          <Dialog.Title className="flex items-center gap-2">
            <ExclamationTriangleIcon className="h-5 w-5 text-danger" />
            Reset All User Devices
          </Dialog.Title>
          <Box>
            <p className="text-sm text-default-600">
              This will deactivate all devices for this user. On next login, a new device can be registered.
            </p>
            <Textarea
              label="Reset reason"
              placeholder="Optional reason shown in user device history"
              value={resetReason}
              onValueChange={setResetReason}
              maxLength={255}
              variant="outline"
              minRows={3}
            />
          </Box>
          <Flex>
            <Button variant="outline" onClick={resetModal.onClose} disabled={processing.reset}>
              Cancel
            </Button>
            <Button
              color="red"
              onClick={handleResetDevices}
              loading={processing.reset}
              startContent={!processing.reset ? <ArrowPathIcon className="h-4 w-4" /> : null}
            >
              Confirm Reset
            </Button>
          </Flex>
        </Dialog.Content>
      </Dialog>

      <Dialog open={deactivateModal.isOpen} onClose={deactivateModal.onClose} size="md">
        <Dialog.Content>
          <Dialog.Title className="flex items-center gap-2">
            <TrashIcon className="h-5 w-5 text-danger" />
            Deactivate Device
          </Dialog.Title>
          <Box>
            <p className="text-sm text-default-600">
              Are you sure you want to deactivate
              {' '}
              <span className="font-semibold">{getSafeText(deviceToDeactivate?.device_name, 'this device')}</span>
              ?
            </p>
            <p className="text-xs text-default-500">
              Device ID: {getSafeText(deviceToDeactivate?.device_id)}
            </p>
          </Box>
          <Flex>
            <Button variant="outline" onClick={deactivateModal.onClose} disabled={processing.deactivateId !== null}>
              Cancel
            </Button>
            <Button
              color="red"
              onClick={handleDeactivateDevice}
              loading={processing.deactivateId !== null}
            >
              Deactivate
            </Button>
          </Flex>
        </Dialog.Content>
      </Dialog>

      <Dialog
        open={detailsModal.isOpen}
        onClose={detailsModal.onClose}
        size="2xl"
        scrollBehavior="inside"
      >
        <Dialog.Content>
          <Dialog.Title>Device Details</Dialog.Title>
          <Box>
            {selectedDevice && (
              <div className="space-y-4 text-sm">
                <div className="flex items-center gap-3">
                  {getDeviceIcon(selectedDevice)}
                  <div>
                    <p className="font-semibold">{getSafeText(selectedDevice.device_name)}</p>
                    <p className="text-xs text-default-500">
                      {getSafeText(selectedDevice.browser)} on {getSafeText(selectedDevice.platform)}
                    </p>
                  </div>
                </div>

                {(() => {
                  const details = getDeviceDetails(selectedDevice);

                  return (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                      <Card className="border border-default-200">
                        <CardBody className="space-y-1">
                          <p className="text-xs text-default-500">Identity</p>
                          <p>Device ID: <span className="font-mono text-xs">{getSafeText(selectedDevice.device_id)}</span></p>
                          <p>Type: {getSafeText(selectedDevice.device_type)}</p>
                          <p>Model: {details.model}</p>
                          <p>Manufacturer: {details.manufacturer}</p>
                          <p>Brand: {details.brand}</p>
                        </CardBody>
                      </Card>

                      <Card className="border border-default-200">
                        <CardBody className="space-y-1">
                          <p className="text-xs text-default-500">Application and OS</p>
                          <p>OS Version: {details.osVersion}</p>
                          <p>App Version: {details.appVersion}</p>
                          <p>Build Version: {details.buildVersion}</p>
                          <p>IP Address: {getSafeText(selectedDevice.ip_address)}</p>
                          <p>MAC Address: {details.macAddress}</p>
                        </CardBody>
                      </Card>

                      <Card className="border border-default-200 md:col-span-2">
                        <CardBody className="space-y-2">
                          <p className="text-xs text-default-500">Security Fingerprints</p>
                          <p>Hardware ID: <span className="font-mono text-xs break-all">{details.hardwareId}</span></p>
                          <p>Raw Signature: <span className="font-mono text-xs break-all">{details.rawSignature}</span></p>
                          <p>Signature Hash: <span className="font-mono text-xs break-all">{details.signatureHash}</span></p>
                        </CardBody>
                      </Card>

                      <Card className="border border-default-200 md:col-span-2">
                        <CardBody className="space-y-1">
                          <p className="text-xs text-default-500">Activity</p>
                          <p>Status: {selectedDevice.is_active ? 'Active' : 'Inactive'}</p>
                          <p>Trusted: {selectedDevice.is_trusted ? 'Yes' : 'No'}</p>
                          <p>Last Used: {formatExact(selectedDevice.last_used_at)}</p>
                          <p>Registered: {formatExact(selectedDevice.created_at)}</p>
                          <p>Updated: {formatExact(selectedDevice.updated_at)}</p>
                        </CardBody>
                      </Card>

                      <Card className="border border-default-200 md:col-span-2">
                        <CardBody className="space-y-1">
                          <p className="text-xs text-default-500">User Agent</p>
                          <p className="break-all font-mono text-xs">
                            {getSafeText(selectedDevice.user_agent, 'No user agent recorded')}
                          </p>
                        </CardBody>
                      </Card>
                    </div>
                  );
                })()}
              </div>
            )}
          </Box>
          <Flex>
            <Button color="primary" variant="soft" onClick={detailsModal.onClose}>
              Close
            </Button>
          </Flex>
        </Dialog.Content>
      </Dialog>
      </ErrorBoundary>
    </App>
  );
};

export default UserDevices;
