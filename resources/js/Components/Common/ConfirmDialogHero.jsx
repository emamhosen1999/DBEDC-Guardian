import React from 'react';
import { Dialog, Button, Flex, Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon, CheckCircledIcon, InfoCircledIcon } from '@radix-ui/react-icons';

/**
 * HeroUI Confirm Dialog Component
 * A consistent confirmation dialog for destructive or important actions
 */
const ConfirmDialogHero = ({
    open,
    onClose,
    onConfirm,
    title = "Confirm Action",
    message,
    description, // Alias for message
    confirmText = "Confirm",
    cancelText = "Cancel",
    confirmColor = "danger",
    type = "warning", // warning, danger, info, success
    isLoading = false,
    icon
}) => {
    const displayMessage = message || description;

    const iconColorMap = { danger: 'var(--red-9)', warning: 'var(--amber-9)', success: 'var(--green-9)', info: 'var(--blue-9)' };
    const confirmColorMap = { danger: 'red', warning: 'orange', success: 'green', info: 'blue', primary: 'indigo' };

    const getIcon = () => {
        if (icon) return icon;
        if (type === 'success') return <CheckCircledIcon style={{ width: 22, height: 22 }} />;
        if (type === 'info')    return <InfoCircledIcon   style={{ width: 22, height: 22 }} />;
        return <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />;
    };

    const handleConfirm = () => {
        onConfirm();
        if (!isLoading) {
            onClose();
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v && !isLoading) onClose(); }}>
            <Dialog.Content maxWidth="480px">
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <span style={{ color: iconColorMap[type] || iconColorMap.warning }}>{getIcon()}</span>
                        {title}
                    </Flex>
                </Dialog.Title>
                <Dialog.Description size="2" mb="4">{displayMessage}</Dialog.Description>
                <Flex gap="2" justify="end">
                    <Button variant="soft" color="gray" disabled={isLoading} onClick={onClose}>
                        {cancelText}
                    </Button>
                    <Button color={confirmColorMap[confirmColor] || 'red'} loading={isLoading} onClick={handleConfirm}>
                        {confirmText}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default ConfirmDialogHero;
