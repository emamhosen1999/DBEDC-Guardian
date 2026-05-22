import React from 'react';
import {
    AlertDialog,
    Button,
    Flex,
    Text,
    Box,
} from '@radix-ui/themes';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';

/**
 * Reusable delete confirmation — Radix AlertDialog only.
 */
const DeleteConfirmDialog = ({
    open,
    onClose,
    onConfirm,
    title = 'Confirm deletion',
    description,
    confirmLabel = 'Delete',
    loading = false,
}) => (
    <AlertDialog.Root open={open} onOpenChange={(v) => { if (!v && !loading) onClose?.(); }}>
        <AlertDialog.Content maxWidth="420px">
            <Flex align="start" gap="3" mb="2">
                <Box
                    p="2"
                    style={{
                        borderRadius: 'var(--radius-2)',
                        background: 'var(--red-a3)',
                        color: 'var(--red-11)',
                        flexShrink: 0,
                    }}
                >
                    <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />
                </Box>
                <Box style={{ minWidth: 0 }}>
                    <AlertDialog.Title>{title}</AlertDialog.Title>
                    <AlertDialog.Description mt="2">
                        <Text size="2" color="gray" as="p">
                            {description}
                        </Text>
                    </AlertDialog.Description>
                </Box>
            </Flex>
            <Flex gap="3" justify="end" mt="4">
                <AlertDialog.Cancel>
                    <Button variant="soft" color="gray" disabled={loading}>
                        Cancel
                    </Button>
                </AlertDialog.Cancel>
                <AlertDialog.Action>
                    <Button color="red" loading={loading} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </AlertDialog.Action>
            </Flex>
        </AlertDialog.Content>
    </AlertDialog.Root>
);

export default DeleteConfirmDialog;
