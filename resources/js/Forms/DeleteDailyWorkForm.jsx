import { Dialog, Button, Flex, Box, Text } from '@radix-ui/themes';
import React from "react";
import { ExclamationTriangleIcon, TrashIcon } from "@radix-ui/react-icons";

const DeleteDailyWorkForm = ({ open, handleClose, handleDelete, isLoading = false }) => {
    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v && !isLoading) handleClose(); }}>
            <Dialog.Content maxWidth="480px" style={{ fontFamily: `var(--fontFamily,"Inter")` }}>
                <Dialog.Title>
                    <Flex align="center" gap="2">
                        <Box p="2" style={{ borderRadius: 8, background: 'var(--red-a3)' }}>
                            <ExclamationTriangleIcon style={{ width: 20, height: 20, color: 'var(--red-9)' }} />
                        </Box>
                        <Flex direction="column">
                            <Text size="4" weight="bold" style={{ color: 'var(--red-11)' }}>Confirm Deletion</Text>
                            <Text size="2" color="gray" as="p">This action cannot be undone</Text>
                        </Flex>
                    </Flex>
                </Dialog.Title>

                <Box py="3">
                    <Flex align="start" gap="3">
                        <Box p="3" style={{ borderRadius: '50%', background: 'var(--red-a3)', flexShrink: 0 }}>
                            <TrashIcon style={{ width: 24, height: 24, color: 'var(--red-9)' }} />
                        </Box>
                        <Flex direction="column" style={{ flex: 1 }}>
                            <Text size="2" as="p" style={{ lineHeight: 1.6 }}>
                                Are you sure you want to delete this daily work entry?
                                This action will permanently remove the work record and cannot be undone.
                            </Text>
                            <Box mt="3" p="3" style={{ background: 'var(--amber-a3)', border: '1px solid var(--amber-a6)', borderRadius: 'var(--radius-2)' }}>
                                <Text size="2" weight="medium" style={{ color: 'var(--amber-11)' }}>
                                    ⚠️ Warning: All associated data will be permanently lost.
                                </Text>
                            </Box>
                        </Flex>
                    </Flex>
                </Box>

                <Flex justify="end" gap="2" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                    <Button variant="outline" color="gray" onClick={handleClose} disabled={isLoading} size="2">
                        Cancel
                    </Button>
                    <Button color="red" onClick={handleDelete} loading={isLoading} disabled={isLoading} size="2">
                        {!isLoading && <TrashIcon style={{ width: 16, height: 16 }} />}
                        {isLoading ? 'Deleting...' : 'Delete Work'}
                    </Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DeleteDailyWorkForm;
