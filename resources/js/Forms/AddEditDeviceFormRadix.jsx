import React, { useState, useEffect } from 'react';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import {
    Dialog,
    Flex,
    Text,
    TextField,
    Switch,
    Button,
} from '@radix-ui/themes';
import * as Select from '@radix-ui/react-select';

const EMPTY_DEVICE = {
    name: '',
    serial_number: '',
    ip_address: '',
    location: '',
    model: '',
    protocol: 'push_sdk',
    is_active: true,
};

export default function AddEditDeviceFormRadix({ 
    device, 
    open, 
    closeModal, 
    onSuccess,
    editMode = false 
}) {
    const [deviceForm, setDeviceForm] = useState(EMPTY_DEVICE);
    const [savingDevice, setSavingDevice] = useState(false);

    useEffect(() => {
        if (editMode && device) {
            setDeviceForm({
                name: device.name,
                serial_number: device.serial_number,
                ip_address: device.ip_address ?? '',
                location: device.location ?? '',
                model: device.model ?? '',
                protocol: device.protocol ?? 'push_sdk',
                is_active: device.is_active,
            });
        } else {
            setDeviceForm(EMPTY_DEVICE);
        }
    }, [device, editMode, open]);

    const saveDevice = async () => {
        if (!deviceForm.name.trim() || !deviceForm.serial_number.trim()) {
            showToast.error('Name and serial number are required.');
            return;
        }
        setSavingDevice(true);
        try {
            if (editMode && device) {
                const { data } = await axios.put(
                    route('biometric-devices.update', device.id),
                    deviceForm
                );
                showToast.success('Device updated.');
                if (onSuccess) onSuccess(data.device);
            } else {
                const { data } = await axios.post(route('biometric-devices.store'), deviceForm);
                showToast.success('Device registered.');
                if (onSuccess) onSuccess(data.device);
            }
            closeModal();
        } catch (err) {
            const msg = err.response?.data?.errors
                ? Object.values(err.response.data.errors).flat().join(' ')
                : err.response?.data?.message ?? 'Failed to save device.';
            showToast.error(msg);
        } finally {
            setSavingDevice(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={closeModal}>
            <Dialog.Content style={{ maxWidth: 450 }}>
                <Dialog.Title>{editMode ? 'Edit Device' : 'Register New Device'}</Dialog.Title>
                <Dialog.Description>
                    {editMode ? 'Update device information.' : 'Register a new biometric device.'}
                </Dialog.Description>
                <Flex direction="column" gap="3" mt="3">
                    <Flex direction="column" gap="1">
                        <Text size="2">Device Name *</Text>
                        <TextField.Root>
                            <TextField.Input
                                placeholder="e.g., Main Entrance"
                                value={deviceForm.name}
                                onChange={e => setDeviceForm(f => ({ ...f, name: e.target.value }))}
                            />
                        </TextField.Root>
                    </Flex>
                    <Flex direction="column" gap="1">
                        <Text size="2">Serial Number *</Text>
                        <TextField.Root>
                            <TextField.Input
                                placeholder="e.g., ABJM12345678"
                                value={deviceForm.serial_number}
                                onChange={e => setDeviceForm(f => ({ ...f, serial_number: e.target.value }))}
                                disabled={editMode}
                            />
                        </TextField.Root>
                        {editMode && <Text size="1" color="gray">Serial number cannot be changed.</Text>}
                    </Flex>
                    <Flex direction="column" gap="1">
                        <Text size="2">IP Address</Text>
                        <TextField.Root>
                            <TextField.Input
                                placeholder="e.g., 192.168.1.100"
                                value={deviceForm.ip_address}
                                onChange={e => setDeviceForm(f => ({ ...f, ip_address: e.target.value }))}
                            />
                        </TextField.Root>
                    </Flex>
                    <Flex direction="column" gap="1">
                        <Text size="2">Location</Text>
                        <TextField.Root>
                            <TextField.Input
                                placeholder="e.g., 3rd Floor, Block B"
                                value={deviceForm.location}
                                onChange={e => setDeviceForm(f => ({ ...f, location: e.target.value }))}
                            />
                        </TextField.Root>
                    </Flex>
                    <Flex direction="column" gap="1">
                        <Text size="2">Model</Text>
                        <TextField.Root>
                            <TextField.Input
                                placeholder="e.g., ZKTeco K40"
                                value={deviceForm.model}
                                onChange={e => setDeviceForm(f => ({ ...f, model: e.target.value }))}
                            />
                        </TextField.Root>
                    </Flex>
                    <Flex direction="column" gap="2">
                        <Text size="2" weight="medium">Communication Protocol</Text>
                        <Select.Root
                            value={deviceForm.protocol}
                            onValueChange={v => setDeviceForm(f => ({ ...f, protocol: v }))}
                        >
                            <Select.Trigger placeholder="Select protocol" />
                            <Select.Content>
                                <Select.Item value="push_sdk">Push SDK (K40, K60, iFace series)</Select.Item>
                                <Select.Item value="adms">ADMS (MB460, MB360)</Select.Item>
                            </Select.Content>
                        </Select.Root>
                        <Text size="1" color="gray">
                            {deviceForm.protocol === 'adms' 
                                ? 'ADMS devices use ZKTeco cloud protocol. No auth token required.'
                                : 'Push SDK devices use standard webhook with auth token.'}
                        </Text>
                    </Flex>
                    <Flex justify="between" align="center">
                        <Text size="2">Active</Text>
                        <Switch
                            checked={deviceForm.is_active}
                            onCheckedChange={v => setDeviceForm(f => ({ ...f, is_active: v }))}
                        />
                    </Flex>
                </Flex>
                <Flex gap="2" justify="end" mt="4">
                    <Dialog.Close>
                        <Button variant="soft" color="red">Cancel</Button>
                    </Dialog.Close>
                    <Button onClick={saveDevice} disabled={savingDevice}>
                        {savingDevice ? 'Saving...' : (editMode ? 'Update Device' : 'Register Device')}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
