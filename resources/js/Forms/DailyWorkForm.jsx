import React, { useEffect, useState, useMemo } from 'react';
import { Dialog, Button, Badge, Separator, Box, Flex, Text, TextField, TextArea, Select, Tooltip } from '@radix-ui/themes';
import { FileTextIcon, CheckCircledIcon, ExclamationTriangleIcon, StarFilledIcon } from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';


const DailyWorkForm = ({ open, closeModal, currentRow, setData, modalType}) => {

    // Auto-generate RFI number
    const generateRFINumber = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const time = String(now.getTime()).slice(-6); // Last 6 digits of timestamp
        return `RFI-${year}${month}${day}-${time}`;
    };

    const [dailyWorkData, setDailyWorkData] = useState({
        id: currentRow?.id || '',
        date: currentRow?.date ? new Date(currentRow.date).toISOString().split('T')[0] : new Date().toISOString().split('T')[0],
        number: currentRow?.number || (modalType === 'add' ? generateRFINumber() : ''),
        planned_time: currentRow?.planned_time || 'Morning shift',
        type: currentRow?.type || 'Structure',
        location: currentRow?.location || '',
        description: currentRow?.description || '',
        side: currentRow?.side || 'SR-R',
        qty_layer: currentRow?.qty_layer || '',
    });

    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [dataChanged, setDataChanged] = useState(false);
    const [formLoading, setFormLoading] = useState(true);
    const [validationStatus, setValidationStatus] = useState({});

    // Work type configurations with enhanced metadata
    const workTypeConfigs = useMemo(() => ({
        'Structure': {
            label: 'Structure Work',
            description: 'Concrete structures, bridges, culverts',
            icon: '🏗️',
            color: 'primary',
            suggestedSides: ['SR-R', 'SR-L', 'Both'],
            suggestedTimes: ['Morning shift', 'Afternoon shift', 'Full day', '2-3 hours'],
            defaultLayers: 1
        },
        'Embankment': {
            label: 'Embankment Work',
            description: 'Earthwork, soil stabilization',
            icon: '🏔️',
            color: 'secondary',
            suggestedSides: ['SR-R', 'SR-L', 'Both'],
            suggestedTimes: ['Early morning', 'Morning shift', 'Full day', '4-5 hours'],
            defaultLayers: 3
        },
        'Pavement': {
            label: 'Pavement Work',
            description: 'Road surfacing, asphalt laying',
            icon: '🛣️',
            color: 'success',
            suggestedSides: ['TR-R', 'TR-L', 'Both'],
            suggestedTimes: ['Night shift', 'Early morning', 'Morning shift', '6-8 hours'],
            defaultLayers: 2
        }
    }), []);

    // Road type configurations
    const roadTypeConfigs = useMemo(() => ({
        'SR-R': { label: 'Service Road - Right', description: 'Right side service road' },
        'SR-L': { label: 'Service Road - Left', description: 'Left side service road' },
        'TR-R': { label: 'Through Road - Right', description: 'Right lane main road' },
        'TR-L': { label: 'Through Road - Left', description: 'Left lane main road' },
        'Both': { label: 'Both Sides', description: 'Both sides of the road' }
    }), []);

    // Form validation with real-time feedback
    const validateField = (name, value) => {
        const validations = {
            date: (val) => {
                if (!val) return 'Date is required';
                const selectedDate = new Date(val);
                const today = new Date();
                const thirtyDaysAgo = new Date(today.setDate(today.getDate() - 30));
                if (selectedDate < thirtyDaysAgo) return 'Date cannot be more than 30 days ago';
                return null;
            },
            number: (val) => {
                if (!val) return 'RFI Number is required';
                if (val.length < 5) return 'RFI Number must be at least 5 characters';
                return null;
            },
            location: (val) => {
                if (!val) return 'Location is required';
                if (val.length < 3) return 'Location must be at least 3 characters';
                return null;
            },
            description: (val) => {
                if (!val) return 'Description is required';
                if (val.length < 10) return 'Description must be at least 10 characters';
                return null;
            },
            planned_time: (val) => {
                if (!val) return 'Planned time is required';
                if (val.length < 2) return 'Planned time must be at least 2 characters';
                return null;
            }
        };

        const error = validations[name]?.(value);
        
        // For edit mode, don't show error initially if field has a value
        if (modalType === 'update' && value && value.toString().trim() !== '' && !error) {
            setValidationStatus(prev => ({
                ...prev,
                [name]: 'success'
            }));
        } else {
            setValidationStatus(prev => ({
                ...prev,
                [name]: error ? 'error' : 'success'
            }));
        }
        
        return error;
    };

    // Initialize form loading simulation
    useEffect(() => {
        const timer = setTimeout(() => {
            setFormLoading(false);
            
            // For edit mode, clear any initial validation errors for fields with values
            if (modalType === 'update' && currentRow) {
                const initialErrors = {};
                const initialValidationStatus = {};
                
                Object.entries(dailyWorkData).forEach(([key, value]) => {
                    if (value && value.toString().trim() !== '') {
                        initialValidationStatus[key] = 'success';
                        // Don't include in errors if field has a valid value
                        const error = validateField(key, value);
                        if (!error) {
                            delete initialErrors[key];
                        }
                    }
                });
                
                setValidationStatus(initialValidationStatus);
                setErrors(initialErrors);
            }
        }, 800);
        return () => clearTimeout(timer);
    }, [modalType, currentRow, dailyWorkData]);

    // Smart defaults based on work type
    useEffect(() => {
        if (dailyWorkData.type && workTypeConfigs[dailyWorkData.type]) {
            const config = workTypeConfigs[dailyWorkData.type];
            if (!currentRow) { // Only for new forms
                setDailyWorkData(prev => ({
                    ...prev,
                    qty_layer: prev.qty_layer || String(config.defaultLayers),
                    planned_time: prev.planned_time || config.suggestedTimes[0]
                }));
            }
        }
    }, [dailyWorkData.type, currentRow, workTypeConfigs]);

    useEffect(() => {
        // Check if any field is changed
        const hasChanges = Object.entries(dailyWorkData).some(([key, value]) => {
            if (currentRow) {
                return value !== (currentRow[key] || '');
            }
            return value !== '';
        });
        setDataChanged(hasChanges);

        // For edit mode, initialize validation status to success for fields with values
        if (currentRow && modalType === 'update') {
            const initialValidationStatus = {};
            Object.entries(dailyWorkData).forEach(([key, value]) => {
                if (value && value.toString().trim() !== '') {
                    initialValidationStatus[key] = 'success';
                }
            });
            setValidationStatus(prev => ({ ...prev, ...initialValidationStatus }));
        }
    }, [dailyWorkData, currentRow, modalType]);

    const handleChange = (name, value) => {
        setDailyWorkData(prevData => ({
            ...prevData,
            [name]: value,
        }));
        
        // Real-time validation
        const error = validateField(name, value);
        if (error) {
            setErrors(prev => ({ ...prev, [name]: error }));
        } else {
            setErrors(prev => {
                const newErrors = { ...prev };
                delete newErrors[name];
                return newErrors;
            });
        }
        
        setDataChanged(true);
    };

    // Generate new RFI number
    const handleGenerateNewRFI = () => {
        const newNumber = generateRFINumber();
        handleChange('number', newNumber);
        showToast.success('New RFI number generated!');
    };

    // Validate entire form
    const validateForm = () => {
        const requiredFields = ['date', 'number', 'location', 'description', 'planned_time'];
        const newErrors = {};
        
        requiredFields.forEach(field => {
            const error = validateField(field, dailyWorkData[field]);
            if (error) {
                newErrors[field] = error;
            }
        });
        
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    async function handleSubmit(event) {
        event.preventDefault();
        
        // Validate form before submission
        if (!validateForm()) {
            showToast.error('Please fix all validation errors before submitting');
            return;
        }

        setProcessing(true);
        
        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route(`dailyWorks.${modalType}`), {
                    ruleSet: 'details',
                    ...dailyWorkData
                });

                if (response.status === 200) {
                    if (modalType === 'add') {
                        setData(prevWorks => [response.data.dailyWork, ...prevWorks]);
                    } else {
                        setData(prevWorks => prevWorks.map(work =>
                            work.id === dailyWorkData.id ? response.data.dailyWork : work
                        ));
                    }

                    closeModal();
                    resolve([response.data.message || `Daily work ${modalType === 'add' ? 'created' : 'updated'} successfully`]);
                }
            } catch (error) {
                console.error('Form submission error:', error);
                if (error.response?.status === 422) {
                    const validationErrors = error.response.data.errors || {};
                    setErrors(validationErrors);
                    
                    // Set validation status for each error
                    Object.keys(validationErrors).forEach(field => {
                        setValidationStatus(prev => ({ ...prev, [field]: 'error' }));
                    });
                    
                    reject([error.response.data.message || 'Please check the form for errors']);
                } else {
                    reject([error.response?.data?.message || 'An unexpected error occurred. Please try again.']);
                }
            } finally {
                setProcessing(false);
            }
        });

        showToast.promise(promise, {
            loading: modalType === 'add' ? 'Creating daily work...' : 'Updating daily work...',
            success: (data) => data.join(', '),
            error: (data) => data.join(', '),
        });
    }

    // Loading skeleton component
    const FormLoadingSkeleton = () => (
        <Flex wrap="wrap" gap="3">
            {Array.from({ length: 8 }).map((_, index) => (
                <Box key={index} style={{ flex: index === 7 ? '1 0 100%' : '1 0 45%', minWidth: 200 }}>
                    <Flex direction="column" gap="2">
                        <Box style={{ height: 16, width: 80, borderRadius: 4, background: 'var(--gray-a4)' }} />
                        <Box style={{ width: '100%', borderRadius: 8, height: index === 7 ? 80 : 40, background: 'var(--gray-a4)' }} />
                    </Flex>
                </Box>
            ))}
        </Flex>
    );

    // Get validation icon
    const getValidationIcon = (fieldName) => {
        const status = validationStatus[fieldName];
        if (!dailyWorkData[fieldName]) return null;
        if (status === 'success') return <CheckCircledIcon style={{ width: 14, height: 14, color: 'var(--green-9)' }} />;
        if (status === 'error') return <ExclamationTriangleIcon style={{ width: 14, height: 14, color: 'var(--red-9)' }} />;
        return null;
    };

    return (
        <Dialog.Root open={open} onOpenChange={(v) => { if (!v && !processing) closeModal(); }}>
            <Dialog.Content maxWidth="720px" style={{ fontFamily: `var(--fontFamily,"Inter")` }}>
                <form onSubmit={handleSubmit}>
                    <Dialog.Title>
                        <Flex align="center" justify="between" wrap="wrap" gap="2">
                            <Flex align="center" gap="2">
                                <Box p="2" style={{ borderRadius: 8, background: 'var(--accent-a3)' }}>
                                    <FileTextIcon style={{ width: 20, height: 20, color: 'var(--accent-9)' }} />
                                </Box>
                                <Flex direction="column">
                                    <Text weight="bold" size={{ initial: '2', sm: '3' }} as="p">
                                        {currentRow ? 'Edit Daily Work' : 'Add Daily Work'}
                                    </Text>
                                    <Text size="1" color="gray" as="p">
                                        {currentRow ? 'Update work details and status' : 'Create a new daily work entry'}
                                    </Text>
                                </Flex>
                            </Flex>
                            {dataChanged && !processing && (
                                <Badge color="amber" variant="soft" size="1">
                                    <ExclamationTriangleIcon style={{ width: 10, height: 10 }} /> Unsaved Changes
                                </Badge>
                            )}
                        </Flex>
                    </Dialog.Title>

                    <Box style={{ overflowY: 'auto', maxHeight: 'min(65vh, 520px)' }} py="3">
                        {formLoading ? (
                            <FormLoadingSkeleton />
                        ) : (
                            <>
                                {/* Work type selector */}
                                <Box mb="4">
                                    <Text size="1" weight="medium" color="gray" as="p" mb="2">Work Type Selection</Text>
                                    <Flex wrap="wrap" gap="3">
                                        {Object.entries(workTypeConfigs).map(([key, config]) => (
                                            <button
                                                key={key}
                                                type="button"
                                                onClick={() => handleChange('type', key)}
                                                style={{ padding: 12, textAlign: 'left', width: '100%', cursor: 'pointer', border: `2px solid ${dailyWorkData.type === key ? 'var(--accent-8)' : 'var(--gray-a4)'}`, background: dailyWorkData.type === key ? 'var(--accent-a2)' : 'transparent', borderRadius: 8, transition: 'all 0.2s' }}
                                            >
                                                <Flex align="center" gap="3">
                                                    <Text size="5">{config.icon}</Text>
                                                    <Flex direction="column" style={{ flex: 1 }}>
                                                        <Text size="2" weight="bold" as="p">{config.label}</Text>
                                                        <Text size="1" color="gray" as="p">{config.description}</Text>
                                                    </Flex>
                                                    {dailyWorkData.type === key && <CheckCircledIcon style={{ width: 16, height: 16, color: 'var(--accent-9)', flexShrink: 0 }} />}
                                                </Flex>
                                            </button>
                                        ))}
                                    </Flex>
                                </Box>

                                <Separator size="4" mb="4" />

                                <Flex wrap="wrap" gap="3">
                                    {/* RFI Date */}
                                    <Box>
                                        <Text size="1" color="gray" as="p" mb="1">RFI Date *</Text>
                                        <Flex align="center" gap="1">
                                            <TextField.Root type="date" value={dailyWorkData.date} onChange={(e) => handleChange('date', e.target.value)} style={{ flex: 1, ...(errors.date ? { outline: '2px solid var(--red-8)', borderRadius: 6 } : {}) }} />
                                            {getValidationIcon('date')}
                                        </Flex>
                                        {errors.date && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{errors.date}</Text>}
                                    </Box>

                                    {/* RFI Number */}
                                    <Box>
                                        <Text size="1" color="gray" as="p" mb="1">RFI Number *</Text>
                                        <Flex align="center" gap="1">
                                            <TextField.Root value={dailyWorkData.number} onChange={(e) => handleChange('number', e.target.value)} style={{ flex: 1, ...(errors.number ? { outline: '2px solid var(--red-8)', borderRadius: 6 } : {}) }} />
                                            <Tooltip content="Generate new RFI number">
                                                <Button type="button" variant="ghost" size="1" onClick={handleGenerateNewRFI}>
                                                    <StarFilledIcon style={{ width: 12, height: 12 }} />
                                                </Button>
                                            </Tooltip>
                                            {getValidationIcon('number')}
                                        </Flex>
                                        {errors.number && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{errors.number}</Text>}
                                    </Box>

                                    {/* Location */}
                                    <Box>
                                        <Text size="1" color="gray" as="p" mb="1">Location *</Text>
                                        <Flex align="center" gap="1">
                                            <TextField.Root placeholder="e.g., Station 10+500 to 11+000" value={dailyWorkData.location} onChange={(e) => handleChange('location', e.target.value)} style={{ flex: 1, ...(errors.location ? { outline: '2px solid var(--red-8)', borderRadius: 6 } : {}) }} />
                                            {getValidationIcon('location')}
                                        </Flex>
                                        {errors.location && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{errors.location}</Text>}
                                    </Box>

                                    {/* Road Type */}
                                    <Box>
                                        <Text size="1" color="gray" as="p" mb="1">Road Type</Text>
                                        <Select.Root value={dailyWorkData.side} onValueChange={(v) => handleChange('side', v)}>
                                            <Select.Trigger style={{ width: '100%' }} />
                                            <Select.Content>
                                                {Object.entries(roadTypeConfigs).map(([key, config]) => (
                                                    <Select.Item key={key} value={key}>
                                                        <Flex direction="column">
                                                            <Text size="2" weight="medium">{config.label}</Text>
                                                            <Text size="1" style={{ color: 'var(--gray-11)' }}>{config.description}</Text>
                                                        </Flex>
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>

                                    {/* Planned Time */}
                                    <Box>
                                        <Text size="1" color="gray" as="p" mb="1">Planned Time *</Text>
                                        <Flex align="center" gap="1">
                                            <TextField.Root placeholder="e.g., Morning shift, 2-3 hours, Full day" value={dailyWorkData.planned_time} onChange={(e) => handleChange('planned_time', e.target.value)} style={{ flex: 1, ...(errors.planned_time ? { outline: '2px solid var(--red-8)', borderRadius: 6 } : {}) }} />
                                            {getValidationIcon('planned_time')}
                                        </Flex>
                                        {errors.planned_time && <Text size="1" style={{ color: 'var(--red-11)' }} as="p" mt="1">{errors.planned_time}</Text>}
                                    </Box>

                                    {/* Qty/Layer */}
                                    <Box>
                                        <Text size="1" color="gray" as="p" mb="1">Quantity/Layer No.</Text>
                                        <TextField.Root placeholder="e.g., 3 layers or 150 m³" value={dailyWorkData.qty_layer} onChange={(e) => handleChange('qty_layer', e.target.value)} />
                                    </Box>

                                    {/* Description */}
                                    <Box style={{ flex: '1 0 100%' }}>
                                        <Text size="1" color="gray" as="p" mb="1">Work Description *</Text>
                                        <TextArea placeholder="Provide detailed description of the work to be performed..." value={dailyWorkData.description} onChange={(e) => handleChange('description', e.target.value)} rows={3} style={errors.description ? { outline: '2px solid var(--red-8)' } : {}} />
                                        <Flex justify="between" mt="1">
                                            {errors.description ? <Text size="1" style={{ color: 'var(--red-11)' }}>{errors.description}</Text> : <span />}
                                            <Text size="1" color="gray">{dailyWorkData.description?.length || 0}/500</Text>
                                        </Flex>
                                    </Box>
                                </Flex>

                                {/* Smart suggestions */}
                                {dailyWorkData.type && workTypeConfigs[dailyWorkData.type] && (
                                    <Box mt="3" p="3" style={{ background: 'var(--accent-a2)', border: '1px solid var(--accent-a4)', borderRadius: 'var(--radius-2)' }}>
                                        <Flex align="start" gap="2">
                                            <StarFilledIcon style={{ width: 16, height: 16, color: 'var(--accent-9)', flexShrink: 0, marginTop: 2 }} />
                                            <Flex direction="column" style={{ flex: 1 }}>
                                                <Text size="2" weight="bold" style={{ color: 'var(--accent-11)' }} as="p" mb="2">
                                                    Smart Suggestions for {workTypeConfigs[dailyWorkData.type].label}
                                                </Text>
                                                <Flex direction="column">
                                                    <Text size="1" color="gray" as="p" mb="1">Recommended times:</Text>
                                                    <Flex wrap="wrap" gap="1">
                                                        {workTypeConfigs[dailyWorkData.type].suggestedTimes.map((time, index) => (
                                                            <Badge key={index} size="1" variant="soft" color="indigo" style={{ cursor: 'pointer' }} onClick={() => handleChange('planned_time', time)}>{time}</Badge>
                                                        ))}
                                                    </Flex>
                                                </Flex>
                                                <Text size="1" color="gray" as="p" mt="1">Typical layers: {workTypeConfigs[dailyWorkData.type].defaultLayers}</Text>
                                            </Flex>
                                        </Flex>
                                    </Box>
                                )}
                            </>
                        )}
                    </Box>

                    <Flex justify="between" align="center" pt="3" style={{ borderTop: '1px solid var(--gray-a4)', flexWrap: 'wrap', gap: 8 }}>
                        <Flex direction="column">
                            {Object.keys(errors).length > 0 ? (
                                <Flex align="center" gap="1"><ExclamationTriangleIcon style={{ width: 14, height: 14, color: 'var(--red-9)' }} /><Text size="1" style={{ color: 'var(--red-11)' }}>{Object.keys(errors).length} field{Object.keys(errors).length > 1 ? 's' : ''} need attention</Text></Flex>
                            ) : dataChanged ? (
                                <Flex align="center" gap="1"><CheckCircledIcon style={{ width: 14, height: 14, color: 'var(--green-9)' }} /><Text size="1" style={{ color: 'var(--green-11)' }}>Ready to submit</Text></Flex>
                            ) : (
                                <Text size="1" color="gray">Fill in the required fields</Text>
                            )}
                        </Flex>
                        <Flex gap="2">
                            <Button type="button" variant="outline" color="gray" onClick={closeModal} disabled={processing} size="2">Cancel</Button>
                            <Button type="submit" color="indigo" loading={processing} disabled={processing || !dataChanged || Object.keys(errors).length > 0} size="2">
                                {!processing && (modalType === 'add' ? <FileTextIcon style={{ width: 14, height: 14 }} /> : <CheckCircledIcon style={{ width: 14, height: 14 }} />)}
                                {processing ? (modalType === 'add' ? 'Creating...' : 'Updating...') : (modalType === 'add' ? 'Create Work' : 'Update Work')}
                            </Button>
                        </Flex>
                    </Flex>
                </form>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default DailyWorkForm;
