import React, { useState, useEffect, useCallback } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import {
    Card,
    CardBody,
    CardHeader,
    Button,
    Input,
    Select,
    SelectItem,
    Chip,
    Badge,
    Pagination,
    Spinner,
    Modal,
    ModalContent,
    ModalHeader,
    ModalBody,
    ModalFooter,
    Textarea,
    Checkbox,
    Dropdown,
    DropdownTrigger,
    DropdownMenu,
    DropdownItem,
    Table,
    TableHeader,
    TableBody,
    TableColumn,
    TableRow,
    TableCell,
    Tooltip,
    Avatar,
    Breadcrumbs,
    BreadcrumbItem
} from '@heroui/react';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    FunnelIcon,
    ArrowPathIcon,
    EnvelopeIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    CheckCircleIcon,
    ArchiveBoxIcon,
    EyeIcon,
    PencilIcon,
    TrashIcon,
    PaperAirplaneIcon,
    DocumentArrowDownIcon,
    UserIcon,
    TagIcon
} from '@heroicons/react/24/outline';
import { toast } from 'react-hot-toast';
import axios from 'axios';

export default function Letters({ auth }) {
    const { url } = usePage();
    const [letters, setLetters] = useState([]);
    const [stats, setStats] = useState({});
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedStatus, setSelectedStatus] = useState('');
    const [selectedPriority, setSelectedPriority] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [selectedLetters, setSelectedLetters] = useState([]);
    const [showFilters, setShowFilters] = useState(false);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showDetailModal, setShowDetailModal] = useState(false);
    const [selectedLetter, setSelectedLetter] = useState(null);
    const [filters, setFilters] = useState({
        unread_only: false,
        urgent_only: false,
        needing_reply: false,
        overdue: false
    });

    // Form states
    const [formData, setFormData] = useState({
        from: '',
        sender_name: '',
        sender_email: '',
        sender_address: '',
        sender_phone: '',
        recipient: '',
        subject: '',
        content: '',
        priority: 'normal',
        category: 'general',
        received_date: new Date().toISOString().split('T')[0],
        due_date: '',
        need_reply: false,
        need_forward: false,
        confidential: false,
        attachments: [],
        tags: []
    });

    const [replyContent, setReplyContent] = useState('');

    const statusOptions = [
        { value: '', label: 'All Status' },
        { value: 'unread', label: 'Unread' },
        { value: 'read', label: 'Read' },
        { value: 'processed', label: 'Processed' },
        { value: 'archived', label: 'Archived' },
        { value: 'urgent', label: 'Urgent' }
    ];

    const priorityOptions = [
        { value: '', label: 'All Priority' },
        { value: 'low', label: 'Low' },
        { value: 'normal', label: 'Normal' },
        { value: 'high', label: 'High' },
        { value: 'urgent', label: 'Urgent' }
    ];

    const categoryOptions = [
        { value: '', label: 'All Categories' },
        { value: 'general', label: 'General' },
        { value: 'official', label: 'Official' },
        { value: 'personal', label: 'Personal' },
        { value: 'legal', label: 'Legal' },
        { value: 'financial', label: 'Financial' }
    ];

    const fetchLetters = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({
                page: currentPage,
                search: searchTerm,
                status: selectedStatus,
                priority: selectedPriority,
                category: selectedCategory,
                ...filters
            });

            const response = await axios.get(`/letters?${params}`);
            setLetters(response.data.data.data);
            setStats(response.data.stats);
            setTotalPages(response.data.data.last_page);
        } catch (error) {
            toast.error('Failed to fetch letters');
            console.error('Error fetching letters:', error);
        } finally {
            setLoading(false);
        }
    }, [currentPage, searchTerm, selectedStatus, selectedPriority, selectedCategory, filters]);

    useEffect(() => {
        fetchLetters();
    }, [fetchLetters]);

    const handleStatusChange = async (letterId, newStatus) => {
        try {
            await axios.put(`/letters/${letterId}`, { status: newStatus });
            toast.success('Letter status updated');
            fetchLetters();
        } catch (error) {
            toast.error('Failed to update status');
        }
    };

    const handleBulkUpdate = async (action, value = null) => {
        if (selectedLetters.length === 0) {
            toast.error('Please select letters first');
            return;
        }

        try {
            await axios.post('/letters/bulk-update', {
                letter_ids: selectedLetters,
                action,
                value
            });
            toast.success('Bulk update completed');
            setSelectedLetters([]);
            fetchLetters();
        } catch (error) {
            toast.error('Bulk update failed');
        }
    };

    const handleSyncEmails = async () => {
        try {
            const response = await axios.post('/letters/sync-emails');
            toast.success(`Synced ${response.data.processed} emails`);
            fetchLetters();
        } catch (error) {
            toast.error('Email sync failed');
        }
    };

    const handleCreateLetter = async () => {
        try {
            const formDataToSend = new FormData();

            Object.keys(formData).forEach(key => {
                if (key === 'attachments') {
                    formData.attachments.forEach(file => {
                        formDataToSend.append('attachments[]', file);
                    });
                } else if (key === 'tags') {
                    formDataToSend.append('tags', JSON.stringify(formData.tags));
                } else {
                    formDataToSend.append(key, formData[key]);
                }
            });

            await axios.post('/letters', formDataToSend, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            toast.success('Letter created successfully');
            setShowCreateModal(false);
            setFormData({
                from: '',
                sender_name: '',
                sender_email: '',
                sender_address: '',
                sender_phone: '',
                recipient: '',
                subject: '',
                content: '',
                priority: 'normal',
                category: 'general',
                received_date: new Date().toISOString().split('T')[0],
                due_date: '',
                need_reply: false,
                need_forward: false,
                confidential: false,
                attachments: [],
                tags: []
            });
            fetchLetters();
        } catch (error) {
            toast.error('Failed to create letter');
        }
    };

    const handleSendReply = async () => {
        if (!selectedLetter || !replyContent.trim()) {
            toast.error('Please enter reply content');
            return;
        }

        try {
            await axios.post(`/letters/${selectedLetter.id}/reply`, {
                reply_content: replyContent
            });
            toast.success('Reply sent successfully');
            setReplyContent('');
            setShowDetailModal(false);
            fetchLetters();
        } catch (error) {
            toast.error('Failed to send reply');
        }
    };

    const downloadAttachment = (letterId, attachmentIndex) => {
        window.open(`/letters/${letterId}/attachment/${attachmentIndex}`, '_blank');
    };

    const getStatusColor = (status) => {
        const colors = {
            unread: 'danger',
            read: 'primary',
            processed: 'success',
            archived: 'secondary',
            urgent: 'warning'
        };
        return colors[status] || 'default';
    };

    const getPriorityColor = (priority) => {
        const colors = {
            low: 'secondary',
            normal: 'default',
            high: 'warning',
            urgent: 'danger'
        };
        return colors[priority] || 'default';
    };

    return (
        <>
            <Head title="Incoming Letters" />

            <div className="container mx-auto px-4 py-6">
                {/* Header */}
                <div className="mb-6">
                    <Breadcrumbs className="mb-4">
                        <BreadcrumbItem href="/dashboard">Dashboard</BreadcrumbItem>
                        <BreadcrumbItem>Incoming Letters</BreadcrumbItem>
                    </Breadcrumbs>

                    <div className="flex justify-between items-center">
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">
                                Incoming Letters
                            </h1>
                            <p className="text-gray-600 dark:text-gray-400 mt-1">
                                Manage and track all incoming correspondence
                            </p>
                        </div>

                        <div className="flex gap-3">
                            <Button
                                color="primary"
                                variant="flat"
                                startContent={<ArrowPathIcon className="w-4 h-4" />}
                                onPress={handleSyncEmails}
                            >
                                Sync Emails
                            </Button>
                            <Button
                                color="primary"
                                startContent={<PlusIcon className="w-4 h-4" />}
                                onPress={() => setShowCreateModal(true)}
                            >
                                New Letter
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Stats Cards */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <Card>
                        <CardBody className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">Total Letters</p>
                                    <p className="text-2xl font-bold">{stats.total || 0}</p>
                                </div>
                                <EnvelopeIcon className="w-8 h-8 text-blue-500" />
                            </div>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardBody className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">Unread</p>
                                    <p className="text-2xl font-bold text-red-500">{stats.unread || 0}</p>
                                </div>
                                <EyeIcon className="w-8 h-8 text-red-500" />
                            </div>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardBody className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">Urgent</p>
                                    <p className="text-2xl font-bold text-orange-500">{stats.urgent || 0}</p>
                                </div>
                                <ExclamationTriangleIcon className="w-8 h-8 text-orange-500" />
                            </div>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardBody className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">Needing Reply</p>
                                    <p className="text-2xl font-bold text-blue-500">{stats.needing_reply || 0}</p>
                                </div>
                                <PaperAirplaneIcon className="w-8 h-8 text-blue-500" />
                            </div>
                        </CardBody>
                    </Card>
                </div>

                {/* Filters and Search */}
                <Card className="mb-6">
                    <CardBody>
                        <div className="flex flex-col lg:flex-row gap-4">
                            <div className="flex-1">
                                <Input
                                    placeholder="Search letters..."
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    startContent={<MagnifyingGlassIcon className="w-4 h-4" />}
                                />
                            </div>

                            <div className="flex gap-2">
                                <Select
                                    placeholder="Status"
                                    selectedKeys={[selectedStatus]}
                                    onSelectionChange={(keys) => setSelectedStatus([...keys][0] || '')}
                                    className="w-32"
                                >
                                    {statusOptions.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </Select>

                                <Select
                                    placeholder="Priority"
                                    selectedKeys={[selectedPriority]}
                                    onSelectionChange={(keys) => setSelectedPriority([...keys][0] || '')}
                                    className="w-32"
                                >
                                    {priorityOptions.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </Select>

                                <Select
                                    placeholder="Category"
                                    selectedKeys={[selectedCategory]}
                                    onSelectionChange={(keys) => setSelectedCategory([...keys][0] || '')}
                                    className="w-36"
                                >
                                    {categoryOptions.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </Select>

                                <Button
                                    variant="flat"
                                    onPress={() => setShowFilters(!showFilters)}
                                    startContent={<FunnelIcon className="w-4 h-4" />}
                                >
                                    Filters
                                </Button>
                            </div>
                        </div>

                        {showFilters && (
                            <div className="mt-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <Checkbox
                                        isSelected={filters.unread_only}
                                        onValueChange={(checked) => setFilters({...filters, unread_only: checked})}
                                    >
                                        Unread Only
                                    </Checkbox>
                                    <Checkbox
                                        isSelected={filters.urgent_only}
                                        onValueChange={(checked) => setFilters({...filters, urgent_only: checked})}
                                    >
                                        Urgent Only
                                    </Checkbox>
                                    <Checkbox
                                        isSelected={filters.needing_reply}
                                        onValueChange={(checked) => setFilters({...filters, needing_reply: checked})}
                                    >
                                        Needing Reply
                                    </Checkbox>
                                    <Checkbox
                                        isSelected={filters.overdue}
                                        onValueChange={(checked) => setFilters({...filters, overdue: checked})}
                                    >
                                        Overdue
                                    </Checkbox>
                                </div>
                            </div>
                        )}
                    </CardBody>
                </Card>

                {/* Bulk Actions */}
                {selectedLetters.length > 0 && (
                    <Card className="mb-4">
                        <CardBody>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-gray-600 dark:text-gray-400">
                                    {selectedLetters.length} letters selected
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="flat"
                                        onPress={() => handleBulkUpdate('mark_read')}
                                    >
                                        Mark Read
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="flat"
                                        onPress={() => handleBulkUpdate('archive')}
                                    >
                                        Archive
                                    </Button>
                                    <Dropdown>
                                        <DropdownTrigger>
                                            <Button size="sm" variant="flat">
                                                Change Priority
                                            </Button>
                                        </DropdownTrigger>
                                        <DropdownMenu>
                                            {priorityOptions.slice(1).map((option) => (
                                                <DropdownItem
                                                    key={option.value}
                                                    onPress={() => handleBulkUpdate('change_priority', option.value)}
                                                >
                                                    {option.label}
                                                </DropdownItem>
                                            ))}
                                        </DropdownMenu>
                                    </Dropdown>
                                </div>
                            </div>
                        </CardBody>
                    </Card>
                )}

                {/* Letters Table */}
                <Card>
                    <CardBody>
                        {loading ? (
                            <div className="flex justify-center py-8">
                                <Spinner size="lg" />
                            </div>
                        ) : (
                            <Table
                                aria-label="Letters table"
                                selectionMode="multiple"
                                selectedKeys={selectedLetters}
                                onSelectionChange={setSelectedLetters}
                            >
                                <TableHeader>
                                    <TableColumn>Subject</TableColumn>
                                    <TableColumn>From</TableColumn>
                                    <TableColumn>Status</TableColumn>
                                    <TableColumn>Priority</TableColumn>
                                    <TableColumn>Category</TableColumn>
                                    <TableColumn>Received</TableColumn>
                                    <TableColumn>Actions</TableColumn>
                                </TableHeader>
                                <TableBody>
                                    {letters.map((letter) => (
                                        <TableRow key={letter.id}>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span className="font-medium">{letter.subject}</span>
                                                    <span className="text-sm text-gray-500">
                                                        {letter.reference_number}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span>{letter.sender_name || letter.from}</span>
                                                    {letter.sender_email && (
                                                        <span className="text-sm text-gray-500">
                                                            {letter.sender_email}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Chip
                                                    color={getStatusColor(letter.status)}
                                                    size="sm"
                                                    variant="flat"
                                                >
                                                    {letter.status}
                                                </Chip>
                                            </TableCell>
                                            <TableCell>
                                                <Chip
                                                    color={getPriorityColor(letter.priority)}
                                                    size="sm"
                                                    variant="flat"
                                                >
                                                    {letter.priority}
                                                </Chip>
                                            </TableCell>
                                            <TableCell>
                                                <Chip size="sm" variant="bordered">
                                                    {letter.category}
                                                </Chip>
                                            </TableCell>
                                            <TableCell>
                                                {new Date(letter.received_date).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex gap-2">
                                                    <Tooltip content="View Details">
                                                        <Button
                                                            size="sm"
                                                            variant="light"
                                                            isIconOnly
                                                            onPress={() => {
                                                                setSelectedLetter(letter);
                                                                setShowDetailModal(true);
                                                            }}
                                                        >
                                                            <EyeIcon className="w-4 h-4" />
                                                        </Button>
                                                    </Tooltip>

                                                    <Dropdown>
                                                        <DropdownTrigger>
                                                            <Button size="sm" variant="light" isIconOnly>
                                                                <PencilIcon className="w-4 h-4" />
                                                            </Button>
                                                        </DropdownTrigger>
                                                        <DropdownMenu>
                                                            <DropdownItem
                                                                onPress={() => handleStatusChange(letter.id, 'read')}
                                                            >
                                                                Mark as Read
                                                            </DropdownItem>
                                                            <DropdownItem
                                                                onPress={() => handleStatusChange(letter.id, 'processed')}
                                                            >
                                                                Mark as Processed
                                                            </DropdownItem>
                                                            <DropdownItem
                                                                onPress={() => handleStatusChange(letter.id, 'archived')}
                                                            >
                                                                Archive
                                                            </DropdownItem>
                                                        </DropdownMenu>
                                                    </Dropdown>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}

                        {totalPages > 1 && (
                            <div className="flex justify-center mt-6">
                                <Pagination
                                    total={totalPages}
                                    page={currentPage}
                                    onChange={setCurrentPage}
                                />
                            </div>
                        )}
                    </CardBody>
                </Card>

                {/* Create Letter Modal */}
                <Modal
                    isOpen={showCreateModal}
                    onClose={() => setShowCreateModal(false)}
                    size="4xl"
                    scrollBehavior="inside"
                >
                    <ModalContent>
                        <ModalHeader>Create New Letter</ModalHeader>
                        <ModalBody>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Input
                                    label="From"
                                    value={formData.from}
                                    onChange={(e) => setFormData({...formData, from: e.target.value})}
                                    required
                                />
                                <Input
                                    label="Sender Name"
                                    value={formData.sender_name}
                                    onChange={(e) => setFormData({...formData, sender_name: e.target.value})}
                                />
                                <Input
                                    label="Sender Email"
                                    type="email"
                                    value={formData.sender_email}
                                    onChange={(e) => setFormData({...formData, sender_email: e.target.value})}
                                />
                                <Input
                                    label="Sender Phone"
                                    value={formData.sender_phone}
                                    onChange={(e) => setFormData({...formData, sender_phone: e.target.value})}
                                />
                                <Input
                                    label="Recipient"
                                    value={formData.recipient}
                                    onChange={(e) => setFormData({...formData, recipient: e.target.value})}
                                />
                                <Select
                                    label="Priority"
                                    selectedKeys={[formData.priority]}
                                    onSelectionChange={(keys) => setFormData({...formData, priority: [...keys][0]})}
                                >
                                    {priorityOptions.slice(1).map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </Select>
                                <Select
                                    label="Category"
                                    selectedKeys={[formData.category]}
                                    onSelectionChange={(keys) => setFormData({...formData, category: [...keys][0]})}
                                >
                                    {categoryOptions.slice(1).map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </Select>
                                <Input
                                    label="Received Date"
                                    type="date"
                                    value={formData.received_date}
                                    onChange={(e) => setFormData({...formData, received_date: e.target.value})}
                                    required
                                />
                            </div>

                            <Input
                                label="Subject"
                                value={formData.subject}
                                onChange={(e) => setFormData({...formData, subject: e.target.value})}
                                required
                                className="mt-4"
                            />

                            <Textarea
                                label="Content"
                                value={formData.content}
                                onChange={(e) => setFormData({...formData, content: e.target.value})}
                                rows={6}
                                className="mt-4"
                            />

                            <div className="flex gap-4 mt-4">
                                <Checkbox
                                    isSelected={formData.need_reply}
                                    onValueChange={(checked) => setFormData({...formData, need_reply: checked})}
                                >
                                    Needs Reply
                                </Checkbox>
                                <Checkbox
                                    isSelected={formData.need_forward}
                                    onValueChange={(checked) => setFormData({...formData, need_forward: checked})}
                                >
                                    Needs Forward
                                </Checkbox>
                                <Checkbox
                                    isSelected={formData.confidential}
                                    onValueChange={(checked) => setFormData({...formData, confidential: checked})}
                                >
                                    Confidential
                                </Checkbox>
                            </div>
                        </ModalBody>
                        <ModalFooter>
                            <Button variant="flat" onPress={() => setShowCreateModal(false)}>
                                Cancel
                            </Button>
                            <Button color="primary" onPress={handleCreateLetter}>
                                Create Letter
                            </Button>
                        </ModalFooter>
                    </ModalContent>
                </Modal>

                {/* Letter Detail Modal */}
                <Modal
                    isOpen={showDetailModal}
                    onClose={() => setShowDetailModal(false)}
                    size="5xl"
                    scrollBehavior="inside"
                >
                    <ModalContent>
                        <ModalHeader>
                            <div className="flex items-center gap-3">
                                <span>{selectedLetter?.subject}</span>
                                <Chip color={getStatusColor(selectedLetter?.status)} size="sm">
                                    {selectedLetter?.status}
                                </Chip>
                                <Chip color={getPriorityColor(selectedLetter?.priority)} size="sm">
                                    {selectedLetter?.priority}
                                </Chip>
                            </div>
                        </ModalHeader>
                        <ModalBody>
                            {selectedLetter && (
                                <div className="space-y-6">
                                    {/* Letter Details */}
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <h4 className="font-semibold mb-2">Sender Information</h4>
                                            <p><strong>From:</strong> {selectedLetter.from}</p>
                                            {selectedLetter.sender_name && (
                                                <p><strong>Name:</strong> {selectedLetter.sender_name}</p>
                                            )}
                                            {selectedLetter.sender_email && (
                                                <p><strong>Email:</strong> {selectedLetter.sender_email}</p>
                                            )}
                                            {selectedLetter.sender_phone && (
                                                <p><strong>Phone:</strong> {selectedLetter.sender_phone}</p>
                                            )}
                                            {selectedLetter.sender_address && (
                                                <p><strong>Address:</strong> {selectedLetter.sender_address}</p>
                                            )}
                                        </div>

                                        <div>
                                            <h4 className="font-semibold mb-2">Letter Details</h4>
                                            <p><strong>Reference:</strong> {selectedLetter.reference_number}</p>
                                            <p><strong>Category:</strong> {selectedLetter.category}</p>
                                            <p><strong>Received:</strong> {new Date(selectedLetter.received_date).toLocaleString()}</p>
                                            {selectedLetter.due_date && (
                                                <p><strong>Due:</strong> {new Date(selectedLetter.due_date).toLocaleString()}</p>
                                            )}
                                        </div>
                                    </div>

                                    {/* Content */}
                                    <div>
                                        <h4 className="font-semibold mb-2">Content</h4>
                                        <div className="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg whitespace-pre-wrap">
                                            {selectedLetter.content}
                                        </div>
                                    </div>

                                    {/* Attachments */}
                                    {selectedLetter.attachments && selectedLetter.attachments.length > 0 && (
                                        <div>
                                            <h4 className="font-semibold mb-2">Attachments</h4>
                                            <div className="space-y-2">
                                                {selectedLetter.attachments.map((attachment, index) => (
                                                    <div key={index} className="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded">
                                                        <span>{attachment.filename}</span>
                                                        <Button
                                                            size="sm"
                                                            variant="flat"
                                                            startContent={<DocumentArrowDownIcon className="w-4 h-4" />}
                                                            onPress={() => downloadAttachment(selectedLetter.id, index)}
                                                        >
                                                            Download
                                                        </Button>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Reply Section */}
                                    {selectedLetter.need_reply && !selectedLetter.replied_status && (
                                        <div>
                                            <h4 className="font-semibold mb-2">Send Reply</h4>
                                            <Textarea
                                                placeholder="Enter your reply..."
                                                value={replyContent}
                                                onChange={(e) => setReplyContent(e.target.value)}
                                                rows={4}
                                            />
                                            <Button
                                                color="primary"
                                                className="mt-2"
                                                startContent={<PaperAirplaneIcon className="w-4 h-4" />}
                                                onPress={handleSendReply}
                                            >
                                                Send Reply
                                            </Button>
                                        </div>
                                    )}

                                    {/* Reply History */}
                                    {selectedLetter.replied_status && selectedLetter.reply_content && (
                                        <div>
                                            <h4 className="font-semibold mb-2">Reply Sent</h4>
                                            <div className="bg-green-50 dark:bg-green-900 p-4 rounded-lg">
                                                <p className="text-sm text-green-700 dark:text-green-300">
                                                    Replied on {new Date(selectedLetter.reply_date).toLocaleString()}
                                                </p>
                                                <div className="mt-2 whitespace-pre-wrap">
                                                    {selectedLetter.reply_content}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </ModalBody>
                        <ModalFooter>
                            <Button variant="flat" onPress={() => setShowDetailModal(false)}>
                                Close
                            </Button>
                        </ModalFooter>
                    </ModalContent>
                </Modal>
            </div>
        </>
    );
}