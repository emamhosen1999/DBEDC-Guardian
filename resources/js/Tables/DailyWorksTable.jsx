import SwipeableCard from '@/Components/Common/SwipeableCard';
import BulkImportResponseStatusModal from '@/Components/DailyWork/BulkImportResponseStatusModal';
import BulkImportSubmitModal from '@/Components/DailyWork/BulkImportSubmitModal';
import BulkResponseStatusModal from '@/Components/DailyWork/BulkResponseStatusModal';
import BulkSubmitModal from '@/Components/DailyWork/BulkSubmitModal';
import ObjectionsModal from '@/Components/DailyWork/ObjectionsModal';
import ObjectionWarningModal from '@/Components/DailyWork/ObjectionWarningModal';
import RfiFilesModal from '@/Components/DailyWork/RfiFilesModal';
import StatusUpdateModal from '@/Components/StatusUpdateModal';
import BulkInchargeModal from '@/Components/DailyWork/BulkInchargeModal';
import BulkStatusModal from '@/Components/DailyWork/BulkStatusModal';
import BulkCompletionDateModal from '@/Components/DailyWork/BulkCompletionDateModal';
import BulkDeleteModal from '@/Components/DailyWork/BulkDeleteModal';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { showToast } from '@/utils/toastUtils';
import { router, usePage } from "@inertiajs/react";
import { debounce } from "lodash";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import {
    ArchiveIcon,
    CalendarIcon,
    CheckCircledIcon,
    CheckIcon, ChevronDownIcon,
    ChevronLeftIcon, ChevronRightIcon,
    ClipboardIcon,
    ClockIcon,
    CrossCircledIcon,
    DownloadIcon,
    ExclamationTriangleIcon,
    EyeOpenIcon,
    FileIcon,
    FileTextIcon,
    HomeIcon,
    OpenInNewWindowIcon,
    Pencil1Icon,
    PersonIcon,
    PlusCircledIcon,
    ReloadIcon,
    TrashIcon,
    UploadIcon
} from '@radix-ui/react-icons';
import {
    Badge, Box,
    Button,
    Card,
    Flex,
    IconButton,
    Table as RadixTable,
    ScrollArea,
    Select,
    Separator,
    Skeleton,
    Text,
    TextField,
    Tooltip,
    Tabs
} from '@radix-ui/themes';
import axios from 'axios';
import { jsPDF } from "jspdf";

// Utility function to highlight matching text in search results (supports multi-word search)
const HighlightedText = ({ text, searchTerm }) => {
    if (!searchTerm || !text) return <>{text}</>;
    
    const textStr = String(text);
    
    // Split search term into individual words and filter out empty strings
    const searchWords = searchTerm.trim().split(/\s+/).filter(Boolean);
    
    if (searchWords.length === 0) return <>{textStr}</>;
    
    // Escape special regex characters in each word
    const escapedWords = searchWords.map(word => 
        word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    );
    
    // Create a regex that matches any of the search words (case insensitive)
    const regex = new RegExp(`(${escapedWords.join('|')})`, 'gi');
    
    // Split text by matches
    const parts = textStr.split(regex);
    
    if (parts.length === 1) return <>{textStr}</>;
    
    return (
        <>
            {parts.map((part, index) => {
                // Check if this part matches any of the search words
                const isMatch = searchWords.some(word => 
                    part.toLowerCase() === word.toLowerCase()
                );
                
                return isMatch ? (
                    <mark 
                        key={index} 
                        style={{ background: 'var(--yellow-5)', color: 'var(--yellow-11)', padding: '0 2px', borderRadius: 'var(--radius-1)' }}
                    >
                        {part}
                    </mark>
                ) : (
                    <span key={index}>{part}</span>
                );
            })}
        </>
    );
};

