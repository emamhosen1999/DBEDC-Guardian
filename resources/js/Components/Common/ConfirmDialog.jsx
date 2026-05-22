import React from 'react';
import {
    AlertDialog,
    Button,
    Flex,
    Text,
    Box,
    IconButton,
} from '@radix-ui/themes';
import { XMarkIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

const ConfirmDialog = ({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    confirmColor = 'indigo',
    icon,
}) => {
    const accent = confirmColor === 'error' || confirmColor === 'red' ? 'red' : 'indigo';

    return (
        <AlertDialog.Root open={open} onOpenChange={(v) => { if (!v) onClose?.(); }}>
            <AlertDialog.Content maxWidth="400px">
                <Flex justify="between" align="start" gap="3" mb="3">
                    <Flex align="center" gap="2" style={{ minWidth: 0 }}>
                        {icon ?? (
                            <ExclamationTriangleIcon
                                style={{
                                    width: 22,
                                    height: 22,
                                    color: accent === 'red' ? 'var(--red-11)' : 'var(--amber-11)',
                                    flexShrink: 0,
                                }}
                            />
                        )}
                        <AlertDialog.Title>{title}</AlertDialog.Title>
                    </Flex>
                    <IconButton variant="ghost" color="gray" size="1" onClick={onClose} aria-label="Close">
                        <XMarkIcon style={{ width: 16, height: 16 }} />
                    </IconButton>
                </Flex>
                <AlertDialog.Description mb="4">
                    <Text size="2" color="gray" as="p">{description}</Text>
                </AlertDialog.Description>
                <Flex gap="3" justify="end">
                    <AlertDialog.Cancel>
                        <Button variant="soft" color="gray">{cancelText}</Button>
                    </AlertDialog.Cancel>
                    <AlertDialog.Action>
                        <Button
                            color={accent}
                            onClick={() => {
                                onConfirm?.();
                                onClose?.();
                            }}
                        >
                            {confirmText}
                        </Button>
                    </AlertDialog.Action>
                </Flex>
            </AlertDialog.Content>
        </AlertDialog.Root>
    );
};

export default ConfirmDialog;
