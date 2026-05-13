import React, { useState } from 'react';
import { Dialog, Button, Badge, Box, Flex, Text, TextField, Select } from '@radix-ui/themes';
import { ExclamationTriangleIcon, CheckCircledIcon } from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';

const EnhancedDailyWorkForm = ({ open, closeModal, currentRow, setData, modalType }) => {
    const [dailyWorkData, setDailyWorkData] = useState({
        id: currentRow?.id || '',
        date: currentRow?.date || new Date().toISOString().split('T')[0],
        number: currentRow?.number || '',
        planned_time: currentRow?.planned_time || '',
        type: currentRow?.type || 'Structure',
        location: currentRow?.location || '',
        description: currentRow?.description || '',
        side: currentRow?.side || 'SR-R',
        qty_layer: currentRow?.qty_layer || '',
    });

    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [validationErrors, setValidationErrors] = useState({});
    const [formTouched, setFormTouched] = useState(false);

    // Real-time validation
    const validateField = (name, value) => {
        const newErrors = { ...validationErrors };

        switch (name) {
            case 'number':
                if (!value.trim()) {
                    newErrors.number = 'RFI Number is required.';
                } else if (!/^RFI-\d{4}-\d{3,4}$/.test(value)) {
                    newErrors.number = 'RFI Number must follow format: RFI-YYYY-XXX (e.g., RFI-2025-001)';
                } else {
                    delete newErrors.number;
                }
                break;
                
            case 'location':
                if (!value.trim()) {
                    newErrors.location = 'Location is required.';
                } else if (!/^K\d+(\+\d+)?(-K\d+(\+\d+)?)?$/.test(value)) {
                    newErrors.location = 'Location must follow chainage format (e.g., K5+100 or K5+100-K5+200)';
                } else {
                    delete newErrors.location;
                }
                break;
                
            case 'description':
                if (!value.trim()) {
                    newErrors.description = 'Description is required.';
                } else if (value.length < 10) {
                    newErrors.description = 'Description must be at least 10 characters.';
                } else {
                    delete newErrors.description;
                }
                break;
                
            case 'date':
                if (!value) {
                    newErrors.date = 'Date is required.';
                } else if (new Date(value) > new Date()) {
                    newErrors.date = 'Date cannot be in the future.';
                } else {
                    delete newErrors.date;
                }
                break;
                
            case 'planned_time':
                if (!value.trim()) {
                    newErrors.planned_time = 'Planned time is required.';
                } else if (!/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(value)) {
                    newErrors.planned_time = 'Time must be in HH:MM format (e.g., 09:00)';
                } else {
                    delete newErrors.planned_time;
                }
                break;
                
            case 'qty_layer':
                // No validation needed - field is optional
                delete newErrors.qty_layer;
                break;
        }

        setValidationErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleChange = (event) => {
        const { name, value } = event.target;
        setDailyWorkData(prevData => ({
            ...prevData,
            [name]: value,
        }));
        
        // Mark form as touched
        setFormTouched(true);
        
        // Clear backend errors for this field
        if (errors[name]) {
            setErrors(prev => {
                const newErrors = { ...prev };
                delete newErrors[name];
                return newErrors;
            });
        }
        
        // Real-time validation
        validateField(name, value);
    };

    const validateAllFields = () => {
        let isValid = true;
        Object.keys(dailyWorkData).forEach(key => {
            if (!validateField(key, dailyWorkData[key])) {
                isValid = false;
            }
        });
        return isValid;
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        
        // Validate all fields before submission
        if (!validateAllFields()) {
            showToast.error('Please correct the validation errors before submitting.');
            return;
        }

        setProcessing(true);
        setErrors({});

        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route(`dailyWorks.${modalType}`), {
                    ruleSet: 'details',
                    ...dailyWorkData
                });

                if (response.status === 200) {
                    if (modalType === 'update') {
                        setData(prevWorks => prevWorks.map(work =>
                            work.id === dailyWorkData.id ? { ...work, ...response.data.dailyWork } : work
                        ));
                    } else {
                        setData(prevWorks => [...prevWorks, response.data.dailyWork]);
                    }
                    
                    closeModal();
                    resolve(response.data.message || `Daily work ${modalType === 'add' ? 'created' : 'updated'} successfully!`);
                }
            } catch (error) {
                console.error('Daily work submission error:', error);
                
                let errorMessage = 'An unexpected error occurred. Please try again.';
                
                if (error.response?.status === 422) {
                    // Validation errors from backend
                    const backendErrors = error.response.data.errors || {};
                    setErrors(backendErrors);
                    
                    // Show specific error messages
                    const errorMessages = Object.values(backendErrors).flat();
                    errorMessage = errorMessages.join(', ') || 'Please correct the errors and try again.';
                } else if (error.response?.status === 403) {
                    errorMessage = 'You do not have permission to perform this action.';
                } else if (error.response?.status === 409) {
                    errorMessage = 'A daily work with this RFI number already exists.';
                } else if (error.response?.status === 500) {
                    errorMessage = 'Server error occurred. Please try again later.';
                }
                
                reject(errorMessage);
            } finally {
                setProcessing(false);
            }
        });

        showToast.promise(promise, {
            loading: modalType === 'add' ? 'Creating daily work...' : 'Updating daily work...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };

    const getFieldError = (fieldName) => {
        return errors[fieldName] || validationErrors[fieldName];
    };

    const isFieldInvalid = (fieldName) => {
        return Boolean(getFieldError(fieldName));
    };

    const isFormValid = () => {
        return Object.keys(validationErrors).length === 0 && formTouched;
    };

    const fieldStyle = (fieldName) => isFieldInvalid(fieldName)
        ? { outline: '2px solid var(--red-8)', borderRadius: 6 }
        : {};

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v && !processing) closeModal(); }}>
            <Dialog.Content maxWidth="640px" style={{ fontFamily: `var(--fontFamily,"Inter")` }}>
                <form onSubmit={handleSubmit} noValidate>
                    <Dialog.Title>
                        <Flex align="center" gap="2" wrap="wrap">
                            <Text weight="bold" size={{ initial: '2', sm: '3' }}>
                                {modalType === 'add' ? 'Add Daily Work' : 'Edit Daily Work'}
                            </Text>
                            {isFormValid() && (
                                <Badge size="1" color="green" variant="soft">
                                    <CheckCircledIcon style={{ width: 10, height: 10 }} /> Ready to submit
                                </Badge>
                            )}
                        </Flex>
                    </Dialog.Title>

                    <Box style={{ overflowY: 'auto', maxHeight: 'min(65vh, 480px)' }} py="3">
                        <Flex wrap="wrap" gap="3">
                            {/* RFI Date */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">RFI Date <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <TextField.Root type="date" name="date" value={dailyWorkData.date} onChange={handleChange} style={fieldStyle('date')} />
                                {getFieldError('date') && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{getFieldError('date')}</Text>}
                                {!getFieldError('date') && <Text size="1" color="gray" as="p" mt="1">Date when the work was performed</Text>}
                            </Box>

                            {/* RFI Number */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">RFI Number <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <TextField.Root name="number" value={dailyWorkData.number} onChange={handleChange} placeholder="RFI-2025-001" style={fieldStyle('number')} />
                                {getFieldError('number') && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{getFieldError('number')}</Text>}
                                {!getFieldError('number') && <Text size="1" color="gray" as="p" mt="1">Unique RFI identifier</Text>}
                            </Box>

                            {/* Planned Time */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">Planned Time <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <TextField.Root name="planned_time" value={dailyWorkData.planned_time} onChange={handleChange} placeholder="09:00" style={fieldStyle('planned_time')} />
                                {getFieldError('planned_time') && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{getFieldError('planned_time')}</Text>}
                                {!getFieldError('planned_time') && <Text size="1" color="gray" as="p" mt="1">Time in HH:MM format</Text>}
                            </Box>

                            {/* Work Type */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">Work Type <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <Select.Root value={dailyWorkData.type} onValueChange={(v) => handleChange({ target: { name: 'type', value: v } })}>
                                    <Select.Trigger style={{ width: '100%', ...fieldStyle('type') }} />
                                    <Select.Content>
                                        <Select.Item value="Structure">Structure</Select.Item>
                                        <Select.Item value="Embankment">Embankment</Select.Item>
                                        <Select.Item value="Pavement">Pavement</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                                <Text size="1" color="gray" as="p" mt="1">Type of construction work</Text>
                            </Box>

                            {/* Location */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">Location <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <TextField.Root name="location" value={dailyWorkData.location} onChange={handleChange} placeholder="K5+100 or K5+100-K5+200" style={fieldStyle('location')} />
                                {getFieldError('location') && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{getFieldError('location')}</Text>}
                                {!getFieldError('location') && <Text size="1" color="gray" as="p" mt="1">Chainage location</Text>}
                            </Box>

                            {/* Description */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">Description <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <TextField.Root name="description" value={dailyWorkData.description} onChange={handleChange} placeholder="Detailed work description" style={fieldStyle('description')} />
                                {getFieldError('description') && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{getFieldError('description')}</Text>}
                                {!getFieldError('description') && <Text size="1" color="gray" as="p" mt="1">Minimum 10 characters</Text>}
                            </Box>

                            {/* Road Side */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">Road Side <Text style={{ color: 'var(--red-9)' }}>*</Text></Text>
                                <Select.Root value={dailyWorkData.side} onValueChange={(v) => handleChange({ target: { name: 'side', value: v } })}>
                                    <Select.Trigger style={{ width: '100%', ...fieldStyle('side') }} />
                                    <Select.Content>
                                        <Select.Item value="SR-R">Service Road - Right</Select.Item>
                                        <Select.Item value="SR-L">Service Road - Left</Select.Item>
                                        <Select.Item value="TR-R">Through Road - Right</Select.Item>
                                        <Select.Item value="TR-L">Through Road - Left</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                                <Text size="1" color="gray" as="p" mt="1">Which side of the road</Text>
                            </Box>

                            {/* Qty/Layer */}
                            <Box>
                                <Text size="1" color="gray" as="p" mb="1">
                                    {dailyWorkData.type === 'Embankment' ? 'Layer Number' : 'Quantity/Layer'}
                                </Text>
                                <TextField.Root
                                    name="qty_layer"
                                    value={dailyWorkData.qty_layer}
                                    onChange={handleChange}
                                    placeholder={dailyWorkData.type === 'Embankment' ? 'Layer 1 (optional)' : 'Quantity or layer info'}
                                />
                                <Text size="1" color="gray" as="p" mt="1">Optional quantity or layer information</Text>
                            </Box>
                        </Flex>
                    </Box>

                    <Box mt="4" p="3" style={{ background: 'var(--red-a3)', border: '1px solid var(--red-a6)', borderRadius: 'var(--radius-2)' }}>
                        <Flex align="start" gap="2">
                            <ExclamationTriangleIcon style={{ width: 16, height: 16, color: 'var(--red-9)' }} />
                            <Text size="1" style={{ color: 'var(--red-11)' }} as="p">
                                <Text weight="bold">Important:</Text> Ensure all required fields are filled correctly before submitting.
                            </Text>
                        </Flex>
                    </Box>

                    <Flex gap="2" justify="end" mt="4">
                        <Button type="button" variant="ghost" color="gray" onClick={closeModal} disabled={processing} style={{ width: '100%' }}>Cancel</Button>
                        <Button type="submit" color="indigo" loading={processing} disabled={!isFormValid() || processing} style={{ width: '100%' }}>
                            {modalType === 'add' ? 'Add Daily Work' : 'Update Daily Work'}
                        </Button>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default EnhancedDailyWorkForm;