const DailyWorksTable = ({ 
    allData, 
    setData, 
    loading, 
    handleClickOpen, 
    allInCharges, 
    reports, 
    juniors, 
    reports_with_daily_works, 
    openModal, 
    setCurrentRow, 
    filteredData, 
    setFilteredData,
    currentPage,
    totalRows,
    lastPage,
    onPageChange,
    searchTerm = ''
}) => {
    const { auth, users, jurisdictions } = usePage().props;
    const isLargeScreen = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isMobile = useMediaQuery('(max-width: 640px)');

    // Handle refresh functionality
    const handleRefresh = useCallback(() => {
        router.reload({ only: ['allData', 'reports_with_daily_works'], onSuccess: () => {
            showToast.success('Daily works data refreshed successfully');
        }});
    }, []);

    const [isUpdating, setIsUpdating] = useState(false);
    const [updatingWorkId, setUpdatingWorkId] = useState(null);
    
    // Mobile tab state - persist across pagination
    const [selectedTab, setSelectedTab] = useState("structure");
    
    // Define work types for mobile tabs - shared between loading skeleton and actual component
    const workTypes = [
        { key: "structure", label: "Structure", icon: "🏗️" },
        { key: "embankment", label: "Embankment", icon: "🏔️" },
        { key: "pavement", label: "Pavement", icon: "🛣️" }
    ];
    
    // Mobile accordion state - persist across all updates and re-renders
    const [expandedItems, setExpandedItems] = useState(new Set());
    
    // RFI Files Modal state
    const [rfiModalOpen, setRfiModalOpen] = useState(false);
    const [rfiModalWork, setRfiModalWork] = useState(null);
    
    // Status Update Modal state
    const [statusModalOpen, setStatusModalOpen] = useState(false);
    const [statusModalWork, setStatusModalWork] = useState(null);
    
    // Objections Modal state
    const [objectionsModalOpen, setObjectionsModalOpen] = useState(false);
    const [objectionsModalWork, setObjectionsModalWork] = useState(null);
    
    // Objection Warning Modal state (for submission date changes)
    const [objectionWarningModal, setObjectionWarningModal] = useState({
        isOpen: false,
        dailyWork: null,
        newSubmissionDate: null,
        activeObjectionsCount: 0,
        activeObjections: [],
        isLoading: false,
    });

    // Bulk selection state
    const [selectedKeys, setSelectedKeys] = useState(new Set([]));
    const [bulkSubmitModalOpen, setBulkSubmitModalOpen] = useState(false);
    const [bulkImportModalOpen, setBulkImportModalOpen] = useState(false);
    const [bulkResponseStatusModalOpen, setBulkResponseStatusModalOpen] = useState(false);
    const [bulkImportResponseStatusModalOpen, setBulkImportResponseStatusModalOpen] = useState(false);
    const [bulkInchargeModalOpen, setBulkInchargeModalOpen] = useState(false);
    const [bulkStatusModalOpen, setBulkStatusModalOpen] = useState(false);
    const [bulkCompletionDateModalOpen, setBulkCompletionDateModalOpen] = useState(false);
    const [bulkDeleteModalOpen, setBulkDeleteModalOpen] = useState(false);

    // Get selected works for bulk operations
    const selectedWorks = useMemo(() => {
        if (selectedKeys === "all") {
            return allData || [];
        }
        return (allData || []).filter(work => selectedKeys.has(String(work.id)));
    }, [selectedKeys, allData]);

    // Handle bulk submit success - update local state without page reload
    const handleBulkSubmitSuccess = useCallback((result) => {
        // Clear selection
        setSelectedKeys(new Set([]));
        
        // Update local state with the submitted works from the response
        if (result?.submitted && result.submitted.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.submitted
                    .filter(item => item.dailyWork)
                    .map(item => [item.dailyWork.id, item.dailyWork])
            );
            
            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }
    }, [setData]);

    // Handle bulk import success - update local state without page reload
    const handleBulkImportSuccess = useCallback((result) => {
        // Update local state with the submitted works from the response
        if (result?.submitted && result.submitted.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.submitted
                    .filter(item => item.dailyWork)
                    .map(item => [item.dailyWork.id, item.dailyWork])
            );
            
            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }
    }, [setData]);

    // Handle bulk response status success - update local state without page reload
    const handleBulkResponseStatusSuccess = useCallback((result) => {
        // Clear selection
        setSelectedKeys(new Set([]));
        
        // Update local state with the updated works from the response
        if (result?.updated && result.updated.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.updated
                    .filter(item => item.dailyWork)
                    .map(item => [item.dailyWork.id, item.dailyWork])
            );
            
            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }
    }, [setData]);

    // Handle bulk import response status success - update local state without page reload
    const handleBulkImportResponseStatusSuccess = useCallback((result) => {
        // Clear selection
        setSelectedKeys(new Set([]));

        // Update local state with the updated works from the response
        if (result?.updated && result.updated.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.updated.map(item => [item.id, item])
            );

            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }
    }, [setData]);

    // Handle bulk incharge success - update local state without page reload
    const handleBulkInchargeSuccess = useCallback((result) => {
        setSelectedKeys(new Set([]));

        if (result?.updated && result.updated.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.updated.map(item => [item.id, item])
            );

            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }

        showToast.success('Incharge updated successfully');
    }, [setData]);

    // Handle bulk status success - update local state without page reload
    const handleBulkStatusSuccess = useCallback((result) => {
        setSelectedKeys(new Set([]));

        if (result?.updated && result.updated.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.updated.map(item => [item.id, item])
            );

            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }

        showToast.success('Status updated successfully');
    }, [setData]);

    // Handle bulk completion date success - update local state without page reload
    const handleBulkCompletionDateSuccess = useCallback((result) => {
        setSelectedKeys(new Set([]));

        if (result?.updated && result.updated.length > 0 && setData) {
            const updatedWorksMap = new Map(
                result.updated.map(item => [item.id, item])
            );

            if (updatedWorksMap.size > 0) {
                setData(prevWorks =>
                    prevWorks.map(w =>
                        updatedWorksMap.has(w.id) ? updatedWorksMap.get(w.id) : w
                    )
                );
            }
        }

        showToast.success('Completion date updated successfully');
    }, [setData]);

    // Handle bulk delete success - remove deleted works from local state
    const handleBulkDeleteSuccess = useCallback((result) => {
        setSelectedKeys(new Set([]));

        if (result?.deleted && result.deleted.length > 0 && setData) {
            const deletedIds = new Set(result.deleted);
            setData(prevWorks => prevWorks.filter(w => !deletedIds.has(w.id)));
        }

        showToast.success('Works deleted successfully');
    }, [setData]);

    // Function to open Status Update modal
    const openStatusModal = useCallback((work) => {
        setStatusModalWork(work);
        setStatusModalOpen(true);
    }, []);
    
    // Function to handle status update from modal
    const handleStatusUpdated = useCallback((updatedWork) => {
        if (updatedWork && setData) {
            setData(prevWorks =>
                prevWorks.map(w =>
                    w.id === updatedWork.id ? updatedWork : w
                )
            );
        }
    }, [setData]);
    
    // Function to open RFI files modal
    const openRfiFilesModal = useCallback((work) => {
        setRfiModalWork(work);
        setRfiModalOpen(true);
    }, []);
    
    // Function to open Objections modal
    const openObjectionsModal = useCallback((work) => {
        setObjectionsModalWork(work);
        setObjectionsModalOpen(true);
    }, []);
    
    // Function to handle objections update - update local state without full reload
    const handleObjectionsUpdated = useCallback((workId, newActiveCount) => {
        if (workId && setData) {
            // Update the local data to reflect new objection count
            const updatedData = allData.map(w => 
                w.id === workId ? { ...w, active_objections_count: newActiveCount } : w
            );
            setData(updatedData);
            
            // Also update the modal work if it's the same
            if (objectionsModalWork?.id === workId) {
                setObjectionsModalWork(prev => prev ? { ...prev, active_objections_count: newActiveCount } : prev);
            }
        }
    }, [allData, setData, objectionsModalWork]);
    
    // Function to handle files update from modal
    const handleRfiFilesUpdated = useCallback((newCount) => {
        // Update the local data to reflect new file count
        if (rfiModalWork && setData) {
            const updatedData = allData.map(w => 
                w.id === rfiModalWork.id ? { ...w, rfi_files_count: newCount } : w
            );
            setData(updatedData);
        }
    }, [rfiModalWork, allData, setData]);
    
    // Function to toggle expanded state for a specific work item
    const toggleExpanded = useCallback((workId) => {
        setExpandedItems(prev => {
            const newSet = new Set(prev);
            if (newSet.has(workId)) {
                newSet.delete(workId);
            } else {
                newSet.add(workId);
            }
            return newSet;
        });
    }, []);

    // Preserve expanded state across data updates
    useEffect(() => {
        // When allData changes, preserve expanded items that still exist
        if (allData && allData.length > 0) {
            const currentWorkIds = new Set(allData.map(work => work.id));
            setExpandedItems(prev => {
                const preserved = new Set();
                prev.forEach(id => {
                    if (currentWorkIds.has(id)) {
                        preserved.add(id);
                    }
                });
                return preserved;
            });
        }
    }, [allData]);

    // Use available data with fallbacks
    const availableInCharges = allInCharges || users || [];
    const availableJuniors = juniors || users || [];
    const availableJurisdictions = jurisdictions || [];

    // Permission-based access control using designations
    const userIsAdmin = auth.roles?.includes('Administrator') || auth.roles?.includes('Super Administrator') || auth.roles?.includes('Daily Work Manager') || false;
    const userIsEmployee = auth.roles?.includes('Employee') || false;
    const userIsSE = auth.designation === 'Supervision Engineer' || false;
    const userIsQCI = auth.designation === 'Quality Control Inspector' || auth.designation === 'Asst. Quality Control Inspector' || false;
    
    // Helper function to check if user has jurisdiction (is incharge of any jurisdiction)
    const userHasJurisdiction = useMemo(() => {
        if (!auth.user?.id) return false;
        return availableJurisdictions?.some(jurisdiction => 
            jurisdiction.incharge === auth.user.id
        ) || false;
    }, [availableJurisdictions, auth.user?.id]);
    
    // Helper function to check if current user is the incharge of a specific work
    const isUserInchargeOfWork = (work) => {
        return work?.incharge && String(work.incharge) === String(auth.user?.id);
    };
    
    // Helper function to check if current user is the assignee of a specific work
    const isUserAssigneeOfWork = (work) => {
        return work?.assigned && String(work.assigned) === String(auth.user?.id);
    };
    
    // Helper function to check if manager (report_to) is incharge of a specific work
    const isManagerInchargeOfWork = (work) => {
        if (!auth.user?.report_to) return false;
        return work?.incharge && String(work.incharge) === String(auth.user.report_to);
    };
    
    // Check if user can assign for a specific work
    const canUserAssign = (work) => {
        if (userIsAdmin) return true;
        
        // Employee logic based on jurisdiction
        if (userIsEmployee) {
            if (userHasJurisdiction) {
                // Employee has jurisdiction: can assign to works where they are incharge
                return isUserInchargeOfWork(work);
            } else {
                // Employee has no jurisdiction: CANNOT assign
                return false;
            }
        }
        
        // For other roles: incharge can assign
        return isUserInchargeOfWork(work);
    };
    
    // Check if user can update status and completion time
    const canUserUpdateStatus = (work) => {
        if (userIsAdmin) return true;
        
        // Employee logic based on jurisdiction
        if (userIsEmployee) {
            if (userHasJurisdiction) {
                // Employee has jurisdiction: can update works where they are incharge
                return isUserInchargeOfWork(work);
            } else {
                // Employee has no jurisdiction: can update works where manager is incharge
                return isManagerInchargeOfWork(work);
            }
        }
        
        // For other roles (non-employee, non-admin): SE or assignee can update
        return userIsSE || isUserAssigneeOfWork(work);
    };
    
    // Check if user can update completion time (same logic as status)
    const canUserUpdateCompletionTime = (work) => {
        return canUserUpdateStatus(work);
    };
    
    // Check if user can update inspection details (same logic as status)
    const canUserUpdateInspectionDetails = (work) => {
        return canUserUpdateStatus(work);
    };
    
    // Check if user can create/manage objections for a specific work
    const canUserCreateObjections = (work) => {
        if (userIsAdmin) return true;
        
        // Employee logic based on jurisdiction
        if (userIsEmployee) {
            if (userHasJurisdiction) {
                // Employee has jurisdiction: can create objections for works where they are incharge
                return isUserInchargeOfWork(work);
            } else {
                // Employee has no jurisdiction: can create objections for works where manager is incharge
                return isManagerInchargeOfWork(work);
            }
        }
        
        // For other roles (non-employee, non-admin): incharge or assignee can create objections
        return isUserInchargeOfWork(work) || isUserAssigneeOfWork(work);
    };
    
    // Check if user can review objections (admin/manager level)
    // Managers include: Super Admin, Admin, Project Manager, Consultant, HR Manager
    const canUserReviewObjections = () => {
        const managerRoles = ['Super Administrator', 'Administrator', 'Project Manager', 'Consultant', 'HR Manager'];
        return auth.roles?.some(role => managerRoles.includes(role)) || false;
    };
    
    // Check if user is only an incharge (not admin)
    const isUserOnlyIncharge = useMemo(() => {
        if (userIsAdmin) return false;
        return allData?.some(work => isUserInchargeOfWork(work)) || false;
    }, [userIsAdmin, allData, auth.user?.id]);
    
    // Check if user is only an assignee (not admin and not incharge)
    const isUserOnlyAssignee = useMemo(() => {
        if (userIsAdmin) return false;
        if (isUserOnlyIncharge) return false;
        return allData?.some(work => isUserAssigneeOfWork(work)) || false;
    }, [userIsAdmin, isUserOnlyIncharge, allData, auth.user?.id]);
    
    // Check if user should see the incharge column (only admins)
    const shouldShowInchargeColumn = useMemo(() => {
        return userIsAdmin;
    }, [userIsAdmin]);
    
    // Check if user should see the assigned column
    // - Admins and incharges should see the column
    // - Employees with jurisdiction should see the column
    // - Assignees should NOT see the column
    const shouldShowAssignedColumn = useMemo(() => {
        if (userIsAdmin) return true;
        if (isUserOnlyIncharge) return true;
        if (userIsEmployee && userHasJurisdiction) return true;
        // Assignees don't see assignee column
        return false;
    }, [userIsAdmin, isUserOnlyIncharge, userIsEmployee, userHasJurisdiction]);
    
    // Check if user should see the RFI submission date column (only admins)
    const shouldShowRfiColumn = useMemo(() => {
        return userIsAdmin;
    }, [userIsAdmin]);
    
    // Check if user should see edit/delete actions
    const shouldShowActions = useMemo(() => {
        if (userIsAdmin) return true;
        
        // Employee logic based on jurisdiction
        if (userIsEmployee) {
            // Employees can see actions if they have jurisdiction or if they have a manager
            return userHasJurisdiction || auth.user?.report_to;
        }
        
        // For other roles, only admins see actions
        return false;
    }, [userIsAdmin, userIsEmployee, userHasJurisdiction, auth.user?.report_to]);

    // Filter incharges to only show users who are incharge of any jurisdiction
    // Get unique incharge IDs from jurisdictions
    const jurisdictionInchargeIds = [...new Set(
        availableJurisdictions
            .map(jurisdiction => jurisdiction.incharge)
            .filter(id => id) // Remove nulls
    )];

    // Filter available incharges to only those who manage jurisdictions
    const jurisdictionInCharges = availableInCharges.filter(user => 
        jurisdictionInchargeIds.includes(user.id)
    );

    // Fallback to availableInCharges if no jurisdiction incharges found
    const finalInCharges = jurisdictionInCharges.length > 0 ? jurisdictionInCharges : availableInCharges;

    // Function to get available assignees based on selected incharge
    const getAvailableAssignees = (inchargeId) => {
        if (!inchargeId) return [];
        return users?.filter(user => user.report_to === parseInt(inchargeId)) || [];
    };

    // Function to get the appropriate status key for the dropdown
    const getStatusKey = (status, inspectionResult) => {
        if (!status) return 'new';
        
        const statusLower = status.toLowerCase();
        
        // Handle completed status with inspection result
        if (statusLower === 'completed' && inspectionResult) {
            return `completed:${inspectionResult.toLowerCase()}`;
        }
        
        // Handle composite status (already in correct format)
        if (statusLower.includes(':')) {
            return statusLower;
        }
        
        // Map statuses - return as-is if valid, default to 'new'
        const validStatuses = ['new', 'in-progress', 'completed', 'rejected', 'resubmission', 'pending', 'emergency'];
        if (validStatuses.includes(statusLower)) {
            return statusLower;
        }
        
        // Default completed to pass if no inspection result
        if (statusLower === 'completed') {
            return 'completed:pass';
        }
        
        return 'new';
    };

    // Status configuration - consistent with backend DailyWork::$statuses and $inspectionResults
    const statusConfig = {
        // Base statuses (without inspection result)
        'new': {
            color: 'indigo',
            icon: PlusCircledIcon,
            label: 'New',
        },
        'in-progress': {
            color: 'violet',
            icon: ReloadIcon,
            label: 'In Progress',
        },
        'pending': {
            color: 'gray',
            icon: ClockIcon,
            label: 'Pending',
        },
        'rejected': {
            color: 'red',
            icon: CrossCircledIcon,
            label: 'Rejected',
        },
        'resubmission': {
            color: 'orange',
            icon: ReloadIcon,
            label: 'Resubmission',
        },
        'emergency': {
            color: 'red',
            icon: ExclamationTriangleIcon,
            label: 'Emergency',
        },
        // Completed statuses with inspection results (matching database enum: pass, fail, conditional, pending)
        'completed:pass': {
            color: 'green',
            icon: CheckCircledIcon,
            label: 'Completed: Passed',
        },
        'completed:fail': {
            color: 'red',
            icon: CrossCircledIcon,
            label: 'Completed: Failed',
        },
        'completed:conditional': {
            color: 'orange',
            icon: CheckCircledIcon,
            label: 'Completed: Conditional',
        },
        'completed:pending': {
            color: 'gray',
            icon: CheckCircledIcon,
            label: 'Completed: Pending Review',
        },
    };

    const getWorkTypeIcon = (type) => {
        switch (type?.toLowerCase()) {
            case "embankment":
                return <ArchiveIcon style={{ color: 'var(--amber-9)' }} />;
            case "structure":
                return <FileIcon style={{ color: 'var(--blue-9)' }} />;
            case "pavement":
                return <HomeIcon style={{ color: 'var(--gray-9)' }} />;
            case "earthwork":
                return <ArchiveIcon style={{ color: 'var(--green-9)' }} />;
            case "drainage":
                return <FileIcon style={{ color: 'var(--cyan-9)' }} />;
            case "roadwork":
                return <HomeIcon style={{ color: 'var(--orange-9)' }} />;
            case "bridge":
                return <ArchiveIcon style={{ color: 'var(--purple-9)' }} />;
            case "culvert":
                return <FileIcon style={{ color: 'var(--indigo-9)' }} />;
            case "standard":
            default:
                return <FileTextIcon style={{ color: 'var(--gray-9)' }} />;
        }
    };

    const getStatusBadge = (status, inspectionResult = null) => {
        let key = status;
        if (status === 'completed' && inspectionResult) key = `completed:${inspectionResult}`;
        const config = statusConfig[key] || statusConfig['new'];
        const StatusIcon = config.icon;
        return (
            <Badge color={config.color} variant="soft" size="1" style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                <StatusIcon />
                {config.label}
            </Badge>
        );
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'No date';
        
        try {
            return new Date(dateString).toLocaleDateString("en-US", {
                day: "numeric",
                month: "short",
                year: "numeric"
            });
        } catch (error) {
            return 'Invalid date';
        }
    };

    const formatDateTime = (dateTimeString) => {
        if (!dateTimeString) return 'Not set';
        
        try {
            return new Date(dateTimeString).toLocaleString("en-US", {
                day: "numeric",
                month: "short",
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit"
            });
        } catch (error) {
            return 'Invalid datetime';
        }
    };

    const getUserInfo = (userId) => {
        if (!userId) return { name: 'Unassigned', profile_image_url: null, profile_image: null };
        
        const user = availableInCharges?.find((u) => String(u.id) === String(userId)) || 
                    availableJuniors?.find((u) => String(u.id) === String(userId)) ||
                    users?.find((u) => String(u.id) === String(userId));
        return user || { name: 'Unassigned', profile_image_url: null, profile_image: null };
    };

    const getJurisdictionInfo = (jurisdictionId) => {
        if (!jurisdictionId) return { name: 'No jurisdiction assigned', location: 'Unknown' };
        
        const jurisdiction = availableJurisdictions?.find((j) => String(j.id) === String(jurisdictionId));
        return jurisdiction || { name: 'Unknown jurisdiction', location: 'Unknown' };
    };

    // Image capture functions
    const captureDocument = (taskNumber) => {
        return new Promise((resolve, reject) => {
            const fileInput = document.createElement("input");
            fileInput.type = "file";
            fileInput.accept = "image/*";
            fileInput.multiple = true;

            document.body.appendChild(fileInput);

            fileInput.onchange = async () => {
                const files = Array.from(fileInput.files);
                if (files.length > 0) {
                    try {
                        const images = [];

                        for (let file of files) {
                            const img = await loadImage(file);
                            const resizedCanvas = resizeImage(img, 1024);
                            images.push(resizedCanvas);
                        }

                        const pdfBlob = await combineImagesToPDF(images);
                        const pdfFile = new File([pdfBlob], `${taskNumber}_scanned_document.pdf`, { type: "application/pdf" });
                        resolve(pdfFile);

                        document.body.removeChild(fileInput);
                    } catch (error) {
                        reject(error);
                        document.body.removeChild(fileInput);
                    }
                } else {
                    reject(new Error("No files selected"));
                    document.body.removeChild(fileInput);
                }
            };

            fileInput.click();
        });
    };

    const loadImage = (file) => {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.src = URL.createObjectURL(file);
            img.onload = () => {
                resolve(img);
                URL.revokeObjectURL(img.src);
            };
            img.onerror = () => reject(new Error("Failed to load image"));
        });
    };

    const resizeImage = (img, targetHeight) => {
        const aspectRatio = img.width / img.height;
        const targetWidth = targetHeight * aspectRatio;
        const canvas = document.createElement("canvas");
        canvas.width = targetWidth;
        canvas.height = targetHeight;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(img, 0, 0, targetWidth, targetHeight);
        return canvas;
    };

    const combineImagesToPDF = (images) => {
        return new Promise((resolve, reject) => {
            const pdf = new jsPDF({
                orientation: "portrait",
                unit: "px",
                format: [images[0].width, images[0].height],
            });

            images.forEach((canvas, index) => {
                if (index > 0) pdf.addPage();
                const imgData = canvas.toDataURL("image/jpeg", 1.0);
                pdf.addImage(imgData, "JPEG", 0, 0, canvas.width, canvas.height);
            });

            try {
                const pdfBlob = pdf.output("blob");
                resolve(pdfBlob);
            } catch (error) {
                reject(error);
            }
        });
    };

    const uploadImage = async (taskId, imageFile) => {
        const promise = new Promise(async (resolve, reject) => {
            try {
                const formData = new FormData();
                formData.append("taskId", taskId);
                formData.append("file", imageFile);

                const response = await axios.post(route('dailyWorks.uploadRFI'), formData, {
                    headers: {"Content-Type": "multipart/form-data"},
                });

                if (response.status === 200) {
                    setData(prevTasks =>
                        prevTasks.map(task =>
                            task.id === taskId ? {...task, file: response.data.url} : task
                        )
                    );
                    resolve([response.data.message || 'RFI file uploaded successfully']);
                }
            } catch (error) {
                console.error(error);
                reject(error.response.statusText || 'Failed to upload RFI file');
            }
        });

        showToast.promise(promise, {
            loading: 'Uploading RFI file...',
            success: (data) => data.join(', '),
            error: (data) => data,
        });
    };

    // Handle status updates - simplified approach
    const updateWorkStatus = useCallback(async (work, newStatus) => {
        if (updatingWorkId === work.id) return;

        setUpdatingWorkId(work.id);
        const promise = new Promise(async (resolve, reject) => {
            try {
                // Parse composite status (e.g., 'completed:pass' -> status: 'completed', inspection_result: 'pass')
                let actualStatus = newStatus;
                let inspectionResult = null;
                
                if (newStatus.includes(':')) {
                    const [statusPart, resultPart] = newStatus.split(':');
                    actualStatus = statusPart;
                    inspectionResult = resultPart;
                }

                // Simple status update - only send what's needed
                const updateData = {
                    id: work.id,
                    status: actualStatus,
                };

                // Add inspection result if it exists
                if (inspectionResult) {
                    updateData.inspection_result = inspectionResult;
                }

                const response = await axios.post(route('dailyWorks.updateStatus'), updateData);

                if (response.status === 200) {
                    // Update local state with the response data
                    setData(prevWorks =>
                        prevWorks.map(w =>
                            w.id === work.id ? response.data.dailyWork : w
                        )
                    );
                    
                    const statusLabel = statusConfig[newStatus]?.label || `${actualStatus}${inspectionResult ? ` - ${inspectionResult}` : ''}`;
                    resolve(response.data.message || `Work status updated to ${statusLabel}`);
                }
            } catch (error) {
                console.error('Error updating work status:', error.response?.data || error.message || error);
                let errorMsg = "Failed to update work status";
                
                if (error.response?.status === 422 && error.response.data?.errors) {
                    // Handle validation errors
                    const errors = error.response.data.errors;
                    const errorMessages = Object.values(errors).flat();
                    errorMsg = errorMessages.join(', ');
                } else if (error.response?.data?.error) {
                    errorMsg = error.response.data.error;
                } else if (error.response?.data?.message) {
                    errorMsg = error.response.data.message;
                } else if (error.response?.statusText) {
                    errorMsg = error.response.statusText;
                }
                
                reject(errorMsg);
            } finally {
                setUpdatingWorkId(null);
            }
        });

        showToast.promise(promise, {
            loading: 'Updating work status...',
            success: (data) => data || "Work status updated successfully!",
            error: (data) => data || "Failed to update work status",
        });
    }, [setData, updatingWorkId]);

    // Handle completion time updates
    const updateCompletionTime = useCallback(async (work, completionTime) => {
        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route('dailyWorks.updateCompletionTime'), {
                    id: work.id,
                    completion_time: completionTime,
                });

                if (response.status === 200) {
                    setData(prevWorks =>
                        prevWorks.map(w =>
                            w.id === work.id ? response.data.dailyWork : w
                        )
                    );
                    resolve('Completion time updated successfully');
                }
            } catch (error) {
                console.error('Error updating completion time:', error.response?.data || error.message || error);
                reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update completion time');
            }
        });

        showToast.promise(promise, {
            loading: 'Updating completion time...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    }, [setData]);

    // Handle submission time updates
    const updateSubmissionTime = useCallback(async (work, submissionTime) => {
        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route('dailyWorks.updateSubmissionTime'), {
                    id: work.id,
                    submission_time: submissionTime,
                });

                if (response.status === 200) {
                    setData(prevWorks =>
                        prevWorks.map(w =>
                            w.id === work.id ? response.data.dailyWork : w
                        )
                    );
                    resolve('Submission time updated successfully');
                }
            } catch (error) {
                console.error('Error updating submission time:', error.response?.data || error.message || error);
                reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update submission time');
            }
        });

        showToast.promise(promise, {
            loading: 'Updating submission time...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    }, [setData]);

  

    // Create a ref to store the current allData and setData
    const allDataRef = useRef(allData);
    const setDataRef = useRef(setData);
    allDataRef.current = allData;
    setDataRef.current = setData;

 

    // Debounced function for updating incharge
    const debouncedUpdateIncharge = useMemo(
        () => debounce(async (workId, inchargeId) => {
            const work = allDataRef.current?.find(w => w.id === workId);
            if (!work) {
                showToast.error('Work not found');
                return;
            }

            const promise = new Promise(async (resolve, reject) => {
                try {
                    const response = await axios.post(route('dailyWorks.updateIncharge'), {
                        id: work.id,
                        incharge: inchargeId,
                    });

                    if (response.status === 200) {
                        setDataRef.current(prevWorks =>
                            prevWorks.map(w =>
                                w.id === work.id ? response.data.dailyWork : w
                            )
                        );
                        resolve('Incharge updated successfully');
                    }
                } catch (error) {
                    console.error('Error updating incharge:', error.response?.data || error.message || error);
                    reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update incharge');
                }
            });

            showToast.promise(promise, {
                loading: 'Updating incharge...',
                success: (msg) => msg,
                error: (msg) => msg,
            });
        }, 500), // 0.5 second delay for dropdowns
        []
    );

    // Debounced function for updating assigned user
    const debouncedUpdateAssigned = useMemo(
        () => debounce(async (workId, assignedId) => {
            const work = allDataRef.current?.find(w => w.id === workId);
            if (!work) {
                showToast.error('Work not found');
                return;
            }

            const promise = new Promise(async (resolve, reject) => {
                try {
                    const response = await axios.post(route('dailyWorks.updateAssigned'), {
                        id: work.id,
                        assigned: assignedId,
                    });

                    if (response.status === 200) {
                        setDataRef.current(prevWorks =>
                            prevWorks.map(w =>
                                w.id === work.id ? response.data.dailyWork : w
                            )
                        );
                        resolve('Assigned user updated successfully');
                    }
                } catch (error) {
                    console.error('Error updating assigned user:', error.response?.data || error.message || error);
                    reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update assigned user');
                }
            });

            showToast.promise(promise, {
                loading: 'Updating assigned user...',
                success: (msg) => msg,
                error: (msg) => msg,
            });
        }, 500), // 0.5 second delay for dropdowns
        []
    );

    // Debounced function for updating completion time
    const debouncedUpdateCompletionTime = useMemo(
        () => debounce(async (workId, completionTime) => {
            const work = allDataRef.current?.find(w => w.id === workId);
            if (!work) {
                showToast.error('Work not found');
                return;
            }

            const promise = new Promise(async (resolve, reject) => {
                try {
                    const response = await axios.post(route('dailyWorks.updateCompletionTime'), {
                        id: work.id,
                        completion_time: completionTime,
                    });

                    if (response.status === 200) {
                        setDataRef.current(prevWorks =>
                            prevWorks.map(w =>
                                w.id === work.id ? response.data.dailyWork : w
                            )
                        );
                        resolve('Completion time updated successfully');
                    }
                } catch (error) {
                    console.error('Error updating completion time:', error.response?.data || error.message || error);
                    reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update completion time');
                }
            });

            showToast.promise(promise, {
                loading: 'Updating completion time...',
                success: (msg) => msg,
                error: (msg) => msg,
            });
        }, 800), // 0.8 second delay for time inputs
        []
    );

    // Debounced function for updating inspection details
    const debouncedUpdateInspectionDetails = useMemo(
        () => debounce(async (workId, inspectionDetails) => {
            const work = allDataRef.current?.find(w => w.id === workId);
            if (!work) {
                showToast.error('Work not found');
                return;
            }

            const promise = new Promise(async (resolve, reject) => {
                try {
                    const response = await axios.post(route('dailyWorks.updateInspectionDetails'), {
                        id: work.id,
                        inspection_details: inspectionDetails,
                    });

                    if (response.status === 200) {
                        setDataRef.current(prevWorks =>
                            prevWorks.map(w =>
                                w.id === work.id ? { ...w, inspection_details: inspectionDetails } : w
                            )
                        );
                        resolve('Inspection details updated successfully');
                    }
                } catch (error) {
                    console.error('Error updating inspection details:', error.response?.data || error.message || error);
                    reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update inspection details');
                }
            });

            showToast.promise(promise, {
                loading: 'Updating inspection details...',
                success: (msg) => msg,
                error: (msg) => msg,
            });
        }, 800), // 0.8 second delay for text inputs
        []
    );

    // Debounced function for updating RFI submission time
    const debouncedUpdateSubmissionTime = useMemo(
        () => debounce(async (workId, submissionTime) => {
            const work = allDataRef.current?.find(w => w.id === workId);
            if (!work) {
                console.error('Work not found for ID:', workId, 'Available works:', allDataRef.current?.length);
                showToast.error('Unable to find work record. Please refresh and try again.');
                return;
            }

            // Check if work has active objections - if so, show warning modal
            if (work.active_objections_count > 0) {
                // Fetch active objections for the warning modal
                try {
                    const objResponse = await axios.get(route('dailyWorks.objections.index', work.id));
                    
                    setObjectionWarningModal({
                        isOpen: true,
                        dailyWork: work,
                        newSubmissionDate: submissionTime,
                        activeObjectionsCount: work.active_objections_count,
                        activeObjections: objResponse.data?.objections?.filter(obj => 
                            ['draft', 'submitted', 'under_review'].includes(obj.status)
                        ) || [],
                        isLoading: false,
                    });
                } catch (error) {
                    console.error('Error fetching objections:', error);
                    // If fetch fails, still show the modal with count only
                    setObjectionWarningModal({
                        isOpen: true,
                        dailyWork: work,
                        newSubmissionDate: submissionTime,
                        activeObjectionsCount: work.active_objections_count,
                        activeObjections: [],
                        isLoading: false,
                    });
                }
                return;
            }

            const promise = new Promise(async (resolve, reject) => {
                try {
                    const response = await axios.post(route('dailyWorks.updateSubmissionTime'), {
                        id: work.id,
                        rfi_submission_date: submissionTime,
                    });

                    if (response.status === 200) {
                        setDataRef.current(prevWorks =>
                            prevWorks.map(w =>
                                w.id === work.id ? response.data.dailyWork : w
                            )
                        );
                        resolve('RFI submission time updated successfully');
                    }
                } catch (error) {
                    console.error('Error updating RFI submission time:', error.response?.data || error.message || error);
                    reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update RFI submission time');
                }
            });

            showToast.promise(promise, {
                loading: 'Updating RFI submission time...',
                success: (msg) => msg,
                error: (msg) => msg,
            });
        }, 800), // 0.8 second delay for time inputs
        []
    );

    // Function to handle submission time update with override (after warning modal confirmation)
    const handleSubmissionTimeOverride = useCallback(async (workId, newSubmissionDate, overrideReason) => {
        const work = allDataRef.current?.find(w => w.id === workId);
        if (!work) {
            showToast.error('Work not found');
            return;
        }

        setObjectionWarningModal(prev => ({ ...prev, isLoading: true }));

        const promise = new Promise(async (resolve, reject) => {
            try {
                const response = await axios.post(route('dailyWorks.updateSubmissionTime'), {
                    id: work.id,
                    rfi_submission_date: newSubmissionDate,
                    override_confirmed: true,
                    override_reason: overrideReason,
                });

                if (response.status === 200) {
                    setDataRef.current(prevWorks =>
                        prevWorks.map(w =>
                            w.id === work.id ? response.data.dailyWork : w
                        )
                    );
                    setObjectionWarningModal({
                        isOpen: false,
                        dailyWork: null,
                        newSubmissionDate: null,
                        activeObjectionsCount: 0,
                        activeObjections: [],
                        isLoading: false,
                    });
                    resolve('RFI submission time updated successfully');
                }
            } catch (error) {
                console.error('Error updating RFI submission time:', error.response?.data || error.message || error);
                setObjectionWarningModal(prev => ({ ...prev, isLoading: false }));
                reject(error.response?.data?.error || error.response?.data?.message || 'Failed to update RFI submission time');
            }
        });

        showToast.promise(promise, {
            loading: 'Updating RFI submission time...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    }, []);

    // Function to close warning modal
    const closeObjectionWarningModal = useCallback(() => {
        setObjectionWarningModal({
            isOpen: false,
            dailyWork: null,
            newSubmissionDate: null,
            activeObjectionsCount: 0,
            activeObjections: [],
            isLoading: false,
        });
    }, []);

    // Cleanup debounced functions on unmount
    useEffect(() => {
        return () => {
         
            debouncedUpdateIncharge.cancel();
            debouncedUpdateAssigned.cancel();
            debouncedUpdateCompletionTime.cancel();
            debouncedUpdateInspectionDetails.cancel();
            debouncedUpdateSubmissionTime.cancel();
        };
    }, [
   
        debouncedUpdateIncharge,
        debouncedUpdateAssigned,
        debouncedUpdateCompletionTime,
        debouncedUpdateInspectionDetails,
        debouncedUpdateSubmissionTime
    ]);

    // Handle general field updates
    const handleChange = async (taskId, taskNumber, key, value, type) => {
        // Find the current work to get all its data
        const currentWork = allData?.find(work => work.id === taskId);
        if (!currentWork) {
            showToast.error('Work not found');
            return;
        }

        const promise = new Promise(async (resolve, reject) => {
            try {
                // Prepare update data with logical field assignments
                const updateData = {
                    id: taskId,
                    [key]: value,
                    // Include required fields with standardized fallbacks
                    date: currentWork.date || new Date().toISOString().split('T')[0],
                    number: currentWork.number || `RFI-${Date.now()}`,
                    planned_time: currentWork.planned_time || '09:00',
                    status: key === 'status' ? value : (currentWork.status || 'new'),
                    type: currentWork.type || 'Standard',
                    description: currentWork.description || 'Work description pending',
                    location: currentWork.location || 'Location to be determined',
                    side: currentWork.side || 'Both'
                };

                // Logical field assignments
                if (key === 'status') {
                    if (value === 'completed') {
                        // Auto-set completion time and submission time if not already set
                        updateData.completion_time = currentWork.completion_time || new Date().toISOString();
                        updateData.submission_time = currentWork.submission_time || new Date().toISOString();
                        
                        // Capture document if not structure type
                        if (!(type === 'Structure')) {
                            const pdfFile = await captureDocument(taskNumber);
                            if (pdfFile) {
                                await uploadImage(taskId, pdfFile);
                            }
                        }
                    } else if (value === 'resubmission') {
                        // Increment resubmission count
                        updateData.resubmission_count = (currentWork.resubmission_count || 0) + 1;
                    } else if (value === 'new') {
                        // Reset completion and submission times for new status
                        updateData.completion_time = null;
                        updateData.submission_time = null;
                    }
                }

                const response = await axios.post(route('dailyWorks.update'), updateData);

                if (response.status === 200) {
                    // Update local state with the response data
                    setData(prevTasks =>
                        prevTasks.map(task =>
                            task.id === taskId ? { 
                                ...task, 
                                [key]: value,
                                // Update logical fields based on status change
                                ...(key === 'status' && value === 'completed' && {
                                    completion_time: updateData.completion_time,
                                    submission_time: updateData.submission_time
                                }),
                                ...(key === 'status' && value === 'resubmission' && {
                                    resubmission_count: updateData.resubmission_count
                                }),
                                ...(key === 'status' && value === 'new' && {
                                    completion_time: null,
                                    submission_time: null
                                })
                            } : task
                        )
                    );

                    resolve(response.data.message || 'Task updated successfully');
                }
            } catch (error) {
                console.error(error);
                let errorMessage = 'An unexpected error occurred.';
                
                if (error.response?.status === 422 && error.response.data?.errors) {
                    // Handle validation errors
                    const errors = error.response.data.errors;
                    const errorMessages = Object.values(errors).flat();
                    errorMessage = errorMessages.join(', ');
                } else if (error.response?.data?.message) {
                    errorMessage = error.response.data.message;
                } else if (error.response?.statusText) {
                    errorMessage = error.response.statusText;
                }

                reject(errorMessage);
            }
        });

        showToast.promise(promise, {
            loading: 'Updating task...',
            success: (msg) => msg,
            error: (msg) => msg,
        });
    };

const MobileDailyWorkCard = ({ works, selectedTab, setSelectedTab, expandedItems, toggleExpanded, openStatusModal, openRfiFilesModal, openObjectionsModal }) => {

    // Group works by type
    const groupedWorks = useMemo(() => {
        const groups = {
            structure: [],
            embankment: [],
            pavement: []
        };

        works.forEach(work => {
            const workType = work.type?.toLowerCase() || 'structure';
            if (groups[workType]) {
                groups[workType].push(work);
            } else {
                groups.structure.push(work); // Default to structure if type doesn't match
            }
        });

        return groups;
    }, [works]);

    // Individual work accordion item component
const WorkAccordionItem = ({ work, index, isExpanded, onToggle, openStatusModal, openRfiFilesModal, openObjectionsModal }) => {
    const inchargeUser = getUserInfo(work.incharge);
    const assignedUser = getUserInfo(work.assigned);
    const statusKey = getStatusKey(work.status, work.inspection_result);
    const statusConf = statusConfig[statusKey] || statusConfig['new'];

    // Handle keyboard navigation
    const handleKeyDown = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            onToggle();
        }
    };

    // Compact info row component - Fixed truncation and layout
    const InfoRow = ({ label, value, chip, chipColor = 'gray', highlight = false }) => (
        <Flex align="center" justify="between" py="1" style={{ borderBottom: '1px solid var(--gray-a4)', gap: '12px' }}>
            {/* Label stays fixed */}
            <Text size="1" color="gray" style={{ flexShrink: 0 }}>{label}</Text>
            
            {/* Value truncates safely without pushing the width out */}
            {chip ? (
                <Badge size="1" variant="soft" color={chipColor} style={{ flexShrink: 0, maxWidth: '65%' }}>
                    <Text truncate>{highlight ? <HighlightedText text={value} searchTerm={searchTerm} /> : value}</Text>
                </Badge>
            ) : (
                <Text size="1" weight="medium" truncate align="right" style={{ flex: 1, minWidth: 0 }}>
                    {highlight ? <HighlightedText text={value} searchTerm={searchTerm} /> : value}
                </Text>
            )}
        </Flex>
    );

    return (
        <Card 
            size="1"
            variant="surface"
            style={{
                width: '100%',
                overflow: 'hidden', // Prevents internal content from breaking the card boundaries
                ...(work.active_objections_count > 0 && {
                    boxShadow: 'inset 0 0 0 1px var(--orange-6)',
                    backgroundColor: 'var(--orange-2)'
                })
            }}
            role="article"
            aria-label={`Daily work ${work.number}`}
        >
            {/* Card Header (Clickable area to expand) */}
            <Box
                onClick={onToggle}
                onKeyDown={handleKeyDown}
                tabIndex={0}
                role="button"
                aria-expanded={isExpanded}
                style={{ cursor: 'pointer', userSelect: 'none', width: '100%' }}
            >
                <Flex align="center" justify="between" gap="2" width="100%">
                    
                    {/* Left Section: Icons + Title (Allowed to shrink and truncate) */}
                    <Flex align="center" gap="2" style={{ flex: 1, minWidth: 0, overflow: 'hidden' }}>
                        {/* Explicit icon dimensions and flexShrink: 0 prevent icon squeezing */}
                        {work.rfi_files_count > 0 && (
                            <CheckIcon width="16" height="16" color="var(--green-9)" style={{ flexShrink: 0 }} />
                        )}
                        {work.active_objections_count > 0 && (
                            <Box
                                onClick={(e) => { e.stopPropagation(); openObjectionsModal(work); }}
                                title={`${work.active_objections_count} active objection(s)`}
                                style={{ flexShrink: 0, display: 'flex' }}
                            >
                                <ExclamationTriangleIcon width="16" height="16" color="var(--orange-9)" />
                            </Box>
                        )}
                        
                        {/* The truncate prop safely adds ellipsis to long text instead of overflowing */}
                        <Text size="2" weight="bold" truncate style={{ flex: 1, minWidth: 0 }}>
                            <HighlightedText text={work.number} searchTerm={searchTerm} />
                        </Text>

                        {/* Counts */}
                        {work.rfi_files_count > 0 && <Text size="1" color="green" style={{ flexShrink: 0 }}>({work.rfi_files_count})</Text>}
                        {work.active_objections_count > 0 && <Text size="1" color="orange" style={{ flexShrink: 0 }}>⚠{work.active_objections_count}</Text>}
                    </Flex>

                    {/* Right Section: Status Badge + Chevron (Never shrinks) */}
                    <Flex align="center" gap="2" style={{ flexShrink: 0 }}>
                        <Badge size="1" variant="soft" color={statusConf.color}>
                            {statusConf.label.split(':')[0]}
                        </Badge>
                        <ChevronDownIcon 
                            color="var(--gray-9)" 
                            style={{ 
                                transition: 'transform 200ms', 
                                transform: isExpanded ? 'rotate(180deg)' : 'rotate(0deg)' 
                            }} 
                        />
                    </Flex>
                </Flex>
            </Box>

            {/* Expanded Details Content */}
            {isExpanded && (
                <Box pt="3" onClick={(e) => e.stopPropagation()} style={{ width: '100%' }}>
                    <Separator size="4" mb="3" />
                    
                    {/* Summary Data Box */}
                    <Box p="2" mb="3" style={{ backgroundColor: 'var(--gray-a3)', borderRadius: 'var(--radius-2)', width: '100%', overflow: 'hidden' }}>
                        <InfoRow label="Date" value={formatDate(work.date)} highlight />
                        {work.description && <InfoRow label="Description" value={work.description} highlight />}
                        <InfoRow label="Location" value={work.location || 'Not set'} highlight />
                        <InfoRow label="Side" value={work.side || 'Both'} chip highlight />
                        {work.qty_layer && <InfoRow label="Qty/Layer" value={work.qty_layer} highlight />}
                        <InfoRow label="Planned" value={work.planned_time || 'Not set'} highlight />
                        {work.resubmission_count > 0 && <InfoRow label="Resubmissions" value={work.resubmission_count} chip chipColor="orange" />}
                    </Box>

                    {/* Interactive Form Controls */}
                    <Flex direction="column" gap="2" mb="3" width="100%">
                        {shouldShowInchargeColumn && (
                            <Flex align="center" gap="2" width="100%">
                                <Text size="1" color="gray" style={{ width: '60px', flexShrink: 0 }}>In-charge:</Text>
                                <Box style={{ flex: 1, minWidth: 0 }}>
                                    <Select.Root 
                                        size="1" 
                                        value={work.incharge ? String(work.incharge) : ''}
                                        onValueChange={(val) => { if (val) debouncedUpdateIncharge(work.id, val); }}
                                    >
                                        <Select.Trigger style={{ width: '100%' }} placeholder="Select" />
                                        <Select.Content>
                                            {finalInCharges.map((u) => <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>)}
                                        </Select.Content>
                                    </Select.Root>
                                </Box>
                            </Flex>
                        )}

                        {shouldShowAssignedColumn && (
                            <Flex align="center" gap="2" width="100%">
                                <Text size="1" color="gray" style={{ width: '60px', flexShrink: 0 }}>Assigned:</Text>
                                <Box style={{ flex: 1, minWidth: 0 }}>
                                    {canUserAssign(work) ? (
                                        <Select.Root 
                                            size="1" 
                                            value={work.assigned ? String(work.assigned) : ''}
                                            onValueChange={(val) => { if (val) debouncedUpdateAssigned(work.id, val); }}
                                        >
                                            <Select.Trigger style={{ width: '100%' }} placeholder="Select" />
                                            <Select.Content>
                                                {getAvailableAssignees(work.incharge).map((u) => <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>)}
                                            </Select.Content>
                                        </Select.Root>
                                    ) : (
                                        <Text size="1" truncate>{assignedUser.name || 'Unassigned'}</Text>
                                    )}
                                </Box>
                            </Flex>
                        )}

                        {canUserUpdateStatus(work) && (
                            <Flex align="center" gap="2" width="100%">
                                <Text size="1" color="gray" style={{ width: '60px', flexShrink: 0 }}>Status:</Text>
                                <Box style={{ flex: 1, minWidth: 0 }}>
                                    <Select.Root 
                                        size="1" 
                                        value={statusKey}
                                        onValueChange={(val) => { if (val && val !== statusKey) updateWorkStatus(work, val); }}
                                    >
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {Object.entries(statusConfig).map(([k, c]) => <Select.Item key={k} value={k}>{c.label}</Select.Item>)}
                                        </Select.Content>
                                    </Select.Root>
                                </Box>
                            </Flex>
                        )}

                        {canUserUpdateInspectionDetails(work) && (
                            <Flex direction="column" gap="1" width="100%">
                                <Text size="1" color="gray">Inspection Details:</Text>
                                <TextField.Root 
                                    size="1"
                                    placeholder="Enter details..."
                                    defaultValue={work.inspection_details || ''}
                                    onChange={(e) => debouncedUpdateInspectionDetails(work.id, e.target.value)}
                                    style={{ width: '100%' }}
                                />
                            </Flex>
                        )}

                        {canUserUpdateCompletionTime(work) && (
                            <Flex direction="column" gap="1" width="100%">
                                <Text size="1" color="gray">Completion:</Text>
                                <TextField.Root 
                                    size="1"
                                    type="datetime-local"
                                    defaultValue={work.completion_time ? new Date(work.completion_time).toLocaleString('sv-SE').replace(' ', 'T').slice(0, 16) : ''}
                                    onChange={(e) => debouncedUpdateCompletionTime(work.id, e.target.value)}
                                    style={{ width: '100%' }}
                                />
                            </Flex>
                        )}

                        {shouldShowRfiColumn && (
                            <Flex direction="column" gap="1" width="100%">
                                <Text size="1" color="gray">RFI Date:</Text>
                                <TextField.Root 
                                    size="1"
                                    type="date"
                                    defaultValue={work.rfi_submission_date ? new Date(work.rfi_submission_date).toISOString().slice(0, 10) : ''}
                                    onChange={(e) => debouncedUpdateSubmissionTime(work.id, e.target.value)}
                                    style={{ width: '100%' }}
                                />
                            </Flex>
                        )}
                    </Flex>

                    <Separator size="4" my="3" />

                    {/* Action Buttons */}
                    <Flex gap="2" width="100%">
                        <Button 
                            size="1" 
                            variant="soft" 
                            color={work.rfi_files_count > 0 ? 'green' : 'gray'} 
                            style={{ flex: 1 }} 
                            onClick={() => openRfiFilesModal(work)}
                        >
                            <Text truncate>Files{work.rfi_files_count > 0 ? ` (${work.rfi_files_count})` : ''}</Text>
                        </Button>
                        
                        <Button 
                            size="1" 
                            variant="soft" 
                            color={work.active_objections_count > 0 ? 'orange' : 'gray'} 
                            style={{ flex: 1 }} 
                            onClick={() => openObjectionsModal(work)}
                        >
                            <Text truncate>Objections{work.active_objections_count > 0 ? ` (${work.active_objections_count})` : ''}</Text>
                        </Button>
                        
                        {shouldShowActions && (
                            <>
                                <Button size="1" variant="soft" color="indigo" style={{ flex: 1 }} onClick={() => { setCurrentRow(work); openModal('editDailyWork'); }}>Edit</Button>
                                <Button size="1" variant="soft" color="red" style={{ flex: 1 }} onClick={() => handleClickOpen(work.id, 'deleteDailyWork')}>Delete</Button>
                            </>
                        )}
                    </Flex>
                </Box>
            )}
        </Card>
    );
};

    return (
        <Box width="100%">
            <Tabs.Root value={selectedTab} onValueChange={setSelectedTab}>
                {/* ScrollArea allows tabs to swipe horizontally if they overflow */}
                <ScrollArea type="auto" scrollbars="horizontal">
                    <Tabs.List size="2" style={{ width: 'max-content', whiteSpace: 'nowrap' }}>
                        {workTypes.map((type) => (
                            <Tabs.Trigger key={type.key} value={type.key}>
                                <Flex align="center" gap="2">
                                    <Box style={{ display: 'flex' }}>{type.icon}</Box>
                                    <Text size="2" weight="medium">{type.label}</Text>
                                    <Badge 
                                        size="1" 
                                        radius="full" 
                                        variant={selectedTab === type.key ? 'solid' : 'soft'}
                                        color={selectedTab === type.key ? 'indigo' : 'gray'}
                                    >
                                        {groupedWorks[type.key]?.length || 0}
                                    </Badge>
                                </Flex>
                            </Tabs.Trigger>
                        ))}
                    </Tabs.List>
                </ScrollArea>

                <Box pt="4">
                    {workTypes.map((type) => (
                        <Tabs.Content key={type.key} value={type.key}>
                            {groupedWorks[type.key]?.length > 0 ? (
                                <Flex direction="column" gap="3">
                                    {groupedWorks[type.key].map((work, index) => (
                                         <WorkAccordionItem 
                                                work={work} 
                                                index={index}
                                                isExpanded={expandedItems.has(work.id)}
                                                onToggle={() => toggleExpanded(work.id)}
                                                openStatusModal={openStatusModal}
                                                openRfiFilesModal={openRfiFilesModal}
                                                openObjectionsModal={openObjectionsModal}
                                            />
                                       
                                    ))}
                                </Flex>
                            ) : (
                                <Flex direction="column" align="center" justify="center" py="8" gap="3">
                                    <Text size="8" color="gray" style={{ opacity: 0.5 }}>{type.icon}</Text>
                                    <Text size="3" color="gray" weight="medium">
                                        No {type.label.toLowerCase()} works found
                                    </Text>
                                </Flex>
                            )}
                        </Tabs.Content>
                    ))}
                </Box>
            </Tabs.Root>
        </Box>
    );
};
    const handlePageChange = useCallback((page) => {
        if (onPageChange) {
            onPageChange(page);
        }
    }, [onPageChange]);

    const cellBaseClasses = "text-xs sm:text-sm md:text-base whitespace-nowrap";

const renderCell = useCallback((work, columnKey) => {
    const inchargeUser = getUserInfo(work.incharge);
    const assignedUser = getUserInfo(work.assigned);

    // Apply this to prevent wrapping on standard cells
    const autoFitStyle = { whiteSpace: 'nowrap', width: 'auto' };

    switch (columnKey) {
        case "date":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="2">
                        <CalendarIcon color="var(--gray-9)" />
                        <Text size="2" weight="medium">
                            <HighlightedText text={formatDate(work.date)} searchTerm={searchTerm} />
                        </Text>
                    </Flex>
                </RadixTable.Cell>
            );

        case "number":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="1">
                        <IconButton 
                            size="1" 
                            variant="ghost" 
                            color="gray" 
                            disabled={work.rfi_files_count === 0}
                            onClick={() => openRfiFilesModal(work)}
                        >
                            <EyeOpenIcon />
                        </IconButton>
                        
                        {(work.status === 'completed' || work.status?.startsWith('completed:')) && work.file ? (
                            <Link href={work.file} target="_blank" rel="noopener noreferrer" size="2" weight="medium">
                                <HighlightedText text={work.number} searchTerm={searchTerm} />
                            </Link>
                        ) : (
                            <Text size="2" weight="medium" color="indigo">
                                <HighlightedText text={work.number} searchTerm={searchTerm} />
                            </Text>
                        )}
                        
                        {work.rfi_files_count > 0 && <CheckIcon color="var(--green-9)" />}
                        {work.active_objections_count > 0 && (
                            <IconButton size="1" variant="ghost" color="orange" onClick={() => openObjectionsModal(work)}>
                                <ExclamationTriangleIcon />
                            </IconButton>
                        )}
                    </Flex>
                </RadixTable.Cell>
            );

        case "status":
            const statusKey = getStatusKey(work.status, work.inspection_result);
            const currentStatusConfig = statusConfig[statusKey] || statusConfig['new'];
            const StatusIconComponent = currentStatusConfig.icon;
            
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="2">
                        <StatusIconComponent style={{ color: `var(--${currentStatusConfig.color}-9)` }} />
                        <Select.Root 
                            size="1" 
                            value={statusKey} 
                            onValueChange={(value) => { 
                                if (value && value !== statusKey) updateWorkStatus(work, value); 
                            }}
                        >
                            <Select.Trigger />
                            <Select.Content>
                                {Object.entries(statusConfig).map(([k, c]) => (
                                    <Select.Item key={k} value={k}>{c.label}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Flex>
                </RadixTable.Cell>
            );

        case "type":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="2">
                        {getWorkTypeIcon(work.type, "w-4 h-4")}
                        <Text size="2" weight="medium" style={{ textTransform: 'capitalize' }}>
                            <HighlightedText text={work.type || 'Standard Work'} searchTerm={searchTerm} />
                        </Text>
                    </Flex>
                </RadixTable.Cell>
            );

        case "description":
            return (
                <RadixTable.Cell style={{ maxWidth: '300px', width: '300px' }}>
                    <Tooltip content={work.description || "No description provided"}>
                        <Text 
                            size="2" 
                            color="gray" 
                            style={{ 
                                display: 'block', 
                                whiteSpace: 'nowrap', 
                                overflow: 'hidden', 
                                textOverflow: 'ellipsis' 
                            }}
                        >
                            <HighlightedText text={work.description || "No description provided"} searchTerm={searchTerm} />
                        </Text>
                    </Tooltip>
                </RadixTable.Cell>
            );

        case "location":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="2">
                        <HomeIcon color="var(--gray-9)" />
                        <Text size="2" weight="medium">
                            <HighlightedText text={work.location || 'Not specified'} searchTerm={searchTerm} />
                        </Text>
                    </Flex>
                </RadixTable.Cell>
            );

        case "side":
        case "qty_layer":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center">
                        <Text size="2">
                            <HighlightedText text={work[columnKey] || (columnKey === 'side' ? 'Both Sides' : 'N/A')} searchTerm={searchTerm} />
                        </Text>
                    </Flex>
                </RadixTable.Cell>
            );

        case "planned_time":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="1">
                        <ClockIcon color="var(--gray-9)" />
                        <Text size="2">
                            <HighlightedText text={work.planned_time || 'Not set'} searchTerm={searchTerm} />
                        </Text>
                    </Flex>
                </RadixTable.Cell>
            );

        case "resubmission_count":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center">
                        <Badge size="1" variant="soft" color={work.resubmission_count > 0 ? 'orange' : 'gray'}>
                            {work.resubmission_count || 0}
                        </Badge>
                    </Flex>
                </RadixTable.Cell>
            );

        case "incharge":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center">
                        {userIsAdmin ? (
                            <Select.Root 
                                size="1" 
                                value={work.incharge && finalInCharges.find(u => u.id === parseInt(work.incharge)) ? String(work.incharge) : ''}
                                onValueChange={(value) => { if (value) debouncedUpdateIncharge(work.id, value); }}
                            >
                                <Select.Trigger placeholder="Select in-charge" />
                                <Select.Content>
                                    {finalInCharges?.map((u) => (
                                        <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                        ) : (
                            inchargeUser.name !== 'Unassigned' ? (
                                <Flex align="center" gap="2">
                                    <Avatar size="1" src={inchargeUser.profile_image_url || inchargeUser.profile_image} fallback={inchargeUser.name.charAt(0)} radius="full" />
                                    <Text size="1" weight="medium">{inchargeUser.name}</Text>
                                </Flex>
                            ) : (
                                <Text size="2" color="gray">Unassigned</Text>
                            )
                        )}
                    </Flex>
                </RadixTable.Cell>
            );

        case "assigned":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center">
                        <Select.Root 
                            size="1" 
                            value={work.assigned && getAvailableAssignees(work.incharge).find(u => u.id === parseInt(work.assigned)) ? String(work.assigned) : ''}
                            onValueChange={(value) => { if (value) debouncedUpdateAssigned(work.id, value); }}
                        >
                            <Select.Trigger placeholder="Select assignee" />
                            <Select.Content>
                                {getAvailableAssignees(work.incharge)?.map((u) => (
                                    <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Flex>
                </RadixTable.Cell>
            );

        case "inspection_details":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center">
                        <TextField.Root 
                            size="1" 
                            placeholder="Enter details..." 
                            defaultValue={work.inspection_details || ''}
                            onChange={(e) => debouncedUpdateInspectionDetails(work.id, e.target.value)}
                        />
                    </Flex>
                </RadixTable.Cell>
            );

        case "completion_time":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center">
                        <TextField.Root 
                            type="datetime-local" 
                            size="1" 
                            defaultValue={work.completion_time ? new Date(work.completion_time).toLocaleString('sv-SE').replace(' ', 'T').slice(0, 16) : ''}
                            onChange={(e) => debouncedUpdateCompletionTime(work.id, e.target.value)}
                        />
                    </Flex>
                </RadixTable.Cell>
            );

        case "rfi_submission_date":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    {userIsAdmin ? (
                        <Flex align="center" justify="center">
                            <TextField.Root 
                                type="date" 
                                size="1" 
                                defaultValue={work.rfi_submission_date ? new Date(work.rfi_submission_date).toISOString().slice(0, 10) : ''}
                                onChange={(e) => debouncedUpdateSubmissionTime(work.id, e.target.value)}
                            />
                        </Flex>
                    ) : (
                        <Flex align="center" justify="center" gap="1">
                            <CalendarIcon color="var(--gray-9)" />
                            <Text size="2">
                                {work.rfi_submission_date ? formatDate(work.rfi_submission_date) : 'Not set'}
                            </Text>
                        </Flex>
                    )}
                </RadixTable.Cell>
            );

        case "rfi_response_status": {
            const responseStatusConfig = {
                approved: { label: 'Approved', color: 'green' },
                rejected: { label: 'Rejected', color: 'red' },
                returned: { label: 'Returned', color: 'orange' },
                concurred: { label: 'Concurred', color: 'blue' },
                not_concurred: { label: 'Not Concurred', color: 'purple' },
            };
            const statusCfg = responseStatusConfig[work.rfi_response_status] || null;
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="2">
                        {work.rfi_response_status ? (
                            <>
                                <Badge size="1" variant="soft" color={statusCfg?.color || 'gray'}>
                                    {statusCfg?.label || work.rfi_response_status}
                                </Badge>
                                {work.rfi_response_date && (
                                    <Text size="1" color="gray">
                                        {formatDate(work.rfi_response_date)}
                                    </Text>
                                )}
                            </>
                        ) : (
                            <Text size="2" color="gray">-</Text>
                        )}
                    </Flex>
                </RadixTable.Cell>
            );
        }

        case "actions":
            return (
                <RadixTable.Cell style={autoFitStyle}>
                    <Flex align="center" justify="center" gap="1">
                        <Tooltip content={work.rfi_files_count > 0 ? `Files (${work.rfi_files_count})` : 'Manage Files'}>
                            <IconButton size="1" variant="ghost" color={work.rfi_files_count > 0 ? 'green' : 'gray'} onClick={() => openRfiFilesModal(work)}>
                                {work.rfi_files_count > 0 ? <CheckIcon /> : <OpenInNewWindowIcon />}
                            </IconButton>
                        </Tooltip>
                        
                        <Tooltip content={work.active_objections_count > 0 ? `Objections (${work.active_objections_count})` : 'View/Add Objections'}>
                            <IconButton size="1" variant="ghost" color={work.active_objections_count > 0 ? 'orange' : 'gray'} onClick={() => openObjectionsModal(work)}>
                                <ExclamationTriangleIcon style={work.active_objections_count > 0 ? { color: 'var(--orange-9)' } : undefined} />
                            </IconButton>
                        </Tooltip>
                        
                        <Tooltip content="Edit Work">
                            <IconButton size="1" variant="ghost" color="indigo" onClick={() => { if (updatingWorkId === work.id) return; setCurrentRow(work); openModal('editDailyWork'); }}>
                                <Pencil1Icon />
                            </IconButton>
                        </Tooltip>
                        
                        <Tooltip content="Delete Work">
                            <IconButton size="1" variant="ghost" color="red" onClick={() => { if (updatingWorkId === work.id) return; setCurrentRow(work); handleClickOpen(work.id, 'deleteDailyWork'); }}>
                                <TrashIcon />
                            </IconButton>
                        </Tooltip>
                    </Flex>
                </RadixTable.Cell>
            );

        default:
            return <RadixTable.Cell style={autoFitStyle}><Text size="2">{work[columnKey]}</Text></RadixTable.Cell>;
    }
}, [userIsAdmin, userIsSE, updatingWorkId, setCurrentRow, openModal, handleClickOpen, handleChange]);

  // Columns ordered to match mobile card layout:
    // Section 1: Info fields (read-only) - Date, Description, Location, Side, Qty/Layer, Planned Time, Resubmissions
    // Section 2: Input fields - In-charge, Assigned, Status, Inspection Details, Completion Time, RFI Date, Actions
    const columns = [
        // Info fields first
        { name: "Date", uid: "date", icon: CalendarIcon, sortable: true },
        { name: "RFI Number", uid: "number", icon: FileIcon, sortable: true },
        { name: "Work Type", uid: "type", icon: FileTextIcon, sortable: true },
        { name: "Description", uid: "description", icon: FileTextIcon, sortable: false },
        { name: "Location", uid: "location", icon: HomeIcon, sortable: true },
        { name: "Road Side", uid: "side", sortable: true },
        { name: "Layer Quantity", uid: "qty_layer", sortable: true },
        { name: "Planned Time", uid: "planned_time", icon: ClockIcon, sortable: true },
        { name: "Resubmissions", uid: "resubmission_count", icon: ReloadIcon, sortable: true },
        // Input fields last (matching mobile card order)
        ...(shouldShowInchargeColumn ? [{ name: "In-Charge", uid: "incharge", icon: PersonIcon, sortable: true }] : []),
        ...(shouldShowAssignedColumn ? [{ name: "Assigned To", uid: "assigned", icon: PersonIcon, sortable: true }] : []),
        { name: "Status", uid: "status", icon: ClockIcon, sortable: true },
        { name: "Inspection Details", uid: "inspection_details", icon: FileTextIcon, sortable: false },
        { name: "Completion Time", uid: "completion_time", icon: CheckCircledIcon, sortable: true },
        ...(shouldShowRfiColumn ? [{ name: "RFI Submission Date", uid: "rfi_submission_date", icon: CalendarIcon, sortable: true }] : []),
        ...(shouldShowRfiColumn ? [{ name: "RFI Response", uid: "rfi_response_status", icon: ClipboardIcon, sortable: true }] : []),
        ...(shouldShowActions ? [{ name: "Actions", uid: "actions", sortable: false }] : [])
    ];

    const MobileLoadingSkeleton = () => (
    <Box width="100%">
        {/* Tab Headers - Hooked into your existing state */}
        <Tabs.Root value={selectedTab} onValueChange={setSelectedTab}>
            {/* ScrollArea allows the tabs to swipe horizontally if they overflow */}
            <ScrollArea type="auto" scrollbars="horizontal">
                <Tabs.List size="2">
                    {workTypes.map((type) => (
                        <Tabs.Trigger key={type.key} value={type.key}>
                            <Flex align="center" gap="2">
                                {/* Wrap icon if it's raw JSX */}
                                <Box style={{ display: 'flex' }}>{type.icon}</Box>
                                <Text size="1" weight="medium">{type.label}</Text>
                                <Badge 
                                    size="1" 
                                    radius="full" 
                                    variant={selectedTab === type.key ? 'solid' : 'soft'}
                                    color={selectedTab === type.key ? 'indigo' : 'gray'}
                                >
                                    - {/* Placeholder counter */}
                                </Badge>
                            </Flex>
                        </Tabs.Trigger>
                    ))}
                </Tabs.List>
            </ScrollArea>
        </Tabs.Root>

        {/* Skeleton rows in tab content */}
        <Flex direction="column" gap="2" pt="3" pb="2">
            {Array.from({ length: 5 }).map((_, index) => (
                <Card key={index} size="1" variant="surface">
                    <Flex align="center" gap="3">
                        {/* Main description line skeleton */}
                        <Skeleton style={{ flexGrow: 1, height: '14px' }} />
                        
                        {/* Pill/Status badge skeleton */}
                        <Skeleton style={{ width: '48px', height: '20px', borderRadius: 'var(--radius-full)' }} />
                        
                        {/* Avatar/Action icon skeleton */}
                        <Skeleton style={{ width: '20px', height: '20px', borderRadius: 'var(--radius-full)' }} />
                    </Flex>
                </Card>
            ))}
        </Flex>
    </Box>
);

    const DesktopRowSkeleton = () => (
    <>
        {Array.from({ length: 8 }).map((_, rowIndex) => (
            <RadixTable.Row key={rowIndex}>
                {columns.map((column, colIndex) => (
                    <RadixTable.Cell 
                        key={colIndex} 
                        style={{ 
                            verticalAlign: 'middle',
                            textAlign: column.uid === 'description' ? 'left' : 'center',
                            minWidth: column.uid === 'description' ? 240 : column.uid === 'location' ? 192 : column.uid === 'number' ? 128 : column.uid === 'status' ? 192 : 80,
                        }}
                    >
                        {column.uid === 'status' || column.uid === 'completion_time' || column.uid === 'rfi_submission_date' ? (
                            
                            /* Pill / Status Skeleton */
                            <Flex justify={column.uid === 'description' ? 'start' : 'center'}>
                                <Skeleton style={{ width: '100px', height: '26px', borderRadius: 'var(--radius-3)' }} />
                            </Flex>

                        ) : column.uid === 'description' ? (
                            
                            /* Multi-line Text Skeleton */
                            <Flex direction="column" gap="2" width="100%">
                                <Skeleton style={{ width: '100%', height: '12px' }} />
                                <Skeleton style={{ width: '75%', height: '12px' }} />
                            </Flex>

                        ) : column.uid === 'incharge' || column.uid === 'assigned' ? (
                            
                            /* Avatar + Name Skeleton */
                            <Flex align="center" gap="2" justify="center">
                                <Skeleton style={{ width: '28px', height: '28px', borderRadius: 'var(--radius-full)' }} />
                                <Skeleton style={{ width: '60px', height: '12px' }} />
                            </Flex>

                        ) : column.uid === 'actions' ? (
                            
                            /* Action Buttons Skeleton */
                            <Flex gap="2" justify="center">
                                <Skeleton style={{ width: '26px', height: '26px', borderRadius: 'var(--radius-2)' }} />
                                <Skeleton style={{ width: '26px', height: '26px', borderRadius: 'var(--radius-2)' }} />
                            </Flex>

                        ) : (
                            
                            /* Default Short Text Skeleton */
                            <Flex justify="center">
                                <Skeleton style={{ width: '56px', height: '12px' }} />
                            </Flex>

                        )}
                    </RadixTable.Cell>
                ))}
            </RadixTable.Row>
        ))}
    </>
);

