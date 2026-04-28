import React, { useEffect, useState, useMemo } from 'react';
import {
    Button,
    Input,
    Select,
    SelectItem,
    Textarea,
    Spinner,
    Modal,
    ModalContent,
    ModalHeader,
    ModalBody,
    ModalFooter,
    Skeleton,
    Card,
    CardBody,
    Chip,
    Tooltip,
    Divider,
} from '@heroui/react';
import { 
    CalendarIcon, 
    FileTextIcon, 
    MapPinIcon, 
    ClockIcon,
    Hash,
    Sparkles,
    CheckCircle,
    AlertTriangle
} from 'lucide-react';
import { BuildingOfficeIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { showToast } from '@/utils/toastUtils';
import { getThemeRadius } from '@/Hooks/useThemeRadius.js';
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
        planned_time: currentRow?.planned_time || '',
        type: currentRow?.type || 'Structure',
        location: currentRow?.location || '',
        description: currentRow?.description || '',
        side: currentRow?.side || 'SR-R',
        qty_layer: currentRow?.qty_layer || '',
        status: currentRow?.status || 'new',
        inspection_result: currentRow?.inspection_result || '',
        completion_time: currentRow?.completion_time || '',
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
            suggestedTimes: ['08:00', '13:00', '07:00', '09:00'],
            suggestedTimeLabels: ['Morning shift', 'Afternoon shift', 'Full day', '2-3 hours'],
            defaultLayers: 1
        },
        'Embankment': {
            label: 'Embankment Work',
            description: 'Earthwork, soil stabilization',
            icon: '🏔️',
            color: 'secondary',
            suggestedSides: ['TR-R', 'TR-L', 'Both'],
            suggestedTimes: ['06:00', '08:00', '07:00', '10:00'],
            suggestedTimeLabels: ['Early morning', 'Morning shift', 'Full day', '4-5 hours'],
            defaultLayers: 3
        },
        'Pavement': {
            label: 'Pavement Work',
            description: 'Road surfacing, asphalt laying',
            icon: '🛣️',
            color: 'success',
            suggestedSides: ['TR-R', 'TR-L', 'Both'],
            suggestedTimes: ['22:00', '06:00', '08:00', '14:00'],
            suggestedTimeLabels: ['Night shift', 'Early morning', 'Morning shift', '6-8 hours'],
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

    // Enhanced form validation with backend-compatible rules
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

                // Enhanced chainage format validation (matches backend)
                const chainagePatterns = [
                    /^[A-Z]*K\d+$/,                    // Single chainage: K14, SK14
                    /^[A-Z]*K\d+\+\d+(\.\d+)?$/,      // Chainage with meters: K14+500, K14+500.5
                    /^[A-Z]*K\d+-[A-Z]*K\d+(?:\+\d+(?:\.\d+)?)?$/, // Range: K14-K15, K14+500-K15+200
                    /^[A-Z]*K\d+(?:\+\d+(?:\.\d+)?)?[RL]?(?:-[TR][RL])?$/, // With side indicators
                ];

                const isValidFormat = chainagePatterns.some(pattern => pattern.test(val));
                if (!isValidFormat) {
                    return 'Location must be a valid chainage format (e.g., K14, K14+500, K14-K15, K14+500R)';
                }

                return null;
            },
            description: (val) => {
                if (!val) return 'Description is required';
                if (val.length < 10) return 'Description must be at least 10 characters';
                if (val.length > 500) return 'Description cannot exceed 500 characters';
                return null;
            },
            planned_time: (val) => {
                if (!val) return 'Planned time is required';

                // Enhanced time format validation (HH:MM 24-hour format)
                const timeRegex = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/;
                if (!timeRegex.test(val)) {
                    return 'Planned time must be in HH:MM format (24-hour)';
                }

                return null;
            },
            qty_layer: (val) => {
                const workType = dailyWorkData.type;

                // Required for embankment work
                if (workType === 'Embankment') {
                    if (!val || val.trim() === '') {
                        return 'Layer number is required for embankment work';
                    }

                    // Must be a valid number
                    const numberRegex = /^[0-9]+(\.[0-9]+)?$/;
                    if (!numberRegex.test(val)) {
                        return 'Layer number must be a valid number';
                    }
                }

                // Optional for other work types but must be valid if provided
                if (val && val.trim() !== '') {
                    const numberRegex = /^[0-9]+(\.[0-9]+)?$/;
                    if (!numberRegex.test(val)) {
                        return 'Layer number must be a valid number';
                    }
                }

                return null;
            },
            completion_time: (val) => {
                // Only required if status is completed
                if (dailyWorkData.status === 'completed') {
                    if (!val) return 'Completion time is required for completed work';

                    // Must be valid time format
                    const timeRegex = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/;
                    if (!timeRegex.test(val)) {
                        return 'Completion time must be in HH:MM format (24-hour)';
                    }

                    // Must be after planned time
                    if (dailyWorkData.planned_time) {
                        const plannedTime = new Date(`1970-01-01T${dailyWorkData.planned_time}:00`);
                        const completionTime = new Date(`1970-01-01T${val}:00`);

                        if (completionTime <= plannedTime) {
                            return 'Completion time must be after planned time';
                        }
                    }
                }

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
                    planned_time: prev.planned_time || config.suggestedTimes[0],
                    side: prev.side || config.suggestedSides[0]
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

    // Enhanced form validation with cross-field business rules
    const validateForm = () => {
        const requiredFields = ['date', 'number', 'location', 'description', 'planned_time'];
        const newErrors = {};

        // Validate required fields
        requiredFields.forEach(field => {
            const error = validateField(field, dailyWorkData[field]);
            if (error) {
                newErrors[field] = error;
            }
        });

        // Validate conditional fields
        const qtyLayerError = validateField('qty_layer', dailyWorkData.qty_layer);
        if (qtyLayerError) {
            newErrors.qty_layer = qtyLayerError;
        }

        const completionTimeError = validateField('completion_time', dailyWorkData.completion_time);
        if (completionTimeError) {
            newErrors.completion_time = completionTimeError;
        }

        // Cross-field validation for work types
        if (dailyWorkData.type === 'Embankment') {
            // Embankment work must be on specific sides
            if (!['TR-R', 'TR-L', 'Both'].includes(dailyWorkData.side)) {
                newErrors.side = 'Embankment work must be on TR-R, TR-L, or Both sides';
            }
        }

        if (dailyWorkData.type === 'Pavement') {
            // Pavement work should include specific chainage
            if (!/K\d+\+/.test(dailyWorkData.location)) {
                newErrors.location = 'Pavement work location should include specific chainage (e.g., K14+500)';
            }
        }

        if (dailyWorkData.type === 'Structure') {
            // Structure work should specify kilometer
            if (!/K\d+/.test(dailyWorkData.location)) {
                newErrors.location = 'Structure work location should specify kilometer (e.g., K25)';
            }
        }

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

        showToast.promise(
            promise,
            {
                pending: {
                    render() {
                        return (
                            <div className="flex items-center gap-2">
                                <Spinner size="sm" />
                                <span>{modalType === 'add' ? 'Creating daily work...' : 'Updating daily work...'}</span>
                            </div>
                        );
                    },
                    icon: false
                },
                success: {
                    render({ data }) {
                        return (
                            <div className="flex items-center gap-2">
                                <CheckCircle size={16} className="text-green-500" />
                                <span>{data.join(', ')}</span>
                            </div>
                        );
                    },
                    icon: false
                },
                error: {
                    render({ data }) {
                        return (
                            <div className="flex items-center gap-2">
                                <AlertTriangle size={16} className="text-red-500" />
                                <span>{data.join(', ')}</span>
                            </div>
                        );
                    },
                    icon: false
                }
            }
        );
    }

    // Loading skeleton component
    const FormLoadingSkeleton = () => (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
            {Array.from({ length: 8 }).map((_, index) => (
                <div key={index} className={index === 7 ? "col-span-full" : "col-span-1"}>
                    <div className="space-y-2">
                        <Skeleton className="h-4 w-20 rounded" />
                        <Skeleton className={`h-10 w-full rounded-lg ${index === 7 ? 'h-20' : ''}`} />
                    </div>
                </div>
            ))}
        </div>
    );

    // Get validation icon
    const getValidationIcon = (fieldName) => {
        const status = validationStatus[fieldName];
        if (!dailyWorkData[fieldName]) return null;
        
        if (status === 'success') {
            return <CheckCircle size={16} className="text-green-500" />;
        } else if (status === 'error') {
            return <ExclamationTriangleIcon className="w-4 h-4 text-red-500" />;
        }
        return null;
    };

    return (
        <Modal 
            isOpen={open} 
            onClose={closeModal}
            size="3xl"
            radius={getThemeRadius()}
            scrollBehavior="inside"
            classNames={{
                base: "backdrop-blur-md mx-2 my-2 sm:mx-4 sm:my-8 max-h-[95vh]",
                backdrop: "bg-black/50 backdrop-blur-sm",
                header: "border-b border-divider",
                body: "overflow-y-auto",
                footer: "border-t border-divider",
                closeButton: "hover:bg-white/5 active:bg-white/10"
            }}
            style={{
                border: `var(--borderWidth, 2px) solid var(--theme-divider, #E4E4E7)`,
                borderRadius: `var(--borderRadius, 12px)`,
                fontFamily: `var(--fontFamily, "Inter")`,
                transform: `scale(var(--scale, 1))`,
            }}
        >
            <ModalContent>
                {(onClose) => (
                    <>
                        <ModalHeader className="flex flex-col gap-1" style={{
                            borderColor: `var(--theme-divider, #E4E4E7)`,
                            fontFamily: `var(--fontFamily, "Inter")`,
                        }}>
                            <div className="flex items-center justify-between w-full">
                                <div className="flex items-center gap-2">
                                    <div className="p-2 rounded-lg bg-primary/10">
                                        <FileTextIcon size={20} className="text-primary" />
                                    </div>
                                    <div>
                                        <span className="text-lg font-semibold" style={{
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                        }}>
                                            {currentRow ? 'Edit Daily Work' : 'Add Daily Work'}
                                        </span>
                                        <p className="text-sm text-default-500">
                                            {currentRow ? 'Update work details and status' : 'Create a new daily work entry'}
                                        </p>
                                    </div>
                                </div>
                                
                                {/* Form status indicator */}
                                {dataChanged && !processing && (
                                    <Chip
                                        size="sm"
                                        variant="flat"
                                        color="warning"
                                        startContent={<AlertTriangle size={12} />}
                                    >
                                        Unsaved Changes
                                    </Chip>
                                )}
                            </div>
                        </ModalHeader>
                        <form onSubmit={handleSubmit}>
                            <ModalBody className="py-4 px-4 sm:py-6 sm:px-6" style={{
                                fontFamily: `var(--fontFamily, "Inter")`,
                            }}>
                                {formLoading ? (
                                    <FormLoadingSkeleton />
                                ) : (
                                    <>
                                        {/* Smart work type selector with visual cards */}
                                        <div className="col-span-full mb-4">
                                            <label className="text-sm font-medium text-default-700 mb-2 block">
                                                Work Type Selection
                                            </label>
                                            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                {Object.entries(workTypeConfigs).map(([key, config]) => (
                                                    <Card
                                                        key={key}
                                                        isPressable
                                                        isHoverable
                                                        className={`p-3 cursor-pointer transition-all duration-200 ${
                                                            dailyWorkData.type === key 
                                                                ? 'border-2 border-primary bg-primary/5' 
                                                                : 'border border-divider hover:border-primary/50'
                                                        }`}
                                                        onPress={() => handleChange('type', key)}
                                                        radius={getThemeRadius()}
                                                    >
                                                        <CardBody className="p-0">
                                                            <div className="flex items-center gap-3">
                                                                <div className="text-2xl">{config.icon}</div>
                                                                <div className="flex-1">
                                                                    <div className="font-semibold text-sm">{config.label}</div>
                                                                    <div className="text-xs text-default-500">{config.description}</div>
                                                                </div>
                                                                {dailyWorkData.type === key && (
                                                                    <CheckCircle size={16} className="text-primary" />
                                                                )}
                                                            </div>
                                                        </CardBody>
                                                    </Card>
                                                ))}
                                            </div>
                                        </div>

                                        <Divider className="my-4" />

                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
                                            {/* RFI Date */}
                                            <div className="col-span-1">
                                                <Input
                                                    label="RFI Date"
                                                    type="date"
                                                    value={dailyWorkData.date}
                                                    onValueChange={(value) => handleChange('date', value)}
                                                    isInvalid={Boolean(errors.date)}
                                                    errorMessage={errors.date}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    startContent={<CalendarIcon size={16} className="text-default-400" />}
                                                    endContent={getValidationIcon('date')}
                                                    classNames={{
                                                        input: "text-small",
                                                        inputWrapper: "min-h-unit-10"
                                                    }}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                />
                                            </div>

                                            {/* RFI Number with generator */}
                                            <div className="col-span-1">
                                                <Input
                                                    label="RFI Number"
                                                    value={dailyWorkData.number}
                                                    onValueChange={(value) => handleChange('number', value)}
                                                    isInvalid={Boolean(errors.number)}
                                                    errorMessage={errors.number}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    startContent={<Hash size={16} className="text-default-400" />}
                                                    endContent={
                                                        <div className="flex items-center gap-1">
                                                            {getValidationIcon('number')}
                                                            <Tooltip content="Generate new RFI number">
                                                                <Button
                                                                    isIconOnly
                                                                    size="sm"
                                                                    variant="light"
                                                                    onPress={handleGenerateNewRFI}
                                                                    className="min-w-unit-6 w-unit-6 h-unit-6"
                                                                >
                                                                    <Sparkles size={12} />
                                                                </Button>
                                                            </Tooltip>
                                                        </div>
                                                    }
                                                    classNames={{
                                                        input: "text-small",
                                                        inputWrapper: "min-h-unit-10"
                                                    }}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                />
                                            </div>

                                            {/* Location */}
                                            <div className="col-span-1">
                                                <Input
                                                    label="Location"
                                                    placeholder="e.g., Station 10+500 to 11+000"
                                                    value={dailyWorkData.location}
                                                    onValueChange={(value) => handleChange('location', value)}
                                                    isInvalid={Boolean(errors.location)}
                                                    errorMessage={errors.location}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    startContent={<MapPinIcon size={16} className="text-default-400" />}
                                                    endContent={getValidationIcon('location')}
                                                    classNames={{
                                                        input: "text-small",
                                                        inputWrapper: "min-h-unit-10"
                                                    }}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                />
                                            </div>

                                            {/* Road Type with enhanced selector */}
                                            <div className="col-span-1">
                                                <Select
                                                    label="Road Type"
                                                    placeholder="Select Road Type"
                                                    selectionMode="single"
                                                    selectedKeys={dailyWorkData.side ? new Set([dailyWorkData.side]) : new Set()}
                                                    onSelectionChange={(keys) => {
                                                        const value = Array.from(keys)[0];
                                                        handleChange('side', value || '');
                                                    }}
                                                    isInvalid={Boolean(errors.side)}
                                                    errorMessage={errors.side}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    startContent={<MapPinIcon size={16} className="text-default-400" />}
                                                    classNames={{
                                                        trigger: "min-h-unit-10",
                                                        value: "text-small"
                                                    }}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                >
                                                    {Object.entries(roadTypeConfigs).map(([key, config]) => (
                                                        <SelectItem key={key} value={key} textValue={config.label}>
                                                            <div className="flex flex-col">
                                                                <span className="font-medium">{config.label}</span>
                                                                <span className="text-xs text-default-400">{config.description}</span>
                                                            </div>
                                                        </SelectItem>
                                                    ))}
                                                </Select>
                                            </div>

                                             {/* Status */}
                                             <div className="col-span-1">
                                                 <Select
                                                     label="Status"
                                                     placeholder="Select Status"
                                                     selectionMode="single"
                                                     selectedKeys={dailyWorkData.status ? new Set([dailyWorkData.status]) : new Set()}
                                                     onSelectionChange={(keys) => {
                                                         const value = Array.from(keys)[0];
                                                         handleChange('status', value || '');
                                                     }}
                                                     variant="bordered"
                                                     size="sm"
                                                     radius={getThemeRadius()}
                                                     classNames={{
                                                         trigger: "min-h-unit-10",
                                                         value: "text-small"
                                                     }}
                                                     style={{
                                                         fontFamily: `var(--fontFamily, "Inter")`,
                                                     }}
                                                 >
                                                     <SelectItem key="new" value="new">New</SelectItem>
                                                     <SelectItem key="in-progress" value="in-progress">In Progress</SelectItem>
                                                     <SelectItem key="completed" value="completed">Completed</SelectItem>
                                                     <SelectItem key="rejected" value="rejected">Rejected</SelectItem>
                                                     <SelectItem key="resubmission" value="resubmission">Resubmission</SelectItem>
                                                     <SelectItem key="pending" value="pending">Pending</SelectItem>
                                                 </Select>
                                             </div>

                                             {/* Planned Time */}
                                             <div className="col-span-1">
                                                 <Input
                                                     label="Planned Time"
                                                     type="time"
                                                     placeholder="HH:MM"
                                                     value={dailyWorkData.planned_time}
                                                     onValueChange={(value) => handleChange('planned_time', value)}
                                                     isInvalid={Boolean(errors.planned_time)}
                                                     errorMessage={errors.planned_time}
                                                     variant="bordered"
                                                     size="sm"
                                                     radius={getThemeRadius()}
                                                     startContent={<ClockIcon size={16} className="text-default-400" />}
                                                     endContent={getValidationIcon('planned_time')}
                                                     classNames={{
                                                         input: "text-small",
                                                         inputWrapper: "min-h-unit-10"
                                                     }}
                                                     style={{
                                                         fontFamily: `var(--fontFamily, "Inter")`,
                                                     }}
                                                 />
                                             </div>

                                             {/* Completion Time (conditional) */}
                                             {dailyWorkData.status === 'completed' && (
                                                 <div className="col-span-1">
                                                     <Input
                                                         label="Completion Time *"
                                                         type="time"
                                                         placeholder="HH:MM"
                                                         value={dailyWorkData.completion_time}
                                                         onValueChange={(value) => handleChange('completion_time', value)}
                                                         isInvalid={Boolean(errors.completion_time)}
                                                         errorMessage={errors.completion_time}
                                                         variant="bordered"
                                                         size="sm"
                                                         radius={getThemeRadius()}
                                                         startContent={<ClockIcon size={16} className="text-default-400" />}
                                                         endContent={getValidationIcon('completion_time')}
                                                         classNames={{
                                                             input: "text-small",
                                                             inputWrapper: "min-h-unit-10"
                                                         }}
                                                         style={{
                                                             fontFamily: `var(--fontFamily, "Inter")`,
                                                         }}
                                                     />
                                                 </div>
                                             )}

                                             {/* Inspection Result (conditional) */}
                                             {dailyWorkData.status === 'completed' && (
                                                 <div className="col-span-1">
                                                     <Select
                                                         label="Inspection Result *"
                                                         placeholder="Select Result"
                                                         selectionMode="single"
                                                         selectedKeys={dailyWorkData.inspection_result ? new Set([dailyWorkData.inspection_result]) : new Set()}
                                                         onSelectionChange={(keys) => {
                                                             const value = Array.from(keys)[0];
                                                             handleChange('inspection_result', value || '');
                                                         }}
                                                         variant="bordered"
                                                         size="sm"
                                                         radius={getThemeRadius()}
                                                         classNames={{
                                                             trigger: "min-h-unit-10",
                                                             value: "text-small"
                                                         }}
                                                         style={{
                                                             fontFamily: `var(--fontFamily, "Inter")`,
                                                         }}
                                                     >
                                                         <SelectItem key="pass" value="pass">Pass</SelectItem>
                                                         <SelectItem key="approved" value="approved">Approved</SelectItem>
                                                         <SelectItem key="fail" value="fail">Fail</SelectItem>
                                                         <SelectItem key="rejected" value="rejected">Rejected</SelectItem>
                                                     </Select>
                                                 </div>
                                             )}

                                             {/* Quantity/Layer */}
                                             <div className="col-span-1">
                                                 <Input
                                                     label={`Quantity/Layer No.${dailyWorkData.type === 'Embankment' ? ' *' : ''}`}
                                                     placeholder={dailyWorkData.type === 'Embankment' ? 'Required for embankment (e.g., 3)' : 'Optional (e.g., 3 layers or 150 m³)'}
                                                     value={dailyWorkData.qty_layer}
                                                     onValueChange={(value) => handleChange('qty_layer', value)}
                                                     isInvalid={Boolean(errors.qty_layer)}
                                                     errorMessage={errors.qty_layer}
                                                     variant="bordered"
                                                     size="sm"
                                                     radius={getThemeRadius()}
                                                     startContent={<FileTextIcon size={16} className="text-default-400" />}
                                                     endContent={getValidationIcon('qty_layer')}
                                                     classNames={{
                                                         input: "text-small",
                                                         inputWrapper: "min-h-unit-10"
                                                     }}
                                                     style={{
                                                         fontFamily: `var(--fontFamily, "Inter")`,
                                                     }}
                                                 />
                                             </div>

                                            {/* Description */}
                                            <div className="col-span-full">
                                                <Textarea
                                                    label="Work Description"
                                                    placeholder="Provide detailed description of the work to be performed, including specific requirements, materials, and safety considerations..."
                                                    value={dailyWorkData.description}
                                                    onValueChange={(value) => handleChange('description', value)}
                                                    isInvalid={Boolean(errors.description)}
                                                    errorMessage={errors.description}
                                                    variant="bordered"
                                                    size="sm"
                                                    radius={getThemeRadius()}
                                                    minRows={3}
                                                    maxRows={6}
                                                    endContent={
                                                        <div className="flex flex-col items-end gap-1">
                                                            {getValidationIcon('description')}
                                                            <span className="text-xs text-default-400">
                                                                {dailyWorkData.description?.length || 0}/500
                                                            </span>
                                                        </div>
                                                    }
                                                    classNames={{
                                                        input: "text-small"
                                                    }}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                />
                                            </div>

                                            {/* Smart suggestions based on work type */}
                                            {dailyWorkData.type && workTypeConfigs[dailyWorkData.type] && (
                                                <div className="col-span-full">
                                                    <Card className="bg-primary/5 border border-primary/20" radius={getThemeRadius()}>
                                                        <CardBody className="p-3">
                                                            <div className="flex items-start gap-2">
                                                                <Sparkles size={16} className="text-primary mt-0.5" />
                                                                <div className="flex-1">
                                                                    <h4 className="text-sm font-semibold text-primary mb-2">
                                                                        Smart Suggestions for {workTypeConfigs[dailyWorkData.type].label}
                                                                    </h4>
                                                                    
                                                                     {/* Suggested planned times */}
                                                                     <div className="mb-2">
                                                                         <p className="text-xs text-default-600 mb-1">Recommended times:</p>
                                                                         <div className="flex flex-wrap gap-1">
                                                                             {workTypeConfigs[dailyWorkData.type].suggestedTimes.map((time, index) => (
                                                                                 <Chip
                                                                                     key={index}
                                                                                     size="sm"
                                                                                     variant="flat"
                                                                                     color="primary"
                                                                                     className="cursor-pointer hover:bg-primary/20 transition-colors"
                                                                                     onClick={() => handleChange('planned_time', time)}
                                                                                 >
                                                                                     {workTypeConfigs[dailyWorkData.type].suggestedTimeLabels[index] || time}
                                                                                 </Chip>
                                                                             ))}
                                                                         </div>
                                                                     </div>
                                                                    
                                                                    <p className="text-xs text-default-600">
                                                                        Typical layers: {workTypeConfigs[dailyWorkData.type].defaultLayers}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </CardBody>
                                                    </Card>
                                                </div>
                                            )}
                                        </div>
                                    </>
                                )}
                            </ModalBody>
                            <ModalFooter className="flex flex-col sm:flex-row justify-between items-center gap-3 px-4 sm:px-6 py-3 sm:py-4" style={{
                                borderColor: `var(--theme-divider, #E4E4E7)`,
                                fontFamily: `var(--fontFamily, "Inter")`,
                            }}>
                                {/* Form validation summary */}
                                <div className="flex items-center gap-2 text-sm">
                                    {Object.keys(errors).length > 0 ? (
                                        <div className="flex items-center gap-2 text-danger">
                                            <ExclamationTriangleIcon className="w-4 h-4" />
                                            <span>{Object.keys(errors).length} field{Object.keys(errors).length > 1 ? 's' : ''} need attention</span>
                                        </div>
                                    ) : dataChanged ? (
                                        <div className="flex items-center gap-2 text-success">
                                            <CheckCircle size={16} />
                                            <span>Ready to submit</span>
                                        </div>
                                    ) : (
                                        <span className="text-default-500">Fill in the required fields</span>
                                    )}
                                </div>

                                {/* Action buttons */}
                                <div className="flex gap-2">
                                    <Button
                                        color="default"
                                        variant="bordered"
                                        onPress={onClose}
                                        radius={getThemeRadius()}
                                        size="sm"
                                        isDisabled={processing}
                                        style={{
                                            borderRadius: `var(--borderRadius, 8px)`,
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        color="primary"
                                        variant="solid"
                                        isLoading={processing}
                                        isDisabled={processing || !dataChanged || Object.keys(errors).length > 0}
                                        radius={getThemeRadius()}
                                        size="sm"
                                        startContent={!processing && (modalType === 'add' ? <FileTextIcon size={16} /> : <CheckCircle size={16} />)}
                                        style={{
                                            borderRadius: `var(--borderRadius, 8px)`,
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                        }}
                                    >
                                        {processing 
                                            ? (modalType === 'add' ? 'Creating...' : 'Updating...') 
                                            : (modalType === 'add' ? 'Create Work' : 'Update Work')
                                        }
                                    </Button>
                                </div>
                            </ModalFooter>
                        </form>
                    </>
                )}
            </ModalContent>
        </Modal>
    );
};

export default DailyWorkForm;
