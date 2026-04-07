import React, { useMemo, useState } from 'react';
import { Head, Link } from "@inertiajs/react";
import {
  Button,
  Card,
  CardBody,
  CardHeader,
  Chip,
  Input,
  Modal,
  ModalBody,
  ModalContent,
  ModalFooter,
  ModalHeader,
  Table,
  TableBody,
  TableCell,
  TableColumn,
  TableHeader,
  TableRow,
  Textarea,
  Tooltip,
  useDisclosure,
} from "@heroui/react";
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

const UserDevices = ({ user, devices }) => {
  const [userState, setUserState] = useState(user);
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

  const refreshDevices = async () => {
    setProcessing((previous) => ({ ...previous, refresh: true }));

    try {
      const response = await axios.get(route('admin.users.devices', { userId: userState.id }), {
        headers: {
          Accept: 'application/json',
        },
      });

      if (response.data?.success) {
        setDeviceItems(Array.isArray(response.data.devices) ? response.data.devices : []);
      }
    } catch (error) {
      showToast.error(error.response?.data?.message || 'Failed to refresh device history');
    } finally {
      setProcessing((previous) => ({ ...previous, refresh: false }));
    }
  };

  const handleToggleSingleDeviceLogin = async () => {
    setProcessing((previous) => ({ ...previous, toggle: true }));

    try {
      const response = await axios.post(route('admin.users.devices.toggle', { userId: userState.id }));

      if (response.data?.success) {
        setUserState((previous) => ({
          ...previous,
          single_device_login_enabled: Boolean(response.data.single_device_login_enabled),
        }));

        showToast.success(response.data.message || 'Device lock setting updated');
      }
    } catch (error) {
      showToast.error(error.response?.data?.message || 'Failed to update device lock setting');
    } finally {
      setProcessing((previous) => ({ ...previous, toggle: false }));
    }
  };

  const handleResetDevices = async () => {
    setProcessing((previous) => ({ ...previous, reset: true }));

    const reason = resetReason.trim();

    try {
      const response = await axios.post(route('admin.users.devices.reset', { userId: userState.id }), {
        reason: reason === '' ? null : reason,
      });

      if (response.data?.success) {
        setUserState((previous) => ({
          ...previous,
          device_reset_at: new Date().toISOString(),
          device_reset_reason: reason || 'Admin reset via user device management',
        }));

        setResetReason('');
        resetModal.onClose();
        showToast.success(response.data.message || 'Devices reset successfully');
        await refreshDevices();
      }
    } catch (error) {
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

    try {
      const response = await axios.delete(route('admin.users.devices.deactivate', {
        userId: userState.id,
        deviceId: deviceToDeactivate.id,
      }));

      if (response.data?.success) {
        showToast.success(response.data.message || 'Device deactivated successfully');
        deactivateModal.onClose();
        setDeviceToDeactivate(null);
        await refreshDevices();
      }
    } catch (error) {
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
            <Chip
              color={userState.single_device_login_enabled ? 'warning' : 'default'}
              variant="flat"
              startContent={
                userState.single_device_login_enabled
                  ? <LockClosedIcon className="h-4 w-4" />
                  : <LockOpenIcon className="h-4 w-4" />
              }
            >
              {userState.single_device_login_enabled ? 'Single Device Lock: ON' : 'Single Device Lock: OFF'}
            </Chip>

            <Button
              variant="flat"
              color="primary"
              isLoading={processing.refresh}
              startContent={!processing.refresh ? <ArrowPathIcon className="w-4 h-4" /> : null}
              onPress={refreshDevices}
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
                variant="flat"
                isLoading={processing.toggle}
                onPress={handleToggleSingleDeviceLogin}
                startContent={!processing.toggle
                  ? (userState.single_device_login_enabled
                    ? <LockOpenIcon className="w-4 h-4" />
                    : <LockClosedIcon className="w-4 h-4" />)
                  : null}
              >
                {userState.single_device_login_enabled ? 'Disable Lock' : 'Enable Lock'}
              </Button>

              <Button
                color="danger"
                variant="flat"
                isDisabled={summary.total === 0}
                onPress={resetModal.onOpen}
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
              <Input
                value={searchTerm}
                onValueChange={setSearchTerm}
                placeholder="Search by name, platform, model, IP..."
                size="sm"
                startContent={<MagnifyingGlassIcon className="w-4 h-4 text-default-400" />}
                className="w-full lg:w-80"
              />

              <div className="flex items-center gap-2">
                <Chip
                  variant={statusFilter === 'all' ? 'solid' : 'flat'}
                  color={statusFilter === 'all' ? 'primary' : 'default'}
                  className="cursor-pointer"
                  onClick={() => setStatusFilter('all')}
                >
                  All
                </Chip>
                <Chip
                  variant={statusFilter === 'active' ? 'solid' : 'flat'}
                  color={statusFilter === 'active' ? 'success' : 'default'}
                  className="cursor-pointer"
                  onClick={() => setStatusFilter('active')}
                >
                  Active
                </Chip>
                <Chip
                  variant={statusFilter === 'inactive' ? 'solid' : 'flat'}
                  color={statusFilter === 'inactive' ? 'danger' : 'default'}
                  className="cursor-pointer"
                  onClick={() => setStatusFilter('inactive')}
                >
                  Inactive
                </Chip>
              </div>
            </div>
          </CardHeader>

          <CardBody className="pt-0">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <Chip
                startContent={<FunnelIcon className="h-3.5 w-3.5" />}
                variant={typeFilter === 'all' ? 'solid' : 'flat'}
                color={typeFilter === 'all' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('all')}
              >
                All Types
              </Chip>
              <Chip
                variant={typeFilter === 'mobile' ? 'solid' : 'flat'}
                color={typeFilter === 'mobile' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('mobile')}
              >
                Mobile
              </Chip>
              <Chip
                variant={typeFilter === 'tablet' ? 'solid' : 'flat'}
                color={typeFilter === 'tablet' ? 'secondary' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('tablet')}
              >
                Tablet
              </Chip>
              <Chip
                variant={typeFilter === 'desktop' ? 'solid' : 'flat'}
                color={typeFilter === 'desktop' ? 'default' : 'default'}
                className="cursor-pointer"
                onClick={() => setTypeFilter('desktop')}
              >
                Desktop
              </Chip>

              <div className="mx-1 h-5 w-px bg-default-200" />

              <Chip
                variant={sortBy === 'recent' ? 'solid' : 'flat'}
                color={sortBy === 'recent' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setSortBy('recent')}
              >
                Most Recent
              </Chip>
              <Chip
                variant={sortBy === 'oldest' ? 'solid' : 'flat'}
                color={sortBy === 'oldest' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setSortBy('oldest')}
              >
                Oldest
              </Chip>
              <Chip
                variant={sortBy === 'name' ? 'solid' : 'flat'}
                color={sortBy === 'name' ? 'primary' : 'default'}
                className="cursor-pointer"
                onClick={() => setSortBy('name')}
              >
                Name
              </Chip>
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
                                  <Chip
                                    size="sm"
                                    variant="flat"
                                    color={device.is_active ? 'success' : 'default'}
                                    startContent={device.is_active ? <CheckCircleIcon className="h-3 w-3" /> : <XCircleIcon className="h-3 w-3" />}
                                  >
                                    {device.is_active ? 'Active' : 'Inactive'}
                                  </Chip>

                                  {device.is_trusted && (
                                    <Chip
                                      size="sm"
                                      variant="flat"
                                      color="primary"
                                      startContent={<ShieldCheckIcon className="h-3 w-3" />}
                                    >
                                      Trusted
                                    </Chip>
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
                                  variant="flat"
                                  color="primary"
                                  startContent={<EyeIcon className="h-4 w-4" />}
                                  onPress={() => openDetailsModal(device)}
                                >
                                  Details
                                </Button>

                                <Button
                                  size="sm"
                                  variant="flat"
                                  color="danger"
                                  isDisabled={!device.is_active || processing.deactivateId === device.id}
                                  isLoading={processing.deactivateId === device.id}
                                  startContent={processing.deactivateId !== device.id ? <TrashIcon className="h-4 w-4" /> : null}
                                  onPress={() => openDeactivateDialog(device)}
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

                            <Chip
                              size="sm"
                              color={device.is_active ? 'success' : 'default'}
                              variant="flat"
                            >
                              {device.is_active ? 'Active' : 'Inactive'}
                            </Chip>
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
                              variant="flat"
                              color="primary"
                              className="flex-1"
                              startContent={<EyeIcon className="h-4 w-4" />}
                              onPress={() => openDetailsModal(device)}
                            >
                              Details
                            </Button>
                            <Button
                              size="sm"
                              variant="flat"
                              color="danger"
                              className="flex-1"
                              isDisabled={!device.is_active || processing.deactivateId === device.id}
                              isLoading={processing.deactivateId === device.id}
                              onPress={() => openDeactivateDialog(device)}
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

      <Modal isOpen={resetModal.isOpen} onClose={resetModal.onClose} size="lg">
        <ModalContent>
          <ModalHeader className="flex items-center gap-2">
            <ExclamationTriangleIcon className="h-5 w-5 text-danger" />
            Reset All User Devices
          </ModalHeader>
          <ModalBody>
            <p className="text-sm text-default-600">
              This will deactivate all devices for this user. On next login, a new device can be registered.
            </p>
            <Textarea
              label="Reset reason"
              placeholder="Optional reason shown in user device history"
              value={resetReason}
              onValueChange={setResetReason}
              maxLength={255}
              variant="bordered"
              minRows={3}
            />
          </ModalBody>
          <ModalFooter>
            <Button variant="bordered" onPress={resetModal.onClose} isDisabled={processing.reset}>
              Cancel
            </Button>
            <Button
              color="danger"
              onPress={handleResetDevices}
              isLoading={processing.reset}
              startContent={!processing.reset ? <ArrowPathIcon className="h-4 w-4" /> : null}
            >
              Confirm Reset
            </Button>
          </ModalFooter>
        </ModalContent>
      </Modal>

      <Modal isOpen={deactivateModal.isOpen} onClose={deactivateModal.onClose} size="md">
        <ModalContent>
          <ModalHeader className="flex items-center gap-2">
            <TrashIcon className="h-5 w-5 text-danger" />
            Deactivate Device
          </ModalHeader>
          <ModalBody>
            <p className="text-sm text-default-600">
              Are you sure you want to deactivate
              {' '}
              <span className="font-semibold">{getSafeText(deviceToDeactivate?.device_name, 'this device')}</span>
              ?
            </p>
            <p className="text-xs text-default-500">
              Device ID: {getSafeText(deviceToDeactivate?.device_id)}
            </p>
          </ModalBody>
          <ModalFooter>
            <Button variant="bordered" onPress={deactivateModal.onClose} isDisabled={processing.deactivateId !== null}>
              Cancel
            </Button>
            <Button
              color="danger"
              onPress={handleDeactivateDevice}
              isLoading={processing.deactivateId !== null}
            >
              Deactivate
            </Button>
          </ModalFooter>
        </ModalContent>
      </Modal>

      <Modal
        isOpen={detailsModal.isOpen}
        onClose={detailsModal.onClose}
        size="2xl"
        scrollBehavior="inside"
      >
        <ModalContent>
          <ModalHeader>Device Details</ModalHeader>
          <ModalBody>
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
          </ModalBody>
          <ModalFooter>
            <Button color="primary" variant="flat" onPress={detailsModal.onClose}>
              Close
            </Button>
          </ModalFooter>
        </ModalContent>
      </Modal>
    </App>
  );
};

export default UserDevices;