const DesktopLoadingSkeleton = () => (
    <Box style={{ maxHeight: '84vh', display: 'flex', flexDirection: 'column' }}>
        {/* Header with Refresh Button - Always visible */}
        <Flex align="center" justify="between" mb="4" px="2">
            <Text size={{ initial: '3', md: '4' }} weight="bold" color="gray" as="h3">
                Daily Works
            </Text>
            <Button
                variant="soft"
                color="indigo"
                size="1"
                disabled
            >
                {/* Note: Radix themes doesn't have a built in spinner icon, so keeping inline animation is fine here */}
                <ReloadIcon style={{ animation: 'spin 1s linear infinite' }} /> 
                <Text display={{ initial: 'none', sm: 'inline' }}>Loading...</Text>
            </Button>
        </Flex>
        
        {/* Table Area */}
        <ScrollArea type="auto" scrollbars="both" style={{ flexGrow: 1, minHeight: '400px', maxHeight: '70vh' }}>
            <RadixTable.Root variant="surface" style={{ width: '100%', minWidth: '800px' }}>
                <RadixTable.Header>
                    <RadixTable.Row>
                        {columns.map((column) => {
                            const IconComponent = column.icon;
                            return (
                                <RadixTable.ColumnHeaderCell 
                                    key={column.uid} 
                                    style={{
                                        textAlign: column.uid === 'description' ? 'left' : 'center',
                                        minWidth: column.uid === 'description' ? 240 : column.uid === 'location' ? 192 : column.uid === 'number' ? 128 : column.uid === 'status' ? 192 : 80,
                                        background: 'var(--gray-a2)',
                                        whiteSpace: 'nowrap'
                                    }}
                                >
                                    <Flex align="center" gap="1" justify={column.uid === 'description' ? 'start' : 'center'}>
                                        {IconComponent && <IconComponent width="14" height="14" />}
                                        <Text size="1" weight="bold">{column.name}</Text>
                                    </Flex>
                                </RadixTable.ColumnHeaderCell>
                            );
                        })}
                    </RadixTable.Row>
                </RadixTable.Header>
                
                <RadixTable.Body>
                    {/* Ensure your DesktopRowSkeleton component returns <RadixTable.Row> and <RadixTable.Cell> elements */}
                    <DesktopRowSkeleton />
                </RadixTable.Body>
            </RadixTable.Root>
        </ScrollArea>
        
        {/* Pagination - Always visible */}
        <Flex justify="center" align="center" py="4" gap="3">
            <IconButton size="2" variant="surface" color="gray" disabled>
                <ChevronLeftIcon />
            </IconButton>
            <Text size="2" weight="medium" color="gray">
                Page {currentPage} of {lastPage || 1}
            </Text>
            <IconButton size="2" variant="surface" color="gray" disabled>
                <ChevronRightIcon />
            </IconButton>
        </Flex>
    </Box>
);
    // Show loading skeleton BEFORE checking isMobile
    if (loading) {
    return (
        <Box>
            {/* Mobile Skeleton: Visible only on small screens */}
            <Box display={{ initial: 'block', md: 'none' }}>
                <ScrollArea type="auto" style={{ maxHeight: 'calc(100vh - 280px)', minHeight: '300px' }}>
                    <MobileLoadingSkeleton />
                </ScrollArea>
            </Box>

            {/* Desktop Skeleton: Visible only on md screens and larger */}
            <Box display={{ initial: 'none', md: 'block' }}>
                <DesktopLoadingSkeleton />
            </Box>
        </Box>
    );
}

    if (isMobile) {
        return (
            <Flex direction="column" gap="3">
    {/* Mobile Header with Import Buttons - Icon Only for mobile */}
    <Flex align="center" justify="between" px="2">
        <Text size="2" weight="bold" color="gray" as="h3">
            Daily Works
        </Text>
        
        <Flex align="center" gap="2">
            <Tooltip content="Import Submission">
                <IconButton
                    variant="soft"
                    color="violet"
                    size="2"
                    onClick={() => setBulkImportModalOpen(true)}
                    aria-label="Import Submission"
                >
                    <DownloadIcon />
                </IconButton>
            </Tooltip>
            
            <Tooltip content="Import Status">
                <IconButton
                    variant="soft"
                    color="indigo"
                    size="2"
                    onClick={() => setBulkImportResponseStatusModalOpen(true)}
                    aria-label="Import Status"
                >
                    <ClipboardIcon />
                </IconButton>
            </Tooltip>
            
            <Tooltip content="Refresh">
                <IconButton
                    variant="soft"
                    color="gray"
                    size="2"
                    onClick={handleRefresh}
                    aria-label="Refresh"
                >
                    <ReloadIcon />
                </IconButton>
            </Tooltip>
        </Flex>
    </Flex>

    {/* Scrollable Card Area */}
    <ScrollArea type="auto" style={{ maxHeight: 'calc(100vh - 320px)', minHeight: '300px' }}>
        <MobileDailyWorkCard 
            works={allData || []} 
            selectedTab={selectedTab}
            setSelectedTab={setSelectedTab}
            expandedItems={expandedItems}
            toggleExpanded={toggleExpanded}
            openStatusModal={openStatusModal}
            openRfiFilesModal={openRfiFilesModal}
            openObjectionsModal={openObjectionsModal}
        />
    </ScrollArea>

    {/* Mobile pagination - show when there are multiple pages */}
    {lastPage > 1 && (
        <Flex justify="center" pt="2" pb="4">
            <Flex gap="3" align="center">
                <IconButton 
                    size="2" 
                    variant="surface" 
                    color="gray"
                    disabled={currentPage <= 1} 
                    onClick={() => handlePageChange(currentPage - 1)}
                >
                    <ChevronLeftIcon />
                </IconButton>
                <Text size="2" weight="medium" color="gray">
                    {currentPage} / {lastPage}
                </Text>
                <IconButton 
                    size="2" 
                    variant="surface" 
                    color="gray"
                    disabled={currentPage >= lastPage} 
                    onClick={() => handlePageChange(currentPage + 1)}
                >
                    <ChevronRightIcon />
                </IconButton>
            </Flex>
        </Flex>
    )}
    
    {/* --- Modals --- */}

    {/* RFI Files Modal */}
    <RfiFilesModal
        isOpen={rfiModalOpen}
        onClose={() => {
            setRfiModalOpen(false);
            setRfiModalWork(null);
        }}
        dailyWork={rfiModalWork}
        onFilesUpdated={handleRfiFilesUpdated}
    />
    
    {/* Status Update Modal */}
    <StatusUpdateModal
        open={statusModalOpen}
        closeModal={() => {
            setStatusModalOpen(false);
            setStatusModalWork(null);
        }}
        dailyWork={statusModalWork}
        onStatusUpdated={handleStatusUpdated}
    />
    
    {/* Objections Modal */}
    <ObjectionsModal
        isOpen={objectionsModalOpen}
        onClose={() => {
            setObjectionsModalOpen(false);
            setObjectionsModalWork(null);
        }}
        dailyWork={objectionsModalWork}
        onObjectionsUpdated={handleObjectionsUpdated}
    />
    
    {/* Objection Warning Modal - for submission date changes */}
    <ObjectionWarningModal
        isOpen={objectionWarningModal.isOpen}
        onClose={closeObjectionWarningModal}
        dailyWork={objectionWarningModal.dailyWork}
        newSubmissionDate={objectionWarningModal.newSubmissionDate}
        activeObjectionsCount={objectionWarningModal.activeObjectionsCount}
        activeObjections={objectionWarningModal.activeObjections}
        isLoading={objectionWarningModal.isLoading}
        onConfirm={handleSubmissionTimeOverride}
    />
    
    {/* Bulk Submit Modal */}
    <BulkSubmitModal
        isOpen={bulkSubmitModalOpen}
        onClose={() => setBulkSubmitModalOpen(false)}
        selectedWorks={selectedWorks}
        onSuccess={handleBulkSubmitSuccess}
    />
    
    {/* Bulk Import Submit Modal */}
    <BulkImportSubmitModal
        isOpen={bulkImportModalOpen}
        onClose={() => setBulkImportModalOpen(false)}
        onSuccess={handleBulkImportSuccess}
    />

    {/* Bulk Response Status Modal */}
    <BulkResponseStatusModal
        isOpen={bulkResponseStatusModalOpen}
        onClose={() => setBulkResponseStatusModalOpen(false)}
        selectedWorks={selectedWorks}
        onSuccess={handleBulkResponseStatusSuccess}
    />

    {/* Bulk Import Response Status Modal */}
    <BulkImportResponseStatusModal
        isOpen={bulkImportResponseStatusModalOpen}
        onClose={() => setBulkImportResponseStatusModalOpen(false)}
        onSuccess={handleBulkImportResponseStatusSuccess}
    />

    {/* Bulk Incharge Modal */}
    <BulkInchargeModal
        isOpen={bulkInchargeModalOpen}
        onClose={() => setBulkInchargeModalOpen(false)}
        selectedWorks={selectedWorks}
        incharges={allInCharges}
        onSuccess={handleBulkInchargeSuccess}
    />

    {/* Bulk Status Modal */}
    <BulkStatusModal
        isOpen={bulkStatusModalOpen}
        onClose={() => setBulkStatusModalOpen(false)}
        selectedWorks={selectedWorks}
        onSuccess={handleBulkStatusSuccess}
    />

    {/* Bulk Completion Date Modal */}
    <BulkCompletionDateModal
        isOpen={bulkCompletionDateModalOpen}
        onClose={() => setBulkCompletionDateModalOpen(false)}
        selectedWorks={selectedWorks}
        onSuccess={handleBulkCompletionDateSuccess}
    />

    {/* Bulk Delete Modal */}
    <BulkDeleteModal
        isOpen={bulkDeleteModalOpen}
        onClose={() => setBulkDeleteModalOpen(false)}
        selectedWorks={selectedWorks}
        onSuccess={handleBulkDeleteSuccess}
    />
</Flex>
        );
    }

    return (
        <Box style={{ display: 'flex', flexDirection: 'column', maxHeight: '84vh' }}>
            {/* Table Header with Actions */}
            <Flex 
                direction={{ initial: 'column', md: 'row' }} 
                align={{ initial: 'start', md: 'center' }} 
                justify="between" 
                gap="4" 
                mb="4" 
                px="2"
            >
                {/* Title & Badge */}
                <Flex align="center" gap="3">
                    <Text size={{ initial: '3', md: '4' }} weight="bold">Daily Works</Text>
                    {selectedKeys.size > 0 && (
                        <Badge size="1" color="indigo" variant="soft">
                            {selectedKeys === "all" ? allData?.length || 0 : selectedKeys.size} selected
                        </Badge>
                    )}
                </Flex>

                {/* Action Buttons Toolbar */}
                <Flex 
                    align="center" 
                    gap="2" 
                    wrap="wrap" 
                    justify={{ initial: 'start', md: 'end' }}
                >
                    {selectedKeys.size > 0 && (
                        <>
                            <Button variant="soft" color="indigo" size="1" onClick={() => setBulkSubmitModalOpen(true)}>
                                <UploadIcon /> 
                                <Text display={{ initial: 'none', sm: 'inline' }}>Submit RFIs</Text>
                                ({selectedKeys === "all" ? allData?.length || 0 : selectedKeys.size})
                            </Button>
                            <Button variant="soft" color="violet" size="1" onClick={() => setBulkResponseStatusModalOpen(true)}>
                                <ClipboardIcon /> 
                                <Text display={{ initial: 'none', sm: 'inline' }}>Response Status</Text> 
                                ({selectedKeys === "all" ? allData?.length || 0 : selectedKeys.size})
                            </Button>
                            <Button variant="ghost" color="gray" size="1" onClick={() => setSelectedKeys(new Set([]))}>
                                Clear
                            </Button>
                        </>
                    )}
                    <Button variant="soft" color="violet" size="1" onClick={() => setBulkImportModalOpen(true)}>
                        <DownloadIcon />
                        <Text display={{ initial: 'none', sm: 'inline' }}>Import Submission</Text>
                    </Button>
                    <Button variant="soft" color="indigo" size="1" onClick={() => setBulkImportResponseStatusModalOpen(true)}>
                        <ClipboardIcon />
                        <Text display={{ initial: 'none', sm: 'inline' }}>Import Status</Text>
                    </Button>
                    <Button variant="soft" color="green" size="1" onClick={() => openModal('exportDailyWorks')}>
                        <DownloadIcon /> Export
                    </Button>
                    <Button variant="soft" color="indigo" size="1" onClick={handleRefresh}>
                        <ReloadIcon /> Refresh
                    </Button>
                </Flex>

                {/* Bulk Action Toolbar */}
                {selectedWorks.length > 0 && (
                    <Flex align="center" gap="2" mt={{ initial: '2', md: '0' }} style={{ background: 'var(--accent-a3)', padding: '8px 12px', borderRadius: 'var(--radius-2)' }}>
                        <Text size="2" weight="bold">{selectedWorks.length} selected</Text>
                        <Separator orientation="vertical" size="1" />
                        <Button variant="soft" color="blue" size="1" onClick={() => setBulkInchargeModalOpen(true)}>
                            <PersonIcon /> Assign Incharge
                        </Button>
                        <Button variant="soft" color="amber" size="1" onClick={() => setBulkStatusModalOpen(true)}>
                            <CheckCircledIcon /> Update Status
                        </Button>
                        <Button variant="soft" color="purple" size="1" onClick={() => setBulkCompletionDateModalOpen(true)}>
                            <CalendarIcon /> Completion Date
                        </Button>
                        <Button variant="soft" color="red" size="1" onClick={() => setBulkDeleteModalOpen(true)}>
                            <TrashIcon /> Delete
                        </Button>
                        <Button variant="ghost" color="gray" size="1" onClick={() => setSelectedKeys(new Set([]))}>
                            Clear
                        </Button>
                    </Flex>
                )}
            </Flex>

            {/* Responsive Table Area */}
            {/* Using flexGrow: 1 allows it to take up remaining space of the 84vh Box, 
                and type="auto" handles scrollbars dynamically based on screen size */}
            <ScrollArea type="auto" scrollbars="both" style={{ flexGrow: 1, minHeight: '400px' }}>
                <RadixTable.Root variant="surface" aria-label="Daily Works Management Table" style={{ width: '100%', minWidth: '800px' }}>
                    <RadixTable.Header>
                        <RadixTable.Row>
                            {/* Checkbox column for selection */}
                            <RadixTable.ColumnHeaderCell style={{ width: 48, textAlign: 'center', background: 'var(--gray-a2)' }}>
                                <input
                                    type="checkbox"
                                    checked={selectedKeys === "all" || (selectedKeys.size > 0 && selectedKeys.size === (allData?.length || 0))}
                                    onChange={(e) => {
                                        if (e.target.checked) {
                                            setSelectedKeys(new Set(allData?.map(w => w.id) || []));
                                        } else {
                                            setSelectedKeys(new Set([]));
                                        }
                                    }}
                                    style={{ cursor: 'pointer' }}
                                />
                            </RadixTable.ColumnHeaderCell>
                            {columns.map(column => (
                                <RadixTable.ColumnHeaderCell
                                    key={column.uid}
                                    style={{
                                        textAlign: column.uid === 'description' ? 'left' : 'center',
                                        // Auto-fit all cells except description, which gets a fixed constraint to allow truncation
                                        width: column.uid === 'description' ? '300px' : 'auto',
                                        maxWidth: column.uid === 'description' ? '300px' : 'none',
                                        background: 'var(--gray-a2)',
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    <Flex align="center" gap="1" justify={column.uid === 'description' ? 'start' : 'center'}>
                                        {column.icon && <column.icon width="14" height="14" />}
                                        <Text size="1" weight="bold">{column.name}</Text>
                                    </Flex>
                                </RadixTable.ColumnHeaderCell>
                            ))}
                        </RadixTable.Row>
                    </RadixTable.Header>
                    <RadixTable.Body>
                        {(allData || []).length === 0 ? (
                            <RadixTable.Row>
                                <RadixTable.Cell colSpan={columns.length + 1} style={{ textAlign: 'center', padding: '48px 0' }}>
                                    <Flex direction="column" align="center" gap="2">
                                        <FileTextIcon width={40} height={40} style={{ color: 'var(--gray-7)' }} />
                                        <Text size="3" weight="medium" color="gray">No daily works found</Text>
                                        <Text size="2" color="gray">No work logs available for the selected period</Text>
                                    </Flex>
                                </RadixTable.Cell>
                            </RadixTable.Row>
                        ) : (allData || []).map(work => (
                            <RadixTable.Row
                                key={work.id}
                                style={work.active_objections_count > 0 ? { background: 'var(--orange-2)', outline: '2px solid var(--orange-6)' } : undefined}
                            >
                                {/* Checkbox for row selection */}
                                <RadixTable.Cell style={{ width: 48, textAlign: 'center' }}>
                                    <input
                                        type="checkbox"
                                        checked={selectedKeys === "all" || selectedKeys.has(work.id)}
                                        onChange={(e) => {
                                            const newKeys = new Set(selectedKeys === "all" ? new Set(allData?.map(w => w.id) || []) : selectedKeys);
                                            if (e.target.checked) {
                                                newKeys.add(work.id);
                                            } else {
                                                newKeys.delete(work.id);
                                            }
                                            setSelectedKeys(newKeys);
                                        }}
                                        style={{ cursor: 'pointer' }}
                                    />
                                </RadixTable.Cell>
                                {columns.map(col => renderCell(work, col.uid))}
                            </RadixTable.Row>
                        ))}
                    </RadixTable.Body>
                </RadixTable.Root>
            </ScrollArea>

            {/* Pagination Navigation */}
            {totalRows > 30 && (
                <Flex 
                    py="4" 
                    justify="center" 
                    gap="3" 
                    align="center"
                    /* Radix responsive display prop replaces the JS `!isMobile` check */
                    display={{ initial: 'none', md: 'flex' }} 
                >
                    <Button 
                        variant="surface" 
                        color="gray"
                        size="2" 
                        onClick={() => handlePageChange(Math.max(1, currentPage - 1))} 
                        disabled={currentPage <= 1}
                    >
                        <ChevronLeftIcon /> Prev
                    </Button>
                    <Text size="2" color="gray" weight="medium">
                        Page {currentPage} of {lastPage}
                    </Text>
                    <Button 
                        variant="surface" 
                        color="gray"
                        size="2" 
                        onClick={() => handlePageChange(Math.min(lastPage, currentPage + 1))} 
                        disabled={currentPage >= lastPage}
                    >
                        Next <ChevronRightIcon />
                    </Button>
                </Flex>
            )}
            
            {/* --- Modals Remain Unchanged --- */}
            
            {/* RFI Files Modal */}
            <RfiFilesModal
                isOpen={rfiModalOpen}
                onClose={() => {
                    setRfiModalOpen(false);
                    setRfiModalWork(null);
                }}
                dailyWork={rfiModalWork}
                onFilesUpdated={handleRfiFilesUpdated}
            />
            
            {/* Status Update Modal */}
            <StatusUpdateModal
                open={statusModalOpen}
                closeModal={() => {
                    setStatusModalOpen(false);
                    setStatusModalWork(null);
                }}
                dailyWork={statusModalWork}
                onStatusUpdated={handleStatusUpdated}
            />
            
            {/* Objections Modal */}
            <ObjectionsModal
                isOpen={objectionsModalOpen}
                onClose={() => {
                    setObjectionsModalOpen(false);
                    setObjectionsModalWork(null);
                }}
                dailyWork={objectionsModalWork}
                onObjectionsUpdated={handleObjectionsUpdated}
            />
            
            {/* Objection Warning Modal */}
            <ObjectionWarningModal
                isOpen={objectionWarningModal.isOpen}
                onClose={closeObjectionWarningModal}
                dailyWork={objectionWarningModal.dailyWork}
                newSubmissionDate={objectionWarningModal.newSubmissionDate}
                activeObjectionsCount={objectionWarningModal.activeObjectionsCount}
                activeObjections={objectionWarningModal.activeObjections}
                isLoading={objectionWarningModal.isLoading}
                onConfirm={handleSubmissionTimeOverride}
            />
            
            {/* Bulk Submit Modal */}
            <BulkSubmitModal
                isOpen={bulkSubmitModalOpen}
                onClose={() => setBulkSubmitModalOpen(false)}
                selectedWorks={selectedWorks}
                onSuccess={handleBulkSubmitSuccess}
            />
            
            {/* Bulk Import Submit Modal */}
            <BulkImportSubmitModal
                isOpen={bulkImportModalOpen}
                onClose={() => setBulkImportModalOpen(false)}
                onSuccess={handleBulkImportSuccess}
            />

            {/* Bulk Response Status Modal */}
            <BulkResponseStatusModal
                isOpen={bulkResponseStatusModalOpen}
                onClose={() => setBulkResponseStatusModalOpen(false)}
                selectedWorks={selectedWorks}
                onSuccess={handleBulkResponseStatusSuccess}
            />

            {/* Bulk Import Response Status Modal */}
            <BulkImportResponseStatusModal
                isOpen={bulkImportResponseStatusModalOpen}
                onClose={() => setBulkImportResponseStatusModalOpen(false)}
                onSuccess={handleBulkImportResponseStatusSuccess}
            />
        </Box>
    );
};

export default DailyWorksTable;
