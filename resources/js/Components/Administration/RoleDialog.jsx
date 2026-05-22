import React, { useState, useEffect } from 'react';
import {
    Dialog,
    TextField,
    Button,
    Flex,
    Text,
    IconButton,
    Spinner,
    TextArea,
} from '@radix-ui/themes';
import { XMarkIcon } from '@heroicons/react/24/outline';

const fieldError = (errors, field) => {
    const e = errors[field];
    if (!e) return null;
    return Array.isArray(e) ? e[0] : e;
};

const RoleDialog = ({ open, onClose, onSave, role, title, isEdit }) => {
    const [formData, setFormData] = useState({
        name: '',
        description: ''
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (role) {
            setFormData({
                name: role.name || '',
                description: role.description || ''
            });
        } else {
            setFormData({
                name: '',
                description: ''
            });
        }
        setErrors({});
    }, [role, open]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            await onSave(formData);
        } catch (error) {
            if (error.response?.data?.errors) {
                setErrors(error.response.data.errors);
            }
        } finally {
            setProcessing(false);
        }
    };

    const handleChange = (field) => (e) => {
        setFormData(prev => ({
            ...prev,
            [field]: e.target.value
        }));
        if (errors[field]) {
            setErrors(prev => ({
                ...prev,
                [field]: null
            }));
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
            <Dialog.Content style={{ maxWidth: 480 }}>
                <Flex justify="between" align="center" mb="3">
                    <Dialog.Title>{title}</Dialog.Title>
                    <Dialog.Close>
                        <IconButton variant="ghost" color="gray" aria-label="Close">
                            <XMarkIcon className="w-5 h-5" />
                        </IconButton>
                    </Dialog.Close>
                </Flex>

                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4">
                        <BoxField label="Role Name" required error={fieldError(errors, 'name')}>
                            <TextField.Root
                                value={formData.name}
                                onChange={handleChange('name')}
                                disabled={processing}
                                autoFocus
                            />
                        </BoxField>

                        <BoxField label="Description" error={fieldError(errors, 'description')}>
                            <TextArea
                                value={formData.description}
                                onChange={handleChange('description')}
                                disabled={processing}
                                rows={3}
                            />
                        </BoxField>
                    </Flex>

                    <Flex gap="3" justify="end" mt="5">
                        <Button type="button" variant="soft" color="gray" onClick={onClose} disabled={processing}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || !formData.name.trim()}
                        >
                            {processing && <Spinner size="1" />}
                            {processing ? 'Saving...' : (isEdit ? 'Update' : 'Create')}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

const BoxField = ({ label, required, error, children }) => (
    <Flex direction="column" gap="1">
        <Text as="label" size="2" weight="medium">
            {label}{required && <Text color="red"> *</Text>}
        </Text>
        {children}
        {error && <Text size="1" color="red">{error}</Text>}
    </Flex>
);

export default RoleDialog;
