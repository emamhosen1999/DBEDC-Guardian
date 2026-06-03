import {
    Box, Flex, Grid, Text, Heading, IconButton, Card, Separator,
    Checkbox, Switch, RadioGroup, Radio, Spinner, Skeleton, ScrollArea,
    Tabs, Tooltip, Progress, Callout, Inset,
    Dialog as RadixDialog,
    Select as RadixSelect,
    TextField as RadixTextField,
    TextArea,
    Table as RadixTable,
    DropdownMenu as RadixDropdownMenu,
    Button as RadixButton,
    Badge as RadixBadge,
} from '@radix-ui/themes';
import React, { useEffect, useState, useCallback, useMemo, useRef } from 'react';

const CardHeader = ({ children, className, ...props }) => (
  <Box className={className} {...props}>{children}</Box>
);

const CardBody = ({ children, className, ...props }) => (
  <Box className={className} {...props}>{children}</Box>
);

const Dropdown = ({ children }) => (
  <RadixDropdownMenu.Root>{children}</RadixDropdownMenu.Root>
);
const DropdownTrigger = ({ children }) => (
  <RadixDropdownMenu.Trigger>{children}</RadixDropdownMenu.Trigger>
);
const DropdownMenu = ({ children, ...props }) => (
  <RadixDropdownMenu.Content {...props}>{children}</RadixDropdownMenu.Content>
);
const DropdownItem = ({ children, onClick, ...props }) => (
  <RadixDropdownMenu.Item onClick={onClick} {...props}>{children}</RadixDropdownMenu.Item>
);

const Table = ({ children }) => (
  <RadixTable.Root variant="surface">{children}</RadixTable.Root>
);

const TableHeader = ({ columns, children }) => (
  <RadixTable.Header>
    <RadixTable.Row>
      {typeof children === 'function' ? columns.map(children) : children}
    </RadixTable.Row>
  </RadixTable.Header>
);

const TableColumn = ({ children, ...props }) => (
  <RadixTable.ColumnHeaderCell {...props}>{children}</RadixTable.ColumnHeaderCell>
);

const TableBody = ({ items, emptyContent, children }) => {
  if (!items || items.length === 0) {
    return (
      <RadixTable.Body>
        <RadixTable.Row>
          <RadixTable.Cell colSpan={8}>
            {emptyContent}
          </RadixTable.Cell>
        </RadixTable.Row>
      </RadixTable.Body>
    );
  }

  return (
    <RadixTable.Body>
      {items.map((item, idx) => {
        const rowElement = children(item);
        return React.cloneElement(rowElement, { key: item.id || idx, item });
      })}
    </RadixTable.Body>
  );
};

const TableRow = ({ item, children, className }) => {
  const columnKeys = ['title', 'category', 'chainage', 'status', 'affected_rfis', 'created_by', 'created_at', 'actions'];
  return (
    <RadixTable.Row className={className}>
      {columnKeys.map((key) => {
        const cell = children(key);
        return <RadixTable.Cell key={key}>{cell?.props?.children}</RadixTable.Cell>;
      })}
    </RadixTable.Row>
  );
};

const TableCell = ({ children, ...props }) => (
  <RadixTable.Cell {...props}>{children}</RadixTable.Cell>
);

const Pagination = ({ total, page, onChange }) => {
  return (
    <Flex gap="1">
      {Array.from({ length: total }, (_, i) => {
        const p = i + 1;
        return (
          <RadixButton
            key={p}
            size="1"
            variant={p === page ? 'solid' : 'soft'}
            color={p === page ? 'indigo' : 'gray'}
            onClick={() => onChange(p)}
          >
            {p}
          </RadixButton>
        );
      })}
    </Flex>
  );
};

const Textarea = ({ label, value, onValueChange, placeholder, minRows }) => {
  return (
    <Flex direction="column" gap="1" style={{ width: '100%' }}>
      {label && <Text size="1" color="gray">{label}</Text>}
      <TextArea
        placeholder={placeholder}
        value={value}
        onChange={(e) => onValueChange(e.target.value)}
        rows={minRows}
      />
    </Flex>
  );
};

const TextField = ({ label, value, onValueChange, placeholder, isRequired, ...props }) => {
  return (
    <Flex direction="column" gap="1" style={{ width: '100%' }}>
      {label && <Text size="1" color="gray">{label}</Text>}
      <RadixTextField.Root
        placeholder={placeholder}
        value={value}
        onChange={(e) => {
          if (onValueChange) onValueChange(e.target.value);
        }}
        {...props}
      />
    </Flex>
  );
};

const SelectItem = ({ children, value }) => {
  return <RadixSelect.Item value={value}>{children}</RadixSelect.Item>;
};

const Select = ({ children, label, selectedKeys, onSelectionChange, placeholder }) => {
  const selectedValue = selectedKeys ? Array.from(selectedKeys)[0] : undefined;
  
  return (
    <Flex direction="column" gap="1" style={{ width: '100%' }}>
      {label && <Text size="1" color="gray">{label}</Text>}
      <RadixSelect.Root
        value={selectedValue}
        onValueChange={(val) => {
          if (onSelectionChange) onSelectionChange(new Set([val]));
        }}
      >
        <RadixSelect.Trigger placeholder={placeholder} />
        <RadixSelect.Content>
          {React.Children.map(children, child => {
            if (React.isValidElement(child)) {
              return React.cloneElement(child, { value: child.props.value || child.key });
            }
            return child;
          })}
        </RadixSelect.Content>
      </RadixSelect.Root>
    </Flex>
  );
};

const Button = ({ children, startContent, isIconOnly, onPress, onClick, loading, ...props }) => {
  const handler = onClick || onPress;
  return (
    <RadixButton onClick={handler} {...props}>
      {loading && <Spinner size="1" />}
      {!loading && startContent}
      {!isIconOnly && children}
    </RadixButton>
  );
};

const Badge = ({ children, startContent, ...props }) => {
  return (
    <RadixBadge {...props}>
      <Flex align="center" gap="1">
        {startContent}
        {children}
      </Flex>
    </RadixBadge>
  );
};

const DialogShim = ({ children, open, onClose }) => {
  return (
    <RadixDialog.Root open={open} onOpenChange={(val) => { if (!val && onClose) onClose(); }}>
      {children}
    </RadixDialog.Root>
  );
};
DialogShim.Content = ({ children, ...props }) => (
  <RadixDialog.Content {...props}>{children}</RadixDialog.Content>
);
DialogShim.Title = ({ children, ...props }) => (
  <RadixDialog.Title {...props}>{children}</RadixDialog.Title>
);
const Dialog = DialogShim;
import { showToast } from '@/utils/toastUtils';
import {
    ShieldExclamationIcon,
    PlusIcon,
    ChartBarIcon,
    MagnifyingGlassIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    CalendarIcon,
    AdjustmentsHorizontalIcon,
    UserIcon,
    MapPinIcon,
    ArrowPathIcon,
    LinkIcon,
    PencilIcon,
    TrashIcon,
    EllipsisVerticalIcon,
    DocumentTextIcon,
    CalendarDaysIcon,
    ChevronDownIcon,
    DocumentArrowUpIcon,
    PhotoIcon,
    DocumentIcon,
    XMarkIcon,
    ArrowDownTrayIcon,
} from "@heroicons/react/24/outline";
import {
    CheckCircleIcon as CheckCircleSolid,
    XCircleIcon as XCircleSolid,
    ClockIcon as ClockSolid,
    ExclamationTriangleIcon as ExclamationTriangleSolid,
    ShieldExclamationIcon as ShieldExclamationSolid,
} from '@heroicons/react/24/solid';
import { Head, router, usePage } from "@inertiajs/react";
import App from "@/Layouts/App.jsx";

import ObjectionsStatsSection from './components/ObjectionsStatsSection';
import ObjectionsFiltersBar from './components/ObjectionsFiltersBar';
import ObjectionAccordionItem from './components/ObjectionAccordionItem';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { getThemeRadius } from '@/Hooks/useThemeRadius.js';
import ErrorBoundary from "@/Components/Common/ErrorBoundary.jsx";
import PullToRefresh from "@/Components/Common/PullToRefresh.jsx";
import SwipeableCard from '@/Components/Common/SwipeableCard';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar';
import {
    getStatusConfig,
    getCategoryConfig,
} from '@/Config/objectionConfig';
import { statusConfig, categoryConfig } from './config/objectionUiConfig';
import { useObjectionsAccess } from './hooks/useObjectionsAccess';
import { useObjectionsListState } from './hooks/useObjectionsListState';
import { useObjectionsActions } from './hooks/useObjectionsActions';
import useDisclosure from '@/Hooks/useDisclosure';

