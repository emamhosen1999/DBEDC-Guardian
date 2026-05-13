import React, { useState, useCallback } from "react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { usePage } from "@inertiajs/react";
import { showToast } from '@/utils/toastUtils';
import { getProfileAvatarTokens } from '@/Components/ProfileAvatar';
import {
    Table,
    TableHeader,
    TableColumn,
    TableBody,
    TableRow,
    TableCell,
    User,
    Tooltip,
    Pagination,
    Chip,
    Button,
    Dropdown,
    DropdownTrigger,
    DropdownMenu,
    DropdownItem,
    Card,
    CardBody,
    Divider,
    ScrollShadow,
    Link,
} from "@/compat/heroui";
import {
    CalendarIcon,
    CheckCircledIcon,
    ClockIcon,
    CrossCircledIcon,
    DotsVerticalIcon,
    ExclamationTriangleIcon,
    FileTextIcon,
    Pencil1Icon,
    PersonIcon,
    TrashIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import ApprovalActions from '@/Components/Leave/ApprovalActions.jsx';




const LeaveEmployeeTable = React.forwardRef(({
    leaves,
    allUsers,
    handleClickOpen,
    setCurrentLeave,
    openModal,
    setLeaves,
    setCurrentPage,
    currentPage,
    totalRows,
    lastPage,
    perPage,
    selectedMonth,
    employee,
    isAdminView = false,
    onBulkApprove,
    onBulkReject,
    onBulkDelete,
    canApproveLeaves = false,
    canEditLeaves = false,
    canDeleteLeaves = false,
    fetchLeavesStats 
}, ref) => {
    const { auth } = usePage().props;

    const isLargeScreen = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    const isMobile = useMediaQuery('(max-width: 640px)');

    const [isUpdating, setIsUpdating] = useState(false);
    const [updatingLeave, setUpdatingLeave] = useState(null);

    const [selectedKeys, setSelectedKeys] = useState(new Set());

    const selectedValue = React.useMemo(
        () => Array.from(selectedKeys).join(", ").replaceAll("_", " "),
        [selectedKeys]
    );

    const topContent = React.useMemo(() => {
        const isAllSelected = selectedKeys === "all";
        const hasSelection = isAllSelected || selectedKeys.size > 0;
        const selectedCount = isAllSelected ? leaves.length : selectedKeys.size;
        
        return (
            <div className="flex flex-col gap-4">
                <div className="flex justify-between gap-3 items-end">
                    <div className="flex gap-3">
                        {hasSelection && onBulkDelete && (
                            <Button
                                color="danger"
                                variant="flat"
                                startContent={<TrashIcon className="w-4 h-4" />}
                                onPress={() => {
                                    const selectedLeavesArray = isAllSelected 
                                        ? leaves 
                                        : leaves.filter(leave => selectedKeys.has(leave.id.toString()));
                                    onBulkDelete(selectedLeavesArray);
                                }}
                                style={{
                                    background: `color-mix(in srgb, var(--theme-danger) 20%, transparent)`,
                                    border: `1px solid color-mix(in srgb, var(--theme-danger) 30%, transparent)`,
                                    color: 'var(--theme-danger)',
                                }}
                            >
                                Delete {selectedCount} selected
                            </Button>
                        )}
                    </div>
                </div>
                {hasSelection && (
                    <span 
                        className="text-small opacity-70"
                        style={{ color: 'var(--theme-foreground)' }}
                    >
                        {selectedCount} of {leaves.length} selected
                    </span>
                )}
            </div>
        );
    }, [selectedKeys, leaves, onBulkDelete]);
    const canViewLeaves = auth.permissions?.includes('leaves.view') || false;
    const canManageOwnLeaves = auth.permissions?.includes('leave.own.view') || false;
    const hasAdminAccess = isAdminView && (canApproveLeaves || canEditLeaves || canDeleteLeaves);

    // Permission-based access control (replacing role-based checks)
    const userIsAdmin = isAdminView || hasAdminAccess;
    const userIsSE = canApproveLeaves; // SE/Manager can approve leaves

    // Status configuration
    const statusConfig = {
        'New': { color: 'primary', icon: ExclamationTriangleIcon },
        'Pending': { color: 'warning', icon: ClockIcon },
        'Approved': { color: 'success', icon: CheckCircledIcon },
        'Declined': { color: 'danger', icon: CrossCircledIcon }
    };

    

    const getLeaveTypeIcon = (type) => {
        switch (type?.toLowerCase()) {
            case "casual": return <FileTextIcon style={{ width: 12, height: 12, color: '#3b82f6' }} />;
            case "weekend": return <CalendarIcon style={{ width: 12, height: 12, color: '#eab308' }} />;
            case "sick": return <CrossCircledIcon style={{ width: 12, height: 12, color: '#ef4444' }} />;
            case "earned": return <ClockIcon style={{ width: 12, height: 12, color: '#22c55e' }} />;
            default: return <FileTextIcon style={{ width: 12, height: 12, color: 'var(--accent-9)' }} />;
        }
    };

    const handlePageChange = useCallback((page) => {
     
        if (setCurrentPage) {
            // When page changes, this will trigger fetchLeavesData in the parent component
            // which will load the correct data for the requested page
            setCurrentPage(page);
        }
    }, [setCurrentPage]);

    


    const updateLeaveStatus = useCallback(async (leave, newStatus) => {
    // If the leave is already in the desired status, resolve early and do not trigger loader or API
    if (leave.status === newStatus) {
        showToast.info(`Leave is already ${newStatus}.`);
        return Promise.resolve(`The leave status is already updated to ${newStatus}`);
    }

    // Prevent multiple updates for the same leave/status action
    const actionKey = `${leave.id}-${newStatus}`;
    if (updatingLeave === actionKey) return;

    setUpdatingLeave(actionKey);

    const promise = new Promise(async (resolve, reject) => {
        try {
            const response = await axios.post(route("leave-update-status"), {
                id: leave.id,
                status: newStatus
            });

            if (response.status === 200) {
                setLeaves((prevLeaves) =>
                    prevLeaves.map((l) =>
                        l.id === leave.id ? { ...l, status: newStatus } : l
                    )
                );
                fetchLeavesStats();
                resolve(response.data.message || "Leave status updated successfully");
            } else {
                reject(response.data?.message || "Failed to update leave status");
            }
        } catch (error) {
            const errorMsg = error.response?.data?.message ||
                error.response?.statusText ||
                "Failed to update leave status";
            reject(errorMsg);
        } finally {
            setUpdatingLeave(null);
        }
    });

    showToast.promise(promise, {
        loading: "Updating leave status...",
        success: "Leave status updated successfully!",
        error: "Failed to update leave status"
    });

    return promise;
    }, [setLeaves, updatingLeave, fetchLeavesStats]);
    
    const getStatusChip = (status) => {
        const config = statusConfig[status] || statusConfig['New'];
        const StatusIcon = config.icon;

        // Map status to theme colors
        const getStatusColors = (status) => {
            switch (status.toLowerCase()) {
                case 'approved':
                    return {
                        bg: 'color-mix(in srgb, var(--theme-success) 20%, transparent)',
                        border: 'color-mix(in srgb, var(--theme-success) 40%, transparent)',
                        color: 'var(--theme-success)'
                    };
                case 'rejected':
                    return {
                        bg: 'color-mix(in srgb, var(--theme-danger) 20%, transparent)',
                        border: 'color-mix(in srgb, var(--theme-danger) 40%, transparent)',
                        color: 'var(--theme-danger)'
                    };
                case 'pending':
                    return {
                        bg: 'color-mix(in srgb, var(--theme-warning) 20%, transparent)',
                        border: 'color-mix(in srgb, var(--theme-warning) 40%, transparent)',
                        color: 'var(--theme-warning)'
                    };
                default:
                    return {
                        bg: 'color-mix(in srgb, var(--theme-primary) 20%, transparent)',
                        border: 'color-mix(in srgb, var(--theme-primary) 40%, transparent)',
                        color: 'var(--theme-primary)'
                    };
            }
        };

        const colors = getStatusColors(status);

        return (
            <Chip
                size="sm"
                variant="flat"
                startContent={<StatusIcon style={{ width: 12, height: 12 }} />}
                style={{
                    background: colors.bg,
                    border: `1px solid ${colors.border}`,
                    color: colors.color,
                }}
            >
                {status}
            </Chip>
        );
    };

    const getLeaveDuration = (fromDate, toDate) => {
        const from = new Date(fromDate);
        const to = new Date(toDate);
        const diffTime = Math.abs(to - from);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
        return diffDays === 1 ? '1 day' : `${diffDays} days`;
    };

    const formatDate = (dateString) => {
        return new Date(dateString).toLocaleDateString("en-US", {
            day: "numeric",
            month: "short",
            year: "numeric"
        });
    };

    const getUserInfo = (userId) => {
        return allUsers?.find((u) => String(u.id) === String(userId)) || { name: 'Unknown User', phone: '' };
    };

    // Mobile card component for better mobile experience
    const MobileLeaveCard = ({ leave, updatingLeave, setCurrentLeave, openModal, handleClickOpen, updateLeaveStatus, canEditLeaves, canDeleteLeaves, canApproveLeaves, isAdminView }) => {
        const user = getUserInfo(leave.user_id);
        const duration = getLeaveDuration(leave.from_date, leave.to_date);
        const statusConf = statusConfig[leave.status] || statusConfig['New'];


        return (
            <Card 
                className="mb-2"
                style={{
                    background: `color-mix(in srgb, var(--theme-content1) 85%, transparent)`,
                    backdropFilter: 'blur(16px)',
                    border: `1px solid color-mix(in srgb, var(--theme-content2) 50%, transparent)`,
                }}
            >
                <CardBody className="p-3">
                    <div className="flex items-start justify-between mb-3">
                        <div className="flex items-center gap-3 flex-1">
                            {isAdminView && (
                                <User
                        avatarProps={{
                        src: user?.profile_image_url || user?.profile_image,
                        size: "sm",
                        name: user?.name || "Unnamed User",
                        ...getProfileAvatarTokens({
                            name: user?.name || "Unnamed User",
                            size: 'sm',
                        }),
                        }}
                        description={user?.phone ? <a href={`tel:${user?.phone}`} className="text-xs text-blue-500 hover:underline">{user?.phone}</a> : <span className="text-xs text-gray-400 italic">No Phone</span>}
                        name={<span className="text-sm font-medium">{user?.name || "Unnamed User"}</span>}
                    />
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {getStatusChip(leave.status)}
                            {(canEditLeaves || canDeleteLeaves) && (
                                <Dropdown>
                                    <DropdownTrigger>
                                        <Button isIconOnly size="sm" variant="light" className="min-w-8 h-8">
                                            <DotsVerticalIcon style={{ width: 16, height: 16 }} />
                                        </Button>
                                    </DropdownTrigger>
                                    <DropdownMenu aria-label="Leave actions">
                                        {canEditLeaves && (
                                            <DropdownItem key="edit" startContent={<Pencil1Icon style={{ width: 16, height: 16 }} />} onPress={() => { setCurrentLeave(leave); openModal("edit_leave"); }}>
                                                Edit Leave
                                            </DropdownItem>
                                        )}
                                        {canDeleteLeaves && (
                                            <DropdownItem key="delete" color="danger" startContent={<TrashIcon style={{ width: 16, height: 16 }} />} onPress={() => handleClickOpen(leave.id, "delete_leave")}>
                                                Delete Leave
                                            </DropdownItem>
                                        )}
                                    </DropdownMenu>
                                </Dropdown>
                            )}
                        </div>
                    </div>

                    <Divider className="my-3" />

                    <div className="flex flex-col gap-2">
                        <div className="flex items-center gap-2">
                            <FileTextIcon style={{ width: 16, height: 16, color: 'var(--accent-9)' }} />
                            <span className="text-sm font-medium">
                                {leave.leave_type}
                            </span>
                        </div>

                        <div className="flex items-center gap-2">
                            <CalendarIcon 
                                style={{ width: 16, height: 16, opacity: 0.6, color: 'var(--theme-foreground)' }}
                            />
                            <span 
                                className="text-sm opacity-80"
                                style={{ color: 'var(--theme-foreground)' }}
                            >
                                {formatDate(leave.from_date)} - {formatDate(leave.to_date)}
                            </span>
                            <Chip 
                                size="sm" 
                                variant="bordered"
                                style={{
                                    background: 'color-mix(in srgb, var(--theme-content2) 50%, transparent)',
                                    border: `1px solid color-mix(in srgb, var(--theme-content3) 50%, transparent)`,
                                    color: 'var(--theme-foreground)',
                                }}
                            >
                                {duration}
                            </Chip>
                        </div>

                        {leave.reason && (
                            <div className="flex items-start gap-2">
                                <ClockIcon 
                                    style={{ width: 16, height: 16, marginTop: 2, opacity: 0.6, color: 'var(--theme-foreground)' }}
                                />
                                <span 
                                    className="text-sm flex-1 opacity-80"
                                    style={{ color: 'var(--theme-foreground)' }}
                                >
                                    {leave.reason}
                                </span>
                            </div>
                        )}
                    </div>

                    {isAdminView && canApproveLeaves && (
                        <>
                            <Divider className="my-3" />
                            <div className="flex gap-2">
                                {['Approved', 'Declined'].map((status) => (
                                    <Button
                                        key={status}
                                        size="sm"
                                        variant={leave.status === status ? "solid" : "bordered"}
                                        color={statusConfig[status].color}
                                        isLoading={updatingLeave === `${leave.id}-${status}`}
                                        onPress={() => {
                                            if (updatingLeave === `${leave.id}-${status}`) return;
                                            updateLeaveStatus(leave, status);
                                        }}
                                        startContent={
                                            updatingLeave !== `${leave.id}-${status}` ? 
                                            React.createElement(statusConfig[status].icon, {
                                                style: { width: 12, height: 12 }
                                            }) : null
                                        }
                                        classNames={{
                                            base: "flex-1"
                                        }}
                                    >
                                        {status}
                                    </Button>
                                ))}
                            </div>
                        </>
                    )}
                </CardBody>
            </Card>
        );
    };

    const renderCell = useCallback((leave, columnKey) => {
        
        const user = getUserInfo(leave.user_id);

        switch (columnKey) {
            case "employee":
                return (
                    <TableCell className="whitespace-nowrap">
                    <User
                        avatarProps={{
                        src: user?.profile_image_url || user?.profile_image,
                        size: "sm",
                        name: user?.name || "Unnamed User",
                        ...getProfileAvatarTokens({
                            name: user?.name || "Unnamed User",
                            size: 'sm',
                        }),
                        }}
                        description={user?.phone ? <a href={`tel:${user?.phone}`} className="text-xs text-blue-500 hover:underline">{user?.phone}</a> : <span className="text-xs text-gray-400 italic">No Phone</span>}
                        name={<span className="text-sm font-medium">{user?.name || "Unnamed User"}</span>}
                    />
                    </TableCell>
                );

            case "leave_type":
                return (
                    <TableCell>
                        <div className="flex items-center gap-1">
                            {getLeaveTypeIcon(leave.leave_type)}
                            <span className="text-sm font-medium capitalize">
                                {leave.leave_type}
                            </span>
                        </div>
                    </TableCell>
                );
            case "from_date":
         
            case "to_date":
 
                return (
                    <TableCell>
                        <div className="flex items-center gap-1">
                            <CalendarIcon 
                                style={{ width: 12, height: 12, opacity: 0.6, color: 'var(--theme-foreground)' }}
                            />
                            <div>
                                <span 
                                    className="text-sm"
                                    style={{ color: 'var(--theme-foreground)' }}
                                >
                                    {formatDate(leave[columnKey])}
                                </span>
                                {columnKey === "from_date" && (
                                    <div 
                                        className="text-xs opacity-60"
                                        style={{ color: 'var(--theme-foreground)' }}
                                    >
                                        {getLeaveDuration(leave.from_date, leave.to_date)}
                                    </div>
                                )}
                            </div>
                        </div>
                    </TableCell>
                );

            case "status":
                const canApproveThisLeave = leave.approval_chain && 
                    leave.status === 'pending' && 
                    leave.approval_chain.some(level => 
                        level.level === leave.current_approval_level && 
                        level.approver_id === auth.user.id && 
                        level.status === 'pending'
                    );

                return (
                    <TableCell>
                        <div className="flex items-center gap-2">
                            {getStatusChip(leave.status)}
                            {canApproveThisLeave ? (
                                <ApprovalActions 
                                    leave={leave} 
                                    onApprovalComplete={() => {
                                        if (fetchLeavesStats) fetchLeavesStats();
                                        window.location.reload();
                                    }}
                                />
                            ) : isAdminView && canApproveLeaves && (
                                <Dropdown>
                                    <DropdownTrigger>
                                        <Button 
                                            isIconOnly 
                                            size="sm" 
                                            variant="light"
                                            isDisabled={updatingLeave && updatingLeave.startsWith(`${leave.id}-`)}
                                            className="min-w-8 h-8"
                                        >
                                            <DotsVerticalIcon style={{ width: 16, height: 16 }} />
                                        </Button>
                                    </DropdownTrigger>
                                    <DropdownMenu aria-label="Status actions">
                                        {Object.keys(statusConfig).map((status) => {
                                            const config = statusConfig[status];
                                            const StatusIcon = config.icon;
                                            return (
                                                <DropdownItem
                                                    key={status}
                                                    startContent={<StatusIcon style={{ width: 16, height: 16 }} />}
                                                    color={config.color}
                                                    onPress={() => updateLeaveStatus(leave, status)}
                                                >
                                                    {status}
                                                </DropdownItem>
                                            );
                                        })}
                                    </DropdownMenu>
                                </Dropdown>
                            )}
                        </div>
                    </TableCell>
                );

            case "reason":
                return (
                    <TableCell>
                        <Tooltip content={leave.reason || "No reason provided"}>
                            <span 
                                className="max-w-xs truncate cursor-help text-xs opacity-70"
                                style={{ color: 'var(--theme-foreground)' }}
                            >
                                {leave.reason || "No reason provided"}
                            </span>
                        </Tooltip>
                    </TableCell>
                );

            case "actions":
                return (
                    <TableCell>
                        <div className="flex items-center gap-1">
                            {canEditLeaves && (
                                <Tooltip content="Edit Leave">
                                    <Button
                                        isIconOnly size="sm" variant="light" color="primary"
                                        isDisabled={updatingLeave && updatingLeave.startsWith(`${leave.id}-`)}
                                        onPress={() => { if (updatingLeave && updatingLeave.startsWith(`${leave.id}-`)) return; setCurrentLeave(leave); openModal("edit_leave"); }}
                                        className="min-w-8 h-8"
                                    >
                                        <Pencil1Icon style={{ width: 16, height: 16 }} />
                                    </Button>
                                </Tooltip>
                            )}
                            {canDeleteLeaves && (
                                <Tooltip content="Delete Leave" color="danger">
                                    <Button
                                        isIconOnly size="sm" variant="light" color="danger"
                                        isDisabled={updatingLeave && updatingLeave.startsWith(`${leave.id}-`)}
                                        onPress={() => { if (updatingLeave && updatingLeave.startsWith(`${leave.id}-`)) return; setCurrentLeave(leave); handleClickOpen(leave.id, "delete_leave"); }}
                                        className="min-w-8 h-8"
                                    >
                                        <TrashIcon style={{ width: 16, height: 16 }} />
                                    </Button>
                                </Tooltip>
                            )}
                        </div>
                    </TableCell>
                );

            default:
                return <TableCell>{leave[columnKey]}</TableCell>;
        }
    }, [isAdminView, canApproveLeaves, canEditLeaves, canDeleteLeaves, isLargeScreen, updatingLeave, setCurrentLeave, openModal, handleClickOpen, updateLeaveStatus, auth, fetchLeavesStats]);

    const columns = [
        ...(isAdminView ? [{ name: "Employee", uid: "employee", icon: PersonIcon }] : []),
        { name: "Leave Type", uid: "leave_type", icon: FileTextIcon },
        { name: "From Date", uid: "from_date", icon: CalendarIcon },
        { name: "To Date", uid: "to_date", icon: CalendarIcon },
        { name: "Status", uid: "status", icon: ClockIcon },
        { name: "Reason", uid: "reason", icon: FileTextIcon },
        ...(isAdminView ? [{ name: "Actions", uid: "actions" }] : [])
    ];

    if (isMobile) {
        return (
            <div className="space-y-4">
                <ScrollShadow className="max-h-[70vh]">
                    {leaves.map((leave) => (
                        <MobileLeaveCard
                            key={leave.id}
                            leave={leave}
                            updatingLeave={updatingLeave}
                            setCurrentLeave={setCurrentLeave}
                            openModal={openModal}
                            handleClickOpen={handleClickOpen}
                            updateLeaveStatus={updateLeaveStatus}
                            canEditLeaves={canEditLeaves}
                            canDeleteLeaves={canDeleteLeaves}
                            canApproveLeaves={canApproveLeaves}
                            isAdminView={isAdminView}
                        />
                    ))}
                </ScrollShadow>
                {totalRows > perPage && (
                    <div 
                        className="flex justify-center items-center"
                    >
                        <Pagination
                            initialPage={1}
                            isCompact
                            showControls
                            showShadow
                            color="primary"
                            variant="bordered"
                            page={currentPage}
                            total={lastPage}
                            onChange={handlePageChange}
                        />
                        
                    </div>
                )}
            </div>
        );
    }

    return (
        <div 
           
        >
            <ScrollShadow className="max-h-[70vh]">
                <Table
                    isStriped
                    setSelection
                    selectionMode={isAdminView? "multiple" : "none"}
                    selectedKeys={selectedKeys}
                    onSelectionChange={setSelectedKeys}
                    topContent={topContent}
                    topContentPlacement="outside"
                    isCompact
                    isHeaderSticky
                    removeWrapper
                    aria-label="Leave Management Table"
                    disabledBehavior="selection"
                    classNames={{
                        base: "max-h-[520px] overflow-auto",
                        table: "min-h-[200px] w-full",
                        thead: "z-10",
                        tbody: "overflow-y-auto",
                        th: "bg-default-100 text-default-700 font-semibold",
                        td: "text-default-600",
                    }}
                    style={{
                        borderRadius: `var(--borderRadius, 12px)`,
                        fontFamily: `var(--fontFamily, "Inter")`,
                    }}
                >
                    <TableHeader columns={columns}>
                        {(column) => (
                            <TableColumn 
                                key={column.uid} 
                                align={column.uid === "actions" ? "center" : "start"}
                                className="backdrop-blur-md"
                                style={{
                                    backgroundColor: 'color-mix(in srgb, var(--theme-content2) 60%, transparent)',
                                    color: 'var(--theme-foreground)',
                                    borderBottom: `1px solid color-mix(in srgb, var(--theme-content3) 50%, transparent)`,
                                }}
                            >
                                <div className="flex items-center gap-1">
                                    {column.icon && React.createElement(column.icon, { style: { width: 12, height: 12 } })}
                                    <span className="text-xs font-semibold">{column.name}</span>
                                </div>
                            </TableColumn>
                        )}
                    </TableHeader>
                    <TableBody 
                        items={leaves}
                        emptyContent={
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <CalendarIcon 
                                    style={{ width: 48, height: 48, marginBottom: 16, opacity: 0.4, color: 'var(--theme-foreground)' }}
                                />
                                <h6 
                                    className="text-lg font-semibold mb-2"
                                    style={{ color: 'var(--theme-foreground)' }}
                                >
                                    No leaves found
                                </h6>
                                <p 
                                    className="text-sm opacity-70"
                                    style={{ color: 'var(--theme-foreground)' }}
                                >
                                    {employee ? `No leaves found for "${employee}"` : "No leave requests for the selected period"}
                                </p>
                            </div>
                        }
                    >
                        {(leave) => (
                            <TableRow 
                                key={leave.id}
                                className="transition-all duration-200 hover:scale-[1.01]"
                                style={{
                                    color: 'var(--theme-foreground)',
                                    borderBottom: `1px solid color-mix(in srgb, var(--theme-content3) 30%, transparent)`,
                                }}
                            >
                                {(columnKey) => renderCell(leave, columnKey)}
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </ScrollShadow>
            {totalRows > perPage && (
                <div 
                    className="flex justify-center items-center"
                >
                    <Pagination
                        initialPage={1}
                        isCompact
                        showControls
                        showShadow
                        color="primary"
                        variant="bordered"
                        page={currentPage}
                        total={lastPage}
                        onChange={handlePageChange}
                    />
                    <div 
                        className="text-xs opacity-70"
                        style={{ color: 'var(--theme-foreground)' }}
                    >
                        Page {currentPage} of {lastPage} (Total: {totalRows} records)
                    </div>
                </div>
            )}
        </div>
    );
});

LeaveEmployeeTable.displayName = 'LeaveEmployeeTable';

export default LeaveEmployeeTable;