const ObjectionsIndex = ({ objections: initialObjections, filters, statuses, categories, creators, statistics }) => {
    const isLargeScreen = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isMobile = useMediaQuery('(max-width: 640px)');

    const { userIsAdmin, canReviewObjections } = useObjectionsAccess();

    const {
        loading,
        setLoading,
        tableLoading,
        isRefreshing,
        search,
        currentPage,
        setCurrentPage,
        expandedItems,
        objections,
        setObjections,
        showFilters,
        setShowFilters,
        filterData,
        setFilterData,
        cancelPendingRequest,
        fetchData,
        refreshData,
        handlePullToRefresh,
        handleSearch,
        handleFilterChange,
        handlePageChange,
        toggleExpanded,
    } = useObjectionsListState({ initialObjections, initialFilters: filters, isMobile });

    const [statsLoading, setStatsLoading] = useState(false);

    // Create modal state
    const { isOpen: isCreateOpen, onOpen: onCreateOpen, onClose: onCreateClose } = useDisclosure();
    const [createLoading, setCreateLoading] = useState(false);
    const [selectedFiles, setSelectedFiles] = useState([]);
    const [createForm, setCreateForm] = useState({
        title: '',
        category: 'other',
        type: '', // Work type: Embankment, Structure, Pavement
        chainage_from: '', // Legacy - kept for backward compatibility
        chainage_to: '',   // Legacy - kept for backward compatibility
        specific_chainages: '', // New: comma-separated specific chainages
        chainage_range_from: '', // New: range start
        chainage_range_to: '',   // New: range end
        description: '',
        reason: '',
        status: 'draft',
    });

    // Work type config for objections (matching DailyWork types)
    const workTypeConfig = {
        'Embankment': { label: 'Embankment', icon: '🏔️', color: 'secondary' },
        'Structure': { label: 'Structure', icon: '🏗️', color: 'primary' },
        'Pavement': { label: 'Pavement', icon: '🛣️', color: 'success' },
    };

    // File handlers for create modal
    const handleFileSelect = (event) => {
        const files = Array.from(event.target.files);
        setSelectedFiles(prev => [...prev, ...files]);
        event.target.value = ''; // Reset input
    };

    const removeSelectedFile = (index) => {
        setSelectedFiles(prev => prev.filter((_, i) => i !== index));
    };

    const resetCreateForm = () => {
        setCreateForm({
            title: '',
            category: 'other',
            type: '',
            chainage_from: '', // Legacy
            chainage_to: '',   // Legacy
            specific_chainages: '', // New
            chainage_range_from: '', // New
            chainage_range_to: '',   // New
            description: '',
            reason: '',
            status: 'draft',
        });
        setSelectedFiles([]);
    };

    // Attach RFIs modal state
    const { isOpen: isAttachOpen, onOpen: onAttachOpen, onClose: onAttachClose } = useDisclosure();
    const [selectedObjection, setSelectedObjection] = useState(null);
    const [suggestedRfis, setSuggestedRfis] = useState([]);
    const [selectedRfis, setSelectedRfis] = useState([]);
    const [attachLoading, setAttachLoading] = useState(false);
    const [rfiSearchLoading, setRfiSearchLoading] = useState(false);
    const [rfiSearchQuery, setRfiSearchQuery] = useState('');
    const [exportLoading, setExportLoading] = useState(false);

    // Edit modal state
    const { isOpen: isEditOpen, onOpen: onEditOpen, onClose: onEditClose } = useDisclosure();
    const [editLoading, setEditLoading] = useState(false);
    const [editObjection, setEditObjection] = useState(null);
    const [editForm, setEditForm] = useState({
        title: '',
        category: 'other',
        type: '',
        chainage_from: '', // Legacy
        chainage_to: '',   // Legacy
        specific_chainages: '', // New
        chainage_range_from: '', // New
        chainage_range_to: '',   // New
        description: '',
        reason: '',
    });

    // Status update modal state
    const { isOpen: isStatusOpen, onOpen: onStatusOpen, onClose: onStatusClose } = useDisclosure();
    const [statusLoading, setStatusLoading] = useState(false);
    const [statusAction, setStatusAction] = useState(null); // 'submit', 'review', 'resolve', 'reject'
    const [resolutionNotes, setResolutionNotes] = useState('');

    // History modal state
    const { isOpen: isHistoryOpen, onOpen: onHistoryOpen, onClose: onHistoryClose } = useDisclosure();
    const [historyObjection, setHistoryObjection] = useState(null);

    // View details modal state
    const { isOpen: isDetailsOpen, onOpen: onDetailsOpen, onClose: onDetailsClose } = useDisclosure();
    const [detailsObjection, setDetailsObjection] = useState(null);

    // Statistics state
    const [apiStats, setApiStats] = useState(statistics || null);

    const {
        handleCreateObjection,
        handleSuggestRfis,
        handleRfiSearch,
        handleAttachRfis,
        handleExportSelectedRfis,
        handleEditObjection,
        handleStatusChange,
        handleDeleteObjection,
        isMutating,
    } = useObjectionsActions({
        createForm,
        selectedFiles,
        setCreateLoading,
        onCreateClose,
        resetCreateForm,
        setRfiSearchLoading,
        setSuggestedRfis,
        selectedObjection,
        rfiSearchQuery,
        selectedRfis,
        suggestedRfis,
        setAttachLoading,
        onAttachClose,
        setExportLoading,
        editForm,
        editObjection,
        setEditLoading,
        onEditClose,
        statusAction,
        resolutionNotes,
        setStatusLoading,
        onStatusClose,
        setResolutionNotes,
        setObjections,
        setApiStats,
    });

    // Format date
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        try {
            return new Date(dateString).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        } catch {
            return 'Invalid date';
        }
    };

    // Get status chip
    const getStatusChip = (status) => {
        const config = statusConfig[status] || statusConfig['draft'];
        const StatusIcon = config.icon;

        return (
            <Badge
                size="sm"
                variant="soft"
                color={config.color}
                startContent={<StatusIcon className="w-3 h-3" />}
                classNames={{
                    base: "h-6",
                    content: "text-xs font-medium"
                }}
            >
                {config.label}
            </Badge>
        );
    };

    // Get category chip
    const getCategoryChip = (category) => {
        const config = categoryConfig[category] || categoryConfig['other'];
        return (
            <Badge size="sm" variant="soft" color={config.color} className="text-xs">
                {config.label}
            </Badge>
        );
    };

    // Get type chip
    const getTypeChip = (type) => {
        if (!type) return null;
        const config = workTypeConfig[type];
        if (!config) return null;
        return (
            <Badge size="sm" variant="soft" color={config.color} className="text-xs" startContent={<span className="text-xs">{config.icon}</span>}>
                {config.label}
            </Badge>
        );
    };

    // Open attach modal
    const openAttachModal = (objection) => {
        setSelectedObjection(objection);
        setSelectedRfis(objection.daily_works?.map(rfi => String(rfi.id)) || []);
        setRfiSearchQuery('');
        setSuggestedRfis([]);
        
        // Auto-search using new chainage_summary or legacy fields
        const summary = objection.chainage_summary || {};
        const specificChainages = summary.specific?.join(', ') || '';
        const rangeFrom = summary.range?.split(' - ')[0] || objection.chainage_from;
        const rangeTo = summary.range?.split(' - ')[1] || objection.chainage_to;
        const objType = objection.type;
        
        if (specificChainages) {
            // Use specific chainages
            handleSuggestRfis(specificChainages, null, '', objType);
        } else if (rangeFrom && rangeTo) {
            // Use range
            handleSuggestRfis(rangeFrom, rangeTo, '', objType);
        } else if (rangeFrom) {
            // Single chainage
            handleSuggestRfis(rangeFrom, null, '', objType);
        }
        onAttachOpen();
    };

    // Open edit modal
    const openEditModal = (objection) => {
        setEditObjection(objection);
        
        // Extract specific chainages and range from objection data
        // The backend should load chainages relationship with chainage_summary
        const chainageSummary = objection.chainage_summary || {};
        const specificChainages = chainageSummary.specific_chainages || [];
        const chainageRange = chainageSummary.chainage_range || {};
        
        setEditForm({
            title: objection.title || '',
            category: objection.category || 'other',
            type: objection.type || '',
            // Legacy fields (keep for backward compatibility)
            chainage_from: objection.chainage_from || chainageRange.from || '',
            chainage_to: objection.chainage_to || chainageRange.to || '',
            // New fields
            specific_chainages: specificChainages.join(', ') || '',
            chainage_range_from: chainageRange.from || objection.chainage_from || '',
            chainage_range_to: chainageRange.to || objection.chainage_to || '',
            description: objection.description || '',
            reason: objection.reason || '',
        });
        onEditOpen();
    };

    // Open status modal
    const openStatusModal = (objection, action) => {
        setSelectedObjection(objection);
        setStatusAction(action);
        setResolutionNotes('');
        onStatusOpen();
    };

    // Get status action label
    const getStatusActionLabel = (action) => {
        switch (action) {
            case 'submit': return 'Submit for Review';
            case 'review': return 'Start Review';
            case 'resolve': return 'Resolve Objection';
            case 'reject': return 'Reject Objection';
            default: return 'Update Status';
        }
    };

    // Open history modal
    const openHistoryModal = (objection) => {
        setHistoryObjection(objection);
        onHistoryOpen();
    };

    // Open details modal
    const openDetailsModal = (objection) => {
        setDetailsObjection(objection);
        onDetailsOpen();
    };

    // Format status for display
    const formatStatus = (status) => {
        return statusConfig[status]?.label || status?.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'Unknown';
    };

    // Effect for filter and pagination changes
    useEffect(() => {
        fetchData();
    }, [filterData, currentPage]);

    // Action buttons configuration
    const actionButtons = [
        {
            label: 'New Objection',
            icon: <PlusIcon className="w-4 h-4" />,
            color: 'primary',
            variant: 'solid',
            onPress: onCreateOpen
        },
    ];

    // Desktop columns
    const columns = [
        { key: 'title', label: 'Title' },
        { key: 'category', label: 'Category' },
        { key: 'chainage', label: 'Chainage Range' },
        { key: 'status', label: 'Status' },
        { key: 'affected_rfis', label: 'Affected RFIs' },
        { key: 'created_by', label: 'Created By' },
        { key: 'created_at', label: 'Date' },
        { key: 'actions', label: 'Actions' },
    ];

    // Render cell
    const renderCell = (objection, columnKey) => {
        switch (columnKey) {
            case 'title':
                return (
                    <div className="flex items-center gap-2 max-w-[200px]">
                        <ShieldExclamationIcon className={`w-4 h-4 shrink-0 ${
                            objection.is_active ? 'text-warning animate-pulse' : 'text-success'
                        }`} />
                        <span className="font-medium text-sm truncate" title={objection.title}>
                            {objection.title}
                        </span>
                    </div>
                );
            case 'category':
                return getCategoryChip(objection.category);
            case 'chainage':
                return objection.chainage_from && objection.chainage_to ? (
                    <div className="flex items-center gap-1 text-xs">
                        <MapPinIcon className="w-3 h-3 text-default-400" />
                        <span>{objection.chainage_from} - {objection.chainage_to}</span>
                    </div>
                ) : (
                    <span className="text-xs text-default-400">Not set</span>
                );
            case 'status':
                return getStatusChip(objection.status);
            case 'affected_rfis':
                return (
                    <Button
                        size="sm"
                        variant="soft"
                        color={objection.daily_works_count > 0 ? 'primary' : 'default'}
                        startContent={<LinkIcon className="w-3 h-3" />}
                        onClick={() => openAttachModal(objection)}
                    >
                        {objection.daily_works_count || 0} RFI(s)
                    </Button>
                );
            case 'created_by':
                return (
                    <div className="flex items-center gap-2">
                        <ProfileAvatar
                            src={objection.created_by?.profile_image_url}
                            name={objection.created_by?.name || 'Unknown'}
                            size="sm"
                            className="w-6 h-6"
                        />
                        <span className="text-xs truncate max-w-[100px]">{objection.created_by?.name || 'Unknown'}</span>
                    </div>
                );
            case 'created_at':
                return (
                    <div className="flex items-center gap-1 text-xs">
                        <CalendarDaysIcon className="w-3 h-3 text-default-400" />
                        <span>{formatDate(objection.created_at)}</span>
                    </div>
                );
            case 'actions':
                return (
                    <Dropdown>
                        <DropdownTrigger>
                            <Button isIconOnly size="sm" variant="light">
                                <EllipsisVerticalIcon className="w-4 h-4" />
                            </Button>
                        </DropdownTrigger>
                        <DropdownMenu aria-label="Objection actions">
                            <DropdownItem
                                key="view"
                                startContent={<DocumentTextIcon className="w-4 h-4" />}
                                onClick={() => openDetailsModal(objection)}
                            >
                                View Details
                            </DropdownItem>
                            <DropdownItem
                                key="attach"
                                startContent={<LinkIcon className="w-4 h-4" />}
                                onClick={() => openAttachModal(objection)}
                            >
                                Manage RFIs
                            </DropdownItem>
                            {objection.status === 'draft' && (
                                <DropdownItem
                                    key="edit"
                                    startContent={<PencilIcon className="w-4 h-4" />}
                                    onClick={() => openEditModal(objection)}
                                >
                                    Edit
                                </DropdownItem>
                            )}
                            {objection.status === 'draft' && (
                                <DropdownItem
                                    key="submit"
                                    startContent={<DocumentArrowUpIcon className="w-4 h-4" />}
                                    color="primary"
                                    onClick={() => openStatusModal(objection, 'submit')}
                                >
                                    Submit for Review
                                </DropdownItem>
                            )}
                            {objection.status === 'submitted' && (
                                <DropdownItem
                                    key="review"
                                    startContent={<ClockIcon className="w-4 h-4" />}
                                    color="warning"
                                    onClick={() => openStatusModal(objection, 'review')}
                                >
                                    Start Review
                                </DropdownItem>
                            )}
                            {(objection.status === 'submitted' || objection.status === 'under_review') && (
                                <DropdownItem
                                    key="resolve"
                                    startContent={<CheckCircleIcon className="w-4 h-4" />}
                                    color="success"
                                    onClick={() => openStatusModal(objection, 'resolve')}
                                >
                                    Resolve
                                </DropdownItem>
                            )}
                            {(objection.status === 'submitted' || objection.status === 'under_review') && (
                                <DropdownItem
                                    key="reject"
                                    startContent={<XCircleSolid className="w-4 h-4" />}
                                    color="red"
                                    onClick={() => openStatusModal(objection, 'reject')}
                                >
                                    Reject
                                </DropdownItem>
                            )}
                            {objection.status_logs?.length > 0 && (
                                <DropdownItem
                                    key="history"
                                    startContent={<ClockIcon className="w-4 h-4" />}
                                    onClick={() => openHistoryModal(objection)}
                                >
                                    View History
                                </DropdownItem>
                            )}
                            {objection.status === 'draft' && (
                                <DropdownItem
                                    key="delete"
                                    startContent={<TrashIcon className="w-4 h-4" />}
                                    color="red"
                                    className="text-danger"
                                    onClick={() => handleDeleteObjection(objection)}
                                >
                                    Delete
                                </DropdownItem>
                            )}
                        </DropdownMenu>
                    </Dropdown>
                );
            default:
                return '-';
        }
    };

    // Desktop table skeleton
    const renderTableSkeleton = () => (
        <div className="space-y-2">
            {[...Array(5)].map((_, i) => (
                <Skeleton key={i} className="h-12 w-full rounded-lg" />
            ))}
        </div>
    );

    // Mobile skeleton
    const renderMobileSkeleton = () => (
        <div className="space-y-2">
            {[...Array(5)].map((_, i) => (
                <Skeleton key={i} className="h-20 w-full rounded-lg" />
            ))}
        </div>
    );

    return (
        <>
            <Head title="Objections Management" />

            {/* Create Objection Dialog */}
            <Dialog
                open={isCreateOpen}
                onClose={onCreateClose}
                size="lg"
                scrollBehavior="inside"
                shouldBlockScroll={false}
                placement="center"
                classNames={{
                    base: "max-h-[90vh] m-4",
                    wrapper: "items-center",
                }}
            >
                <Dialog.Content>
                    <Dialog.Title className="flex items-center gap-2">
                        <ShieldExclamationIcon className="w-5 h-5 text-warning" />
                        Create New Objection
                    </Dialog.Title>
                    <Box>
                        <div className="space-y-4">
                            <TextField.Root
                                label="Title"
                                placeholder="Enter objection title"
                                value={createForm.title}
                                onValueChange={(value) => setCreateForm(prev => ({ ...prev, title: value }))}
                                isRequired
                            />

                            <Select
                                label="Category"
                                selectedKeys={[createForm.category]}
                                onSelectionChange={(keys) => {
                                    const value = Array.from(keys)[0] || 'other';
                                    setCreateForm(prev => ({ ...prev, category: value }));
                                }}
                            >
                                {Object.entries(categories || categoryConfig).map(([key, value]) => (
                                    <SelectItem key={key}>{typeof value === 'string' ? value : value.label}</SelectItem>
                                ))}
                            </Select>

                            <Select
                                label="Work Type"
                                placeholder="Select work type to filter RFIs"
                                description="Only RFIs of this type will be matched"
                                selectedKeys={createForm.type ? [createForm.type] : []}
                                onSelectionChange={(keys) => {
                                    const value = Array.from(keys)[0] || '';
                                    setCreateForm(prev => ({ ...prev, type: value }));
                                }}
                            >
                                {Object.entries(workTypeConfig).map(([key, conf]) => (
                                    <SelectItem key={key} startContent={<span>{conf.icon}</span>}>
                                        {conf.label}
                                    </SelectItem>
                                ))}
                            </Select>

                            {/* Chainage Section - Supports both specific chainages and ranges */}
                            <div className="space-y-3">
                                <label className="text-sm font-medium text-default-700">
                                    Chainages (Optional)
                                </label>
                                <p className="text-xs text-default-400 mb-2">
                                    Add specific chainages (comma-separated) and/or a range
                                </p>
                                
                                <TextField.Root
                                    label="Specific Chainages"
                                    placeholder="e.g., K35+897, K36+987, K37+123"
                                    description="Multiple chainages separated by commas"
                                    value={createForm.specific_chainages}
                                    onValueChange={(value) => setCreateForm(prev => ({ ...prev, specific_chainages: value }))}
                                />

                                <div className="grid grid-cols-2 gap-3">
                                    <TextField.Root
                                        label="Range From"
                                        placeholder="e.g., K36+580"
                                        value={createForm.chainage_range_from}
                                        onValueChange={(value) => setCreateForm(prev => ({ ...prev, chainage_range_from: value }))}
                                    />
                                    <TextField.Root
                                        label="Range To"
                                        placeholder="e.g., K37+540"
                                        value={createForm.chainage_range_to}
                                        onValueChange={(value) => setCreateForm(prev => ({ ...prev, chainage_range_to: value }))}
                                    />
                                </div>
                            </div>

                            <Textarea
                                label="Description"
                                placeholder="Describe the objection in detail"
                                value={createForm.description}
                                onValueChange={(value) => setCreateForm(prev => ({ ...prev, description: value }))}
                                minRows={3}
                                isRequired
                            />

                            <Textarea
                                label="Reason"
                                placeholder="Explain the reason for this objection"
                                value={createForm.reason}
                                onValueChange={(value) => setCreateForm(prev => ({ ...prev, reason: value }))}
                                minRows={2}
                                isRequired
                            />

                            <Select
                                label="Status"
                                selectedKeys={[createForm.status]}
                                onSelectionChange={(keys) => {
                                    const value = Array.from(keys)[0] || 'draft';
                                    setCreateForm(prev => ({ ...prev, status: value }));
                                }}
                            >
                                <SelectItem key="draft">Save as Draft</SelectItem>
                                <SelectItem key="submitted">Submit Immediately</SelectItem>
                            </Select>

                            {/* File Upload Section */}
                            <div className="space-y-2">
                                <label className="text-sm font-medium text-default-700">
                                    Supporting Documents (Optional)
                                </label>
                                <p className="text-xs text-default-400">
                                    Upload images, PDFs, Word, or Excel files (max 10MB each)
                                </p>
                                <input
                                    type="file"
                                    multiple
                                    accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx"
                                    onChange={handleFileSelect}
                                    className="hidden"
                                    id="objection-file-upload"
                                />
                                <label
                                    htmlFor="objection-file-upload"
                                    className="flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-default-300 rounded-lg cursor-pointer hover:border-primary hover:bg-default-50 transition-colors"
                                >
                                    <DocumentIcon className="w-5 h-5 text-default-500" />
                                    <span className="text-sm text-default-600">
                                        Click to select files
                                    </span>
                                </label>

                                {/* Selected Files List */}
                                {selectedFiles.length > 0 && (
                                    <div className="space-y-1 mt-2">
                                        {selectedFiles.map((file, index) => (
                                            <div
                                                key={`${file.name}-${file.size}`}
                                                className="flex items-center justify-between p-2 bg-default-100 rounded-lg"
                                            >
                                                <div className="flex items-center gap-2 flex-1 min-w-0">
                                                    {file.type.startsWith('image/') ? (
                                                        <PhotoIcon className="w-4 h-4 text-success shrink-0" />
                                                    ) : (
                                                        <DocumentTextIcon className="w-4 h-4 text-primary shrink-0" />
                                                    )}
                                                    <span className="text-xs truncate">{file.name}</span>
                                                    <span className="text-xs text-default-400 shrink-0">
                                                        ({(file.size / 1024).toFixed(1)} KB)
                                                    </span>
                                                </div>
                                                <Button
                                                    isIconOnly
                                                    size="sm"
                                                    variant="light"
                                                    color="red"
                                                    onClick={() => removeSelectedFile(index)}
                                                >
                                                    <XMarkIcon className="w-4 h-4" />
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </Box>
                    <Flex>
                        <Button variant="light" onClick={() => { onCreateClose(); resetCreateForm(); }}>
                            Cancel
                        </Button>
                        <Button
                            color="primary"
                            onClick={handleCreateObjection}
                            loading={createLoading}
                        >
                            Create Objection
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog>

            {/* Attach RFIs Dialog */}
            <Dialog
                open={isAttachOpen}
                onClose={onAttachClose}
                size="2xl"
                scrollBehavior="inside"
                shouldBlockScroll={false}
                placement="center"
                classNames={{
                    base: "max-h-[90vh] m-4",
                    wrapper: "items-center",
                    body: "overflow-x-hidden",
                }}
            >
                <Dialog.Content>
                    <Dialog.Title className="flex items-center gap-2 flex-wrap">
                        <LinkIcon className="w-5 h-5 text-primary" />
                        Manage Affected RFIs
                        {selectedObjection && (
                            <span className="text-sm font-normal text-default-500">
                                - {selectedObjection.title}
                            </span>
                        )}
                        {selectedObjection?.type && (
                            <Badge size="sm" variant="soft" color={workTypeConfig[selectedObjection.type]?.color || 'default'} className="text-xs">
                                {workTypeConfig[selectedObjection.type]?.icon} {selectedObjection.type} only
                            </Badge>
                        )}
                    </Dialog.Title>
                    <Box>
                        <div className="space-y-4">
                            {/* Type Filter Info */}
                            {selectedObjection?.type && (
                                <div className="flex flex-wrap items-center gap-2 p-3 bg-primary-50 dark:bg-primary-900/20 rounded-lg border border-primary-200 dark:border-primary-800">
                                    <span className="text-sm text-primary-700 dark:text-primary-300">
                                        🔍 Filtering RFIs by type: <strong>{selectedObjection.type}</strong>
                                    </span>
                                </div>
                            )}

                            {/* Chainage Range Info */}
                            {selectedObjection?.chainage_from && selectedObjection?.chainage_to && (
                                <div className="flex flex-wrap items-center gap-2 p-3 bg-default-100 rounded-lg">
                                    <MapPinIcon className="w-5 h-5 text-default-500" />
                                    <span className="text-sm">
                                        Chainage Range: <strong>{selectedObjection.chainage_from}</strong> - <strong>{selectedObjection.chainage_to}</strong>
                                    </span>
                                </div>
                            )}

                            {/* Manual Search */}
                            <div className="flex gap-2">
                                <TextField.Root
                                    placeholder="Search RFIs by number, location, or description..."
                                    value={rfiSearchQuery}
                                    onChange={(e) => setRfiSearchQuery(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleRfiSearch()}
                                    startContent={<MagnifyingGlassIcon className="w-4 h-4 text-default-400" />}
                                    size="sm"
                                    className="flex-1"
                                />
                                <Button
                                    size="sm"
                                    color="primary"
                                    variant="soft"
                                    loading={rfiSearchLoading}
                                    onClick={handleRfiSearch}
                                >
                                    Search
                                </Button>
                                {(selectedObjection?.chainage_summary?.specific?.length > 0 || 
                                  selectedObjection?.chainage_summary?.range ||
                                  (selectedObjection?.chainage_from && selectedObjection?.chainage_to)) && (
                                    <Button
                                        size="sm"
                                        variant="soft"
                                        loading={rfiSearchLoading}
                                        onClick={() => {
                                            setRfiSearchQuery('');
                                            const summary = selectedObjection.chainage_summary || {};
                                            const specificChainages = summary.specific?.join(', ') || '';
                                            const rangeFrom = summary.range?.split(' - ')[0] || selectedObjection.chainage_from;
                                            const rangeTo = summary.range?.split(' - ')[1] || selectedObjection.chainage_to;
                                            const objType = selectedObjection.type;
                                            
                                            if (specificChainages) {
                                                handleSuggestRfis(specificChainages, null, '', objType);
                                            } else {
                                                handleSuggestRfis(rangeFrom, rangeTo, '', objType);
                                            }
                                        }}
                                    >
                                        <ArrowPathIcon className="w-4 h-4" />
                                    </Button>
                                )}
                            </div>

                            {/* Currently Attached RFIs */}
                            {selectedObjection?.daily_works?.length > 0 && (
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2">
                                        <CheckCircleSolid className="w-4 h-4 text-success" />
                                        <span className="text-sm font-medium text-success">
                                            Currently Attached ({selectedObjection.daily_works.length})
                                        </span>
                                    </div>
                                    <div className="grid gap-2 max-h-[150px] overflow-auto border border-success/30 rounded-lg p-2 bg-success-50/30 dark:bg-success-900/10">
                                        {selectedObjection.daily_works.map((rfi) => (
                                            <div
                                                key={rfi.id}
                                                className="flex items-center gap-3 p-2 border border-divider rounded-lg bg-content1"
                                            >
                                                <Checkbox 
                                                    value={String(rfi.id)} 
                                                    isSelected={selectedRfis.includes(String(rfi.id))}
                                                    onValueChange={(isSelected) => {
                                                        if (isSelected) {
                                                            setSelectedRfis(prev => [...prev, String(rfi.id)]);
                                                        } else {
                                                            setSelectedRfis(prev => prev.filter(id => id !== String(rfi.id)));
                                                        }
                                                    }}
                                                />
                                                <div className="flex-1 min-w-0">
                                                    <div className="font-medium text-sm">{rfi.number}</div>
                                                    <div className="text-xs text-default-500 flex items-center gap-2">
                                                        <MapPinIcon className="w-3 h-3" />
                                                        {rfi.location || 'No location'}
                                                        {rfi.type && (
                                                            <>
                                                                <span>•</span>
                                                                {rfi.type}
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                <Badge size="sm" variant="soft" color="success">Attached</Badge>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Search Results / Suggested RFIs */}
                            <div className="space-y-2">
                                <div className="flex items-center gap-2">
                                    <MagnifyingGlassIcon className="w-4 h-4 text-default-500" />
                                    <span className="text-sm font-medium">
                                        {rfiSearchQuery ? 'Search Results' : 'Suggested RFIs'} 
                                        {suggestedRfis.length > 0 && ` (${suggestedRfis.length})`}
                                    </span>
                                </div>

                                {rfiSearchLoading ? (
                                    <div className="grid gap-2 border border-divider rounded-lg p-2">
                                        {[1, 2, 3].map((_, idx) => (
                                            <div
                                                key={idx}
                                                className="flex items-center gap-3 p-2 border border-divider rounded-lg"
                                            >
                                                <Skeleton className="w-5 h-5 rounded" />
                                                <div className="flex-1 min-w-0 space-y-2">
                                                    <Skeleton className="h-4 w-24 rounded" />
                                                    <Skeleton className="h-3 w-48 rounded" />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : suggestedRfis.length > 0 ? (
                                    <CheckboxGroup
                                        value={selectedRfis}
                                        onValueChange={setSelectedRfis}
                                    >
                                        <div className="space-y-2 max-h-[300px] overflow-y-auto overflow-x-hidden border border-divider rounded-lg p-2">
                                            {suggestedRfis.map((rfi) => {
                                                const isAlreadyAttached = selectedObjection?.daily_works?.some(dw => dw.id === rfi.id);
                                                return (
                                                    <div
                                                        key={rfi.id}
                                                        className={`flex items-start gap-3 p-3 border rounded-lg hover:bg-default-100 overflow-hidden ${
                                                            isAlreadyAttached ? 'border-success/50 bg-success-50/20' : 'border-divider'
                                                        }`}
                                                    >
                                                        <Checkbox value={String(rfi.id)} className="mt-1" />
                                                        <div className="flex-1 min-w-0 space-y-2">
                                                            {/* Header: RFI Number + Attached Badge */}
                                                            <div className="flex items-center gap-2 flex-wrap">
                                                                <span className="font-semibold text-sm text-primary">{rfi.number}</span>
                                                                {isAlreadyAttached && (
                                                                    <Badge size="sm" variant="soft" color="success" className="h-5 text-[10px]">
                                                                        Attached
                                                                    </Badge>
                                                                )}
                                                                {rfi.type && (
                                                                    <Badge size="sm" variant="soft" color="secondary" className="h-5 text-[10px]">
                                                                        {rfi.type}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            
                                                            {/* Grid: Date, Chainage, Side, Layer/Qty */}
                                                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                                                <div className="flex flex-col">
                                                                    <span className="text-default-400 text-[10px]">Date</span>
                                                                    <span className="font-medium">{rfi.date || '-'}</span>
                                                                </div>
                                                                <div className="flex flex-col">
                                                                    <span className="text-default-400 text-[10px]">Chainage</span>
                                                                    <span className="font-medium truncate" title={rfi.location}>{rfi.location || '-'}</span>
                                                                </div>
                                                                <div className="flex flex-col">
                                                                    <span className="text-default-400 text-[10px]">Side</span>
                                                                    <span className="font-medium">{rfi.side || '-'}</span>
                                                                </div>
                                                                <div className="flex flex-col">
                                                                    <span className="text-default-400 text-[10px]">Layer/Qty</span>
                                                                    <span className="font-medium">{rfi.qty_layer || '-'}</span>
                                                                </div>
                                                            </div>
                                                            
                                                            {/* Description */}
                                                            {rfi.description && (
                                                                <p className="text-xs text-default-500 line-clamp-2">
                                                                    {rfi.description}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </CheckboxGroup>
                                ) : (
                                    <div className="text-center py-8 text-default-500 border border-dashed border-divider rounded-lg">
                                        <DocumentTextIcon className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                        <p>
                                            {rfiSearchQuery 
                                                ? 'No RFIs found matching your search.' 
                                                : selectedObjection?.chainage_from && selectedObjection?.chainage_to
                                                    ? 'No RFIs found in the specified chainage range.'
                                                    : 'Use the search box above to find RFIs.'}
                                        </p>
                                        <p className="text-xs mt-1">Try searching by RFI number, location, or description.</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </Box>
                    <Flex>
                        <div className="flex items-center justify-between w-full">
                            <div className="flex items-center gap-2">
                                <span className="text-sm text-default-500">
                                    {selectedRfis.length} RFI(s) selected
                                </span>
                                {suggestedRfis.length > 0 && (
                                    <Tooltip content={selectedRfis.length > 0 ? `Export ${selectedRfis.length} selected RFIs` : `Export all ${suggestedRfis.length} RFIs`}>
                                        <Button
                                            variant="soft"
                                            color="success"
                                            size="sm"
                                            isIconOnly
                                            onClick={handleExportSelectedRfis}
                                            loading={exportLoading}
                                        >
                                            <ArrowDownTrayIcon className="w-4 h-4" />
                                        </Button>
                                    </Tooltip>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <Button variant="light" onClick={onAttachClose}>
                                    Cancel
                                </Button>
                                <Button
                                    color="primary"
                                    onClick={handleAttachRfis}
                                    loading={attachLoading}
                                    disabled={selectedRfis.length === 0}
                                >
                                    Save Attachments
                                </Button>
                            </div>
                        </div>
                    </Flex>
                </Dialog.Content>
            </Dialog>

            {/* Edit Objection Dialog */}
            <Dialog
                open={isEditOpen}
                onClose={onEditClose}
                size="2xl"
                scrollBehavior="inside"
                shouldBlockScroll={false}
                placement="center"
                classNames={{
                    base: "max-h-[90vh] m-4",
                    wrapper: "items-center",
                }}
            >
                <Dialog.Content>
                    <Dialog.Title className="flex items-center gap-2">
                        <PencilIcon className="w-5 h-5 text-primary" />
                        Edit Objection
                    </Dialog.Title>
                    <Box>
                        <div className="space-y-4">
                            <TextField.Root
                                label="Title"
                                placeholder="Enter objection title"
                                value={editForm.title}
                                onChange={(e) => setEditForm(prev => ({ ...prev, title: e.target.value }))}
                                isRequired
                            />
                            <Select
                                label="Category"
                                placeholder="Select category"
                                selectedKeys={[editForm.category]}
                                onChange={(e) => setEditForm(prev => ({ ...prev, category: e.target.value }))}
                            >
                                {Object.entries(categoryConfig).map(([key, conf]) => (
                                    <SelectItem key={key} value={key}>
                                        {conf.label}
                                    </SelectItem>
                                ))}
                            </Select>

                            <Select
                                label="Work Type"
                                placeholder="Select work type to filter RFIs"
                                description="Only RFIs of this type will be matched"
                                selectedKeys={editForm.type ? [editForm.type] : []}
                                onChange={(e) => setEditForm(prev => ({ ...prev, type: e.target.value }))}
                            >
                                {Object.entries(workTypeConfig).map(([key, conf]) => (
                                    <SelectItem key={key} value={key} startContent={<span>{conf.icon}</span>}>
                                        {conf.label}
                                    </SelectItem>
                                ))}
                            </Select>
                            
                            {/* Chainage Section - Supports both specific chainages and ranges */}
                            <div className="space-y-3">
                                <label className="text-sm font-medium text-default-700">
                                    Chainages (Optional)
                                </label>
                                <p className="text-xs text-default-400 mb-2">
                                    Add specific chainages (comma-separated) and/or a range
                                </p>
                                
                                <TextField.Root
                                    label="Specific Chainages"
                                    placeholder="e.g., K35+897, K36+987, K37+123"
                                    description="Multiple chainages separated by commas"
                                    value={editForm.specific_chainages}
                                    onChange={(e) => setEditForm(prev => ({ ...prev, specific_chainages: e.target.value }))}
                                />

                                <div className="grid grid-cols-2 gap-4">
                                    <TextField.Root
                                        label="Range From"
                                        placeholder="e.g., K36+580"
                                        value={editForm.chainage_range_from}
                                        onChange={(e) => setEditForm(prev => ({ ...prev, chainage_range_from: e.target.value }))}
                                    />
                                    <TextField.Root
                                        label="Range To"
                                        placeholder="e.g., K37+540"
                                        value={editForm.chainage_range_to}
                                        onChange={(e) => setEditForm(prev => ({ ...prev, chainage_range_to: e.target.value }))}
                                    />
                                </div>
                            </div>
                            
                            <Textarea
                                label="Description"
                                placeholder="Describe the objection in detail"
                                value={editForm.description}
                                onChange={(e) => setEditForm(prev => ({ ...prev, description: e.target.value }))}
                                minRows={3}
                                isRequired
                            />
                            <Textarea
                                label="Reason"
                                placeholder="Explain the reason for raising this objection"
                                value={editForm.reason}
                                onChange={(e) => setEditForm(prev => ({ ...prev, reason: e.target.value }))}
                                minRows={2}
                                isRequired
                            />
                        </div>
                    </Box>
                    <Flex>
                        <Button variant="light" onClick={onEditClose}>
                            Cancel
                        </Button>
                        <Button
                            color="primary"
                            onClick={handleEditObjection}
                            loading={editLoading}
                        >
                            Save Changes
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog>

            {/* Status Update Dialog */}
            <Dialog
                open={isStatusOpen}
                onClose={onStatusClose}
                size="lg"
                placement="bottom-center"
                classNames={{
                    base: "max-h-[100dvh] sm:max-h-[90vh] m-0 sm:m-4 mb-0",
                    wrapper: "items-end sm:items-center",
                }}
            >
                <Dialog.Content>
                    <Dialog.Title className="flex items-center gap-2">
                        {statusAction === 'submit' && <DocumentArrowUpIcon className="w-5 h-5 text-primary" />}
                        {statusAction === 'review' && <ClockIcon className="w-5 h-5 text-warning" />}
                        {statusAction === 'resolve' && <CheckCircleIcon className="w-5 h-5 text-success" />}
                        {statusAction === 'reject' && <XCircleSolid className="w-5 h-5 text-danger" />}
                        {getStatusActionLabel(statusAction)}
                    </Dialog.Title>
                    <Box>
                        {selectedObjection && (
                            <div className="space-y-4">
                                <div className="p-3 bg-default-100 rounded-lg">
                                    <p className="font-medium">{selectedObjection.title}</p>
                                    <p className="text-sm text-default-500 mt-1">{selectedObjection.description}</p>
                                </div>
                                
                                {statusAction === 'submit' && (
                                    <p className="text-sm text-default-600">
                                        Are you sure you want to submit this objection for review? 
                                        Once submitted, you won't be able to edit it.
                                    </p>
                                )}
                                
                                {statusAction === 'review' && (
                                    <p className="text-sm text-default-600">
                                        This will mark the objection as "Under Review". 
                                        You can then resolve or reject it after investigation.
                                    </p>
                                )}
                                
                                {(statusAction === 'resolve' || statusAction === 'reject') && (
                                    <Textarea
                                        label={statusAction === 'resolve' ? 'Resolution Notes' : 'Rejection Reason'}
                                        placeholder={statusAction === 'resolve' 
                                            ? 'Explain how the objection was resolved...'
                                            : 'Explain why the objection is being rejected...'
                                        }
                                        value={resolutionNotes}
                                        onChange={(e) => setResolutionNotes(e.target.value)}
                                        minRows={3}
                                        isRequired
                                    />
                                )}
                            </div>
                        )}
                    </Box>
                    <Flex>
                        <Button variant="light" onClick={onStatusClose}>
                            Cancel
                        </Button>
                        <Button
                            color={statusAction === 'reject' ? 'danger' : statusAction === 'resolve' ? 'success' : 'primary'}
                            onClick={handleStatusChange}
                            loading={statusLoading}
                        >
                            {getStatusActionLabel(statusAction)}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog>

            {/* Status History Dialog */}
            <Dialog
                open={isHistoryOpen}
                onClose={onHistoryClose}
                size="2xl"
                scrollBehavior="inside"
                shouldBlockScroll={false}
                placement="center"
                classNames={{
                    base: "max-h-[90vh] m-4",
                    wrapper: "items-center",
                }}
            >
                <Dialog.Content>
                    <Dialog.Title className="flex items-center gap-2">
                        <ClockIcon className="w-5 h-5 text-primary" />
                        Status History
                    </Dialog.Title>
                    <Box>
                        {historyObjection && (
                            <div className="space-y-4">
                                {/* Current Status */}
                                <div className="p-3 bg-default-100 rounded-lg">
                                    <p className="font-medium">{historyObjection.title}</p>
                                    <div className="flex items-center gap-2 mt-2">
                                        <span className="text-sm text-default-500">Current Status:</span>
                                        <Badge
                                            size="sm"
                                            variant="soft"
                                            color={statusConfig[historyObjection.status]?.color || 'default'}
                                        >
                                            {formatStatus(historyObjection.status)}
                                        </Badge>
                                    </div>
                                </div>
                                
                                {/* Timeline */}
                                <div className="relative">
                                    <div className="absolute left-3 top-0 bottom-0 w-0.5 bg-default-200"></div>
                                    
                                    {historyObjection.status_logs?.length > 0 ? (
                                        <div className="space-y-4">
                                            {historyObjection.status_logs.map((log, index) => {
                                                const toStatusConf = statusConfig[log.to_status] || {};
                                                const StatusIcon = toStatusConf.icon || ClockIcon;
                                                
                                                return (
                                                    <div key={log.id || index} className="relative flex gap-4 pl-8">
                                                        {/* Timeline Dot */}
                                                        <div 
                                                            className={`absolute left-0 w-6 h-6 rounded-full flex items-center justify-center ${
                                                                toStatusConf.bgColor || 'bg-default-100'
                                                            }`}
                                                        >
                                                            <StatusIcon className={`w-3 h-3 ${toStatusConf.textColor || 'text-default-500'}`} />
                                                        </div>
                                                        
                                                        {/* Content */}
                                                        <div className="flex-1 pb-4">
                                                            <div className="bg-content2 rounded-lg p-3 border border-divider/40">
                                                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                                                    <Badge
                                                                        size="sm"
                                                                        variant="soft"
                                                                        color={statusConfig[log.from_status]?.color || 'default'}
                                                                    >
                                                                        {formatStatus(log.from_status)}
                                                                    </Badge>
                                                                    <span className="text-default-400">→</span>
                                                                    <Badge
                                                                        size="sm"
                                                                        variant="soft"
                                                                        color={toStatusConf.color || 'default'}
                                                                    >
                                                                        {formatStatus(log.to_status)}
                                                                    </Badge>
                                                                </div>
                                                                
                                                                <div className="flex flex-wrap items-center gap-3 mt-2 text-xs text-default-500">
                                                                    <span className="flex items-center gap-1">
                                                                        <UserIcon className="w-3 h-3" />
                                                                        {log.changed_by?.name || 'System'}
                                                                    </span>
                                                                    <span className="flex items-center gap-1">
                                                                        <CalendarIcon className="w-3 h-3" />
                                                                        {new Date(log.changed_at).toLocaleString()}
                                                                    </span>
                                                                </div>
                                                                
                                                                {log.notes && (
                                                                    <div className="mt-2 pt-2 border-t border-divider/40">
                                                                        <p className="text-sm text-default-600">{log.notes}</p>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <div className="text-center py-8 text-default-500">
                                            <ClockIcon className="w-12 h-12 mx-auto mb-2 opacity-50" />
                                            <p>No status changes recorded yet.</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </Box>
                    <Flex>
                        <Button variant="light" onClick={onHistoryClose}>
                            Close
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog>

            {/* View Details Dialog */}
            <Dialog
                open={isDetailsOpen}
                onClose={onDetailsClose}
                size="4xl"
                scrollBehavior="inside"
                shouldBlockScroll={false}
                placement="center"
                classNames={{
                    base: "max-h-[95vh] m-2 sm:m-4",
                    wrapper: "items-center",
                    body: "p-3 sm:p-6",
                }}
            >
                <Dialog.Content>
                    <Dialog.Title className="flex flex-col sm:flex-row sm:items-center gap-2 pb-2">
                        <div className="flex items-center gap-2">
                            <ShieldExclamationIcon className={`w-5 h-5 ${detailsObjection?.is_active ? 'text-warning' : 'text-success'}`} />
                            <span className="font-semibold truncate">{detailsObjection?.title || 'Objection Details'}</span>
                        </div>
                        {detailsObjection && (
                            <div className="flex items-center gap-2 mt-1 sm:mt-0 sm:ml-auto">
                                {getStatusChip(detailsObjection.status)}
                                {getCategoryChip(detailsObjection.category)}
                                {getTypeChip(detailsObjection.type)}
                            </div>
                        )}
                    </Dialog.Title>
                    <Box>
                        {detailsObjection && (
                            <div className="space-y-4 sm:space-y-6">
                                {/* Basic Info Grid - Responsive */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                    {/* Created By */}
                                    <div className="p-3 bg-default-100 rounded-lg">
                                        <div className="flex items-center gap-2 text-xs text-default-500 mb-1">
                                            <UserIcon className="w-3 h-3" />
                                            <span>Created By</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ProfileAvatar
                                                src={detailsObjection.created_by?.profile_image_url}
                                                name={detailsObjection.created_by?.name || 'Unknown'}
                                                size="sm"
                                                className="w-6 h-6"
                                            />
                                            <span className="text-sm font-medium truncate">{detailsObjection.created_by?.name || 'Unknown'}</span>
                                        </div>
                                    </div>

                                    {/* Created At */}
                                    <div className="p-3 bg-default-100 rounded-lg">
                                        <div className="flex items-center gap-2 text-xs text-default-500 mb-1">
                                            <CalendarDaysIcon className="w-3 h-3" />
                                            <span>Created At</span>
                                        </div>
                                        <p className="text-sm font-medium">
                                            {new Date(detailsObjection.created_at).toLocaleDateString('en-US', {
                                                year: 'numeric',
                                                month: 'short',
                                                day: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit'
                                            })}
                                        </p>
                                    </div>
                                </div>

                                {/* Chainage Section - Full Width with Scroll */}
                                <div className="p-3 sm:p-4 bg-default-100 rounded-lg">
                                    <div className="flex items-center gap-2 text-xs text-default-500 mb-2">
                                        <MapPinIcon className="w-4 h-4" />
                                        <span className="font-medium">Chainage Information</span>
                                    </div>
                                    {detailsObjection.chainage_summary ? (
                                        <div className="space-y-3">
                                            {/* Specific Chainages */}
                                            {detailsObjection.chainage_summary.specific?.length > 0 && (
                                                <div>
                                                    <p className="text-xs text-default-500 mb-1.5">Specific Chainages ({detailsObjection.chainage_summary.specific.length})</p>
                                                    <ScrollShadow className="max-h-24" hideScrollBar>
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {detailsObjection.chainage_summary.specific.map((chainage, idx) => (
                                                                <Badge key={idx} size="sm" variant="soft" color="secondary" className="text-xs">
                                                                    {chainage}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    </ScrollShadow>
                                                </div>
                                            )}
                                            {/* Chainage Range */}
                                            {detailsObjection.chainage_summary.range && (
                                                <div>
                                                    <p className="text-xs text-default-500 mb-1.5">Chainage Range</p>
                                                    <Badge size="sm" variant="outline" color="primary" startContent={<MapPinIcon className="w-3 h-3" />}>
                                                        {detailsObjection.chainage_summary.range}
                                                    </Badge>
                                                </div>
                                            )}
                                            {/* No chainage info */}
                                            {!detailsObjection.chainage_summary.specific?.length && !detailsObjection.chainage_summary.range && (
                                                <p className="text-sm text-default-400">No chainage information specified</p>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-default-400">No chainage information specified</p>
                                    )}
                                </div>

                                {/* Affected RFIs */}
                                <div className="p-3 sm:p-4 bg-primary-50/50 dark:bg-primary-900/10 border border-primary/20 rounded-lg">
                                    <div className="flex items-center justify-between mb-2">
                                        <div className="flex items-center gap-2 text-xs text-primary">
                                            <LinkIcon className="w-3 h-3" />
                                            <span>Affected RFIs ({detailsObjection.daily_works?.length || 0})</span>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="soft"
                                            color="primary"
                                            onClick={() => {
                                                onDetailsClose();
                                                openAttachModal(detailsObjection);
                                            }}
                                        >
                                            Manage
                                        </Button>
                                    </div>
                                    {detailsObjection.daily_works?.length > 0 ? (
                                        <div className="flex flex-wrap gap-2">
                                            {detailsObjection.daily_works.slice(0, 10).map((rfi) => (
                                                <Badge key={rfi.id} size="sm" variant="soft" color="primary">
                                                    {rfi.number}
                                                </Badge>
                                            ))}
                                            {detailsObjection.daily_works.length > 10 && (
                                                <Badge size="sm" variant="outline" color="gray">
                                                    +{detailsObjection.daily_works.length - 10} more
                                                </Badge>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-default-500">No RFIs attached to this objection.</p>
                                    )}
                                </div>

                                {/* Description */}
                                {detailsObjection.description && (
                                    <div className="space-y-2">
                                        <h4 className="text-sm font-semibold flex items-center gap-2">
                                            <DocumentTextIcon className="w-4 h-4 text-default-500" />
                                            Description
                                        </h4>
                                        <div className="p-3 sm:p-4 bg-default-100 rounded-lg">
                                            <p className="text-sm whitespace-pre-wrap">{detailsObjection.description}</p>
                                        </div>
                                    </div>
                                )}

                                {/* Reason */}
                                {detailsObjection.reason && (
                                    <div className="space-y-2">
                                        <h4 className="text-sm font-semibold flex items-center gap-2">
                                            <ExclamationTriangleIcon className="w-4 h-4 text-warning" />
                                            Reason for Objection
                                        </h4>
                                        <div className="p-3 sm:p-4 bg-warning-50/50 dark:bg-warning-900/10 border border-warning/20 rounded-lg">
                                            <p className="text-sm whitespace-pre-wrap">{detailsObjection.reason}</p>
                                        </div>
                                    </div>
                                )}

                                {/* Resolution Notes (if resolved/rejected) */}
                                {detailsObjection.resolution_notes && (
                                    <div className="space-y-2">
                                        <h4 className="text-sm font-semibold flex items-center gap-2">
                                            {detailsObjection.status === 'resolved' ? (
                                                <CheckCircleSolid className="w-4 h-4 text-success" />
                                            ) : (
                                                <XCircleSolid className="w-4 h-4 text-danger" />
                                            )}
                                            Resolution Notes
                                        </h4>
                                        <div className={`p-3 sm:p-4 rounded-lg border ${
                                            detailsObjection.status === 'resolved'
                                                ? 'bg-success-50/50 dark:bg-success-900/10 border-success/20'
                                                : 'bg-danger-50/50 dark:bg-danger-900/10 border-danger/20'
                                        }`}>
                                            <p className="text-sm whitespace-pre-wrap">{detailsObjection.resolution_notes}</p>
                                            {detailsObjection.resolved_by && (
                                                <div className="flex items-center gap-2 mt-3 pt-3 border-t border-divider/40">
                                                    <ProfileAvatar
                                                        src={detailsObjection.resolved_by?.profile_image_url}
                                                        name={detailsObjection.resolved_by?.name || 'Unknown'}
                                                        size="sm"
                                                        className="w-5 h-5"
                                                    />
                                                    <span className="text-xs text-default-500">
                                                        {detailsObjection.status === 'resolved' ? 'Resolved' : 'Rejected'} by {detailsObjection.resolved_by?.name}
                                                        {detailsObjection.resolved_at && (
                                                            <> on {new Date(detailsObjection.resolved_at).toLocaleDateString()}</>
                                                        )}
                                                    </span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Attachments */}
                                {detailsObjection.media?.length > 0 && (
                                    <div className="space-y-2">
                                        <h4 className="text-sm font-semibold flex items-center gap-2">
                                            <PhotoIcon className="w-4 h-4 text-default-500" />
                                            Attachments ({detailsObjection.media.length})
                                        </h4>
                                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 sm:gap-3">
                                            {detailsObjection.media.map((file) => {
                                                const isImage = file.mime_type?.startsWith('image/');
                                                return (
                                                    <a
                                                        key={file.id}
                                                        href={file.original_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="group relative aspect-square rounded-lg overflow-hidden border border-divider hover:border-primary transition-colors"
                                                    >
                                                        {isImage ? (
                                                            <img
                                                                src={file.preview_url || file.original_url}
                                                                alt={file.file_name}
                                                                className="w-full h-full object-cover group-hover:scale-105 transition-transform"
                                                            />
                                                        ) : (
                                                            <div className="w-full h-full flex flex-col items-center justify-center bg-default-100 p-2">
                                                                <DocumentIcon className="w-8 h-8 text-default-400 mb-1" />
                                                                <span className="text-[10px] text-center text-default-500 line-clamp-2">
                                                                    {file.file_name}
                                                                </span>
                                                            </div>
                                                        )}
                                                        <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
                                                            <span className="opacity-0 group-hover:opacity-100 text-white text-xs bg-black/50 px-2 py-1 rounded">
                                                                View
                                                            </span>
                                                        </div>
                                                    </a>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Status Timeline Summary */}
                                {detailsObjection.status_logs?.length > 0 && (
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <h4 className="text-sm font-semibold flex items-center gap-2">
                                                <ClockIcon className="w-4 h-4 text-default-500" />
                                                Status History
                                            </h4>
                                            <Button
                                                size="sm"
                                                variant="light"
                                                onClick={() => {
                                                    onDetailsClose();
                                                    openHistoryModal(detailsObjection);
                                                }}
                                            >
                                                View Full History
                                            </Button>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {detailsObjection.status_logs.slice(-3).map((log, index) => (
                                                <div key={log.id || index} className="flex items-center gap-1 text-xs">
                                                    <Badge size="sm" variant="soft" color={statusConfig[log.from_status]?.color || 'default'}>
                                                        {formatStatus(log.from_status)}
                                                    </Badge>
                                                    <span className="text-default-400">→</span>
                                                    <Badge size="sm" variant="soft" color={statusConfig[log.to_status]?.color || 'default'}>
                                                        {formatStatus(log.to_status)}
                                                    </Badge>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </Box>
                    <Flex className="flex-col sm:flex-row gap-2">
                        <Button variant="light" onClick={onDetailsClose} className="w-full sm:w-auto">
                            Close
                        </Button>
                        {detailsObjection?.status === 'draft' && (
                            <Button
                                color="primary"
                                variant="soft"
                                startContent={<PencilIcon className="w-4 h-4" />}
                                onClick={() => {
                                    onDetailsClose();
                                    openEditModal(detailsObjection);
                                }}
                                className="w-full sm:w-auto"
                            >
                                Edit
                            </Button>
                        )}
                        {detailsObjection?.status === 'draft' && (
                            <Button
                                color="primary"
                                startContent={<DocumentArrowUpIcon className="w-4 h-4" />}
                                onClick={() => {
                                    onDetailsClose();
                                    openStatusModal(detailsObjection, 'submit');
                                }}
                                className="w-full sm:w-auto"
                            >
                                Submit for Review
                            </Button>
                        )}
                    </Flex>
                </Dialog.Content>
            </Dialog>

            <div className="flex justify-center p-4">
                <div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3 }}
                    className="w-full max-w-[2000px]"
                >
                    <Card
                        className="transition-all duration-200"
                        style={{
                            borderRadius: `var(--borderRadius, 12px)`,
                            fontFamily: `var(--fontFamily, "Inter")`,
                            transform: `scale(var(--scale, 1))`,
                        }}
                    >
                        {/* Main Card Header */}
                        <CardHeader
                            className="border-b p-0"
                            style={{
                                borderColor: `var(--theme-divider, #E4E4E7)`,
                                background: `linear-gradient(135deg,
                                    color-mix(in srgb, var(--theme-content1) 50%, transparent) 20%,
                                    color-mix(in srgb, var(--theme-content2) 30%, transparent) 10%)`,
                            }}
                        >
                            <div className={`${isLargeScreen ? 'p-6' : isMediumScreen ? 'p-4' : 'p-3'} w-full`}>
                                <div className="flex flex-col space-y-4">
                                    <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                        {/* Title Section */}
                                        <div className="flex items-center gap-3 lg:gap-4">
                                            <div
                                                className={`
                                                    ${isLargeScreen ? 'p-3' : isMediumScreen ? 'p-2.5' : 'p-2'}
                                                    rounded-xl flex items-center justify-center
                                                `}
                                                style={{
                                                    background: `color-mix(in srgb, var(--theme-warning) 15%, transparent)`,
                                                    borderColor: `color-mix(in srgb, var(--theme-warning) 25%, transparent)`,
                                                    borderWidth: `var(--borderWidth, 2px)`,
                                                    borderRadius: `var(--borderRadius, 12px)`,
                                                }}
                                            >
                                                <ShieldExclamationIcon
                                                    className={`
                                                        ${isLargeScreen ? 'w-8 h-8' : isMediumScreen ? 'w-6 h-6' : 'w-5 h-5'}
                                                    `}
                                                    style={{ color: 'var(--theme-warning)' }}
                                                />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <h4
                                                    className={`
                                                        ${isLargeScreen ? 'text-2xl' : isMediumScreen ? 'text-xl' : 'text-lg'}
                                                        font-bold text-foreground
                                                        ${!isLargeScreen ? 'truncate' : ''}
                                                    `}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                >
                                                    Objections Management
                                                </h4>
                                                <p
                                                    className={`
                                                        ${isLargeScreen ? 'text-sm' : 'text-xs'}
                                                        text-default-500
                                                        ${!isLargeScreen ? 'truncate' : ''}
                                                    `}
                                                    style={{
                                                        fontFamily: `var(--fontFamily, "Inter")`,
                                                    }}
                                                >
                                                    Track and manage RFI objections across projects
                                                </p>
                                            </div>
                                        </div>

                                        {/* Action Buttons */}
                                        <div className="flex items-center gap-2 sm:gap-4 w-full lg:w-auto">
                                            <div className="flex items-center gap-1.5 sm:gap-2 flex-wrap justify-end w-full lg:w-auto">
                                                {actionButtons.map((button, index) => (
                                                    <Button
                                                        key={index}
                                                        size={isLargeScreen ? "md" : "sm"}
                                                        variant={button.variant || "flat"}
                                                        color={button.color || "primary"}
                                                        startContent={!isMobile && button.icon}
                                                        isIconOnly={isMobile}
                                                        onClick={button.onPress}
                                                        className={`${button.className || ''} font-medium ${isMobile ? 'min-w-9 h-9' : ''}`}
                                                        aria-label={button.label}
                                                        style={{
                                                            fontFamily: `var(--fontFamily, "Inter")`,
                                                            borderRadius: `var(--borderRadius, 12px)`,
                                                        }}
                                                    >
                                                        {isMobile ? button.icon : button.label}
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>

                        <CardBody className="pt-6">
                            {/* Quick Stats */}
                            <ObjectionsStatsSection
                                apiStats={apiStats}
                                objections={objections}
                                statsLoading={statsLoading}
                                onRefresh={refreshData}
                            />

                            <ObjectionsFiltersBar
                                isMobile={isMobile}
                                showFilters={showFilters}
                                onToggleFilters={() => setShowFilters(!showFilters)}
                                search={search}
                                onSearchChange={handleSearch}
                                filterData={filterData}
                                onFilterChange={handleFilterChange}
                                statuses={statuses}
                                categories={categories}
                                onClearFilters={() => {
                                    setFilterData({ status: 'all', category: 'all', creator: '' });
                                    setSearch('');
                                    setCurrentPage(1);
                                }}
                            />

                            {/* Objections Table/List */}
                            <Card
                                radius={getThemeRadius()}
                                className="bg-content2/50 backdrop-blur-md border border-divider/30"
                                style={{
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    backgroundColor: 'var(--theme-content2)',
                                    borderColor: 'var(--theme-divider)',
                                }}
                            >
                                <CardBody className="p-4">
                                    <ErrorBoundary
                                        fallbackTitle="Unable to load objections"
                                        fallbackDescription="There was an error loading the objections. Please try refreshing."
                                        onRetry={refreshData}
                                    >
                                        {tableLoading ? (
                                            isMobile ? renderMobileSkeleton() : renderTableSkeleton()
                                        ) : isMobile ? (
                                            <PullToRefresh
                                                onRefresh={handlePullToRefresh}
                                                disabled={tableLoading}
                                            >
                                                <div className="flex flex-col gap-2">
                                                    {objections?.data?.length > 0 ? (
                                                        objections.data.map((objection) => (
                                                            <SwipeableCard key={objection.id}>
                                                                <ObjectionAccordionItem
                                                                    objection={objection}
                                                                    isExpanded={expandedItems.has(objection.id)}
                                                                    onToggle={() => toggleExpanded(objection.id)}
                                                                    getCategoryChip={getCategoryChip}
                                                                    getTypeChip={getTypeChip}
                                                                    formatDate={formatDate}
                                                                    openAttachModal={openAttachModal}
                                                                    openEditModal={openEditModal}
                                                                    openStatusModal={openStatusModal}
                                                                    openHistoryModal={openHistoryModal}
                                                                    handleDeleteObjection={handleDeleteObjection}
                                                                />
                                                            </SwipeableCard>
                                                        ))
                                                    ) : (
                                                        <div className="text-center py-12">
                                                            <ShieldExclamationIcon className="w-16 h-16 mx-auto text-default-300 mb-4" />
                                                            <p className="text-default-500 font-medium">No objections found</p>
                                                            <p className="text-default-400 text-sm mt-1">Create your first objection to get started</p>
                                                        </div>
                                                    )}
                                                </div>
                                            </PullToRefresh>
                                        ) : (
                                            <Table
                                                aria-label="Objections table"
                                                removeWrapper
                                                classNames={{
                                                    base: "overflow-auto",
                                                    table: "min-w-full",
                                                    th: "bg-default-100 text-default-600 text-xs font-semibold uppercase",
                                                    td: "py-3",
                                                }}
                                            >
                                                <TableHeader columns={columns}>
                                                    {(column) => (
                                                        <TableColumn key={column.key}>
                                                            {column.label}
                                                        </TableColumn>
                                                    )}
                                                </TableHeader>
                                                <TableBody
                                                    items={objections?.data || []}
                                                    emptyContent={
                                                        <div className="text-center py-12">
                                                            <ShieldExclamationIcon className="w-16 h-16 mx-auto text-default-300 mb-4" />
                                                            <p className="text-default-500 font-medium">No objections found</p>
                                                            <p className="text-default-400 text-sm mt-1">Create your first objection to get started</p>
                                                        </div>
                                                    }
                                                >
                                                    {(objection) => (
                                                        <TableRow key={objection.id} className={objection.is_active ? 'bg-warning-50/50 dark:bg-warning-900/10' : ''}>
                                                            {(columnKey) => (
                                                                <TableCell>{renderCell(objection, columnKey)}</TableCell>
                                                            )}
                                                        </TableRow>
                                                    )}
                                                </TableBody>
                                            </Table>
                                        )}

                                        {/* Pagination */}
                                        {objections?.last_page > 1 && (
                                            <div className="flex justify-center mt-4 pt-4 border-t border-divider/30">
                                                <Pagination
                                                    total={objections.last_page}
                                                    page={objections.current_page}
                                                    onChange={handlePageChange}
                                                    size={isMobile ? "sm" : "md"}
                                                    showControls
                                                    showShadow
                                                    color="primary"
                                                />
                                            </div>
                                        )}
                                    </ErrorBoundary>
                                </CardBody>
                            </Card>
                        </CardBody>
                    </Card>
                </div>
            </div>
        </>
    );
};

ObjectionsIndex.layout = (page) => <App>{page}</App>;

export default ObjectionsIndex;
