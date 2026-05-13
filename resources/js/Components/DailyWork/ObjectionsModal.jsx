import React, { useState, useCallback, useEffect } from 'react';
import { Dialog, Button, Badge, Card, Box, Flex, Text, TextField, ScrollArea, Tabs, Callout, Checkbox, Separator } from '@radix-ui/themes';
import {
    ExclamationTriangleIcon,
    FileIcon,
    EyeOpenIcon,
    CrossCircledIcon,
    Link2Icon,
    MagnifyingGlassIcon,
    TargetIcon,
    FileTextIcon,
    ImageIcon,
    ExternalLinkIcon,
    InfoCircledIcon,
} from "@radix-ui/react-icons";
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import { router } from '@inertiajs/react';
import {
    STATUS_CONFIG,
    CATEGORY_CONFIG,
    getStatusConfig,
    getCategoryConfig,
} from '@/Config/objectionConfig';

// Category labels for display (mapped from shared config)
const CATEGORY_LABELS = Object.fromEntries(
    Object.entries(CATEGORY_CONFIG).map(([key, val]) => [key, val.label])
);

/**
 * ObjectionsModal - Modal for managing objections attached to an RFI/Daily Work
 * 
 * This modal shows:
 * 1. Already attached objections to this RFI
 * 2. Available objections that can be attached (based on chainage range)
 * 
 * To create new objections, users should use the Objections page.
 */
const ObjectionsModal = ({
    isOpen,
    onClose,
    dailyWork,
    onObjectionsUpdated,
}) => {
    const [activeTab, setActiveTab] = useState('attached');
    const [loading, setLoading] = useState(false);
    const [attachedObjections, setAttachedObjections] = useState([]);
    const [availableObjections, setAvailableObjections] = useState([]);
    const [selectedObjections, setSelectedObjections] = useState([]);
    const [searchTerm, setSearchTerm] = useState('');
    const [attaching, setAttaching] = useState(false);
    const [detaching, setDetaching] = useState(null);

    // Fetch objections when modal opens
    useEffect(() => {
        if (isOpen && dailyWork?.id) {
            fetchAttachedObjections();
            fetchAvailableObjections();
        }
    }, [isOpen, dailyWork?.id]);

    // Wrapped close handler
    const handleClose = useCallback(() => {
        onClose?.();
    }, [onClose]);

    // Reset state when modal closes
    useEffect(() => {
        if (!isOpen) {
            setActiveTab('attached');
            setSelectedObjections([]);
            setSearchTerm('');
        }
    }, [isOpen]);

    // Fetch objections attached to this RFI
    const fetchAttachedObjections = async () => {
        if (!dailyWork?.id) return;

        setLoading(true);
        try {
            const response = await axios.get(route('dailyWorks.objections.index', dailyWork.id));
            setAttachedObjections(response.data.objections || []);
        } catch (error) {
            console.error('Error fetching attached objections:', error);
            showToast.error('Failed to load attached objections');
        } finally {
            setLoading(false);
        }
    };

    // Fetch available objections that can be attached (based on chainage)
    const fetchAvailableObjections = async () => {
        if (!dailyWork?.id) return;

        try {
            // Fetch objections that could match this RFI's chainage
            const response = await axios.get(route('dailyWorks.objections.available', dailyWork.id));
            setAvailableObjections(response.data.objections || []);
        } catch (error) {
            console.error('Error fetching available objections:', error);
            // Don't show error for available - it's optional
        }
    };

    // Attach selected objections to this RFI
    const handleAttachObjections = async () => {
        if (selectedObjections.length === 0) {
            showToast.error('Please select at least one objection to attach');
            return;
        }

        setAttaching(true);
        try {
            const response = await axios.post(route('dailyWorks.objections.attach', dailyWork.id), {
                objection_ids: selectedObjections.map(id => parseInt(id)),
            });
            
            showToast.success(response.data.message || 'Objections attached successfully');
            setSelectedObjections([]);
            
            // Refresh lists
            await fetchAttachedObjections();
            await fetchAvailableObjections();
            
            // Switch to attached tab to show the newly attached objections
            setActiveTab('attached');
            
            // Use the active count returned from the API
            if (onObjectionsUpdated) {
                const newActiveCount = response.data.active_objections_count ?? attachedObjections.filter(o => 
                    ['draft', 'submitted', 'under_review'].includes(o.status)
                ).length + selectedObjections.length;
                onObjectionsUpdated(dailyWork.id, newActiveCount);
            }
        } catch (error) {
            console.error('Error attaching objections:', error);
            showToast.error(error.response?.data?.error || 'Failed to attach objections');
        } finally {
            setAttaching(false);
        }
    };

    // Detach an objection from this RFI
    const handleDetachObjection = async (objectionId) => {
        if (!confirm('Are you sure you want to detach this objection from this RFI?')) {
            return;
        }

        setDetaching(objectionId);
        try {
            const response = await axios.post(route('dailyWorks.objections.detach', dailyWork.id), {
                objection_ids: [objectionId],
            });
            
            showToast.success(response.data.message || 'Objection detached successfully');
            
            // Refresh lists
            await fetchAttachedObjections();
            await fetchAvailableObjections();
            
            // Calculate new active count
            const newActiveCount = response.data.active_objections_count ?? Math.max(0, (dailyWork.active_objections_count || 0) - 1);
            
            if (onObjectionsUpdated) {
                onObjectionsUpdated(dailyWork.id, newActiveCount);
            }
        } catch (error) {
            console.error('Error detaching objection:', error);
            showToast.error(error.response?.data?.error || 'Failed to detach objection');
        } finally {
            setDetaching(null);
        }
    };

    // Navigate to Objections page
    const goToObjectionsPage = () => {
        router.visit(route('objections.index'));
    };

    const renderStatusBadge = (status) => {
        const config = STATUS_CONFIG[status] || STATUS_CONFIG.draft;
        const Icon = config.icon;
        const colorMap = { success: 'green', warning: 'amber', danger: 'red', primary: 'indigo', secondary: 'violet', default: 'gray' };
        return (
            <Badge size="1" color={colorMap[config.color]||'gray'} variant="soft">
                <Icon style={{ width: 10, height: 10 }} /> {config.label}
            </Badge>
        );
    };

    const renderAttachedObjectionCard = (objection) => {
        const isActive = ['draft', 'submitted', 'under_review'].includes(objection.status);
        return (
            <Card key={objection.id} style={{ borderLeft: `4px solid ${isActive ? 'var(--amber-8)' : 'var(--green-8)'}`, marginBottom: 12 }}>
                <Box p="3">
                    <Flex justify="between" gap="2" mb="2">
                        <Box style={{ flex: 1, minWidth: 0 }}>
                            <Flex gap="1" wrap="wrap" mb="1">
                                {renderStatusBadge(objection.status)}
                                <Badge size="1" variant="outline" color="gray">{CATEGORY_LABELS[objection.category] || objection.category}</Badge>
                            </Flex>
                            <Text weight="bold" size="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{objection.title}</Text>
                            {objection.chainage_from && objection.chainage_to && (
                                <Flex align="center" gap="1" mt="1">
                                    <TargetIcon style={{ width: 12, height: 12, flexShrink: 0 }} />
                                    <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{objection.chainage_from} - {objection.chainage_to}</Text>
                                </Flex>
                            )}
                        </Box>
                        <Button size="1" variant="ghost" color="red" loading={detaching === objection.id} onClick={() => handleDetachObjection(objection.id)} title="Detach from this RFI">
                            <CrossCircledIcon style={{ width: 16, height: 16 }} />
                        </Button>
                    </Flex>
                    <Text size="1" color="gray" as="p" mb="1">Description:</Text>
                    <Text size="1" as="p" mb="2" style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{objection.description}</Text>
                    <Text size="1" color="gray" as="p" mb="1">Reason:</Text>
                    <Text size="1" as="p" mb="2" style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{objection.reason}</Text>
                    {objection.resolution_notes && (
                        <Box mb="2" p="2" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-1)' }}>
                            <Text size="1" color="gray" as="p" mb="1">{objection.status === 'resolved' ? 'Resolution:' : 'Rejection Reason:'}</Text>
                            <Text size="1" as="p">{objection.resolution_notes}</Text>
                        </Box>
                    )}
                    {objection.files?.length > 0 && (
                        <Box mb="2">
                            <Text size="1" color="gray" as="p" mb="1">Attachments ({objection.files.length}):</Text>
                            <Flex wrap="wrap" gap="2">
                                {objection.files.slice(0, 3).map((file) => (
                                    <Flex key={file.id} align="center" gap="1" p="1" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-1)' }}>
                                        {file.is_image ? <ImageIcon style={{ width: 12, height: 12 }} /> : <FileTextIcon style={{ width: 12, height: 12 }} />}
                                        <Text size="1" style={{ maxWidth: 80, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{file.name}</Text>
                                        <a href={file.url} target="_blank" rel="noopener noreferrer"><EyeOpenIcon style={{ width: 12, height: 12 }} /></a>
                                    </Flex>
                                ))}
                                {objection.files.length > 3 && <Text size="1" color="gray">+{objection.files.length - 3} more</Text>}
                            </Flex>
                        </Box>
                    )}
                    <Flex justify="between" align="center" pt="2" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                        <Flex align="center" gap="2">
                            <Box style={{ width: 20, height: 20, borderRadius: '50%', background: 'var(--accent-9)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                                <Text size="1" style={{ color: 'white', fontWeight: 'bold' }}>{(objection.created_by?.name || 'U')[0]}</Text>
                            </Box>
                            <Text size="1" color="gray">{objection.created_by?.name || 'Unknown'}</Text>
                        </Flex>
                        <Text size="1" color="gray">{new Date(objection.created_at).toLocaleDateString()}</Text>
                    </Flex>
                </Box>
            </Card>
        );
    };

    // Filter available objections by search term
    const filteredAvailableObjections = availableObjections.filter(obj => {
        if (!searchTerm) return true;
        const term = searchTerm.toLowerCase();
        return (
            obj.title?.toLowerCase().includes(term) ||
            obj.description?.toLowerCase().includes(term) ||
            obj.chainage_from?.toLowerCase().includes(term) ||
            obj.chainage_to?.toLowerCase().includes(term)
        );
    });

    // Count of active attached objections
    const activeCount = attachedObjections.filter(o => 
        ['draft', 'submitted', 'under_review'].includes(o.status)
    ).length;

    return (
        <Dialog.Root open={isOpen} onOpenChange={(v) => { if (!v) handleClose(); }}>
            <Dialog.Content maxWidth="720px">
                <Dialog.Title>
                    <Flex align="center" gap="2" wrap="wrap">
                        <ExclamationTriangleIcon style={{ width: 20, height: 20, flexShrink: 0, color: 'var(--amber-9)' }} />
                        <Text weight="bold" size="3" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>RFI Objections - {dailyWork?.number}</Text>
                        {activeCount > 0 && <Badge color="amber" variant="solid" size="1">{activeCount} Active</Badge>}
                    </Flex>
                </Dialog.Title>
                <Dialog.Description size="2" color="gray" mb="3">View attached objections or attach existing ones to this RFI</Dialog.Description>

                <Tabs.Root value={activeTab} onValueChange={setActiveTab}>
                    <Tabs.List mb="3">
                        <Tabs.Trigger value="attached">
                            <Flex align="center" gap="1">
                                <ExclamationTriangleIcon style={{ width: 12, height: 12 }} />
                                <Text size="2">Attached ({attachedObjections.length})</Text>
                            </Flex>
                        </Tabs.Trigger>
                        <Tabs.Trigger value="add">
                            <Flex align="center" gap="1">
                                <Link2Icon style={{ width: 12, height: 12 }} />
                                <Text size="2">Add Objection</Text>
                            </Flex>
                        </Tabs.Trigger>
                    </Tabs.List>

                    <Tabs.Content value="attached">
                        <Box py="3">
                            {loading ? (
                                <Flex justify="center" py="5"><Text color="gray" size="2">Loading...</Text></Flex>
                            ) : attachedObjections.length === 0 ? (
                                <Flex direction="column" align="center" py="6" gap="2" style={{ color: 'var(--gray-9)' }}>
                                    <ExclamationTriangleIcon style={{ width: 48, height: 48, opacity: 0.5 }} />
                                    <Text size="2" as="p">No objections attached to this RFI</Text>
                                    <Text size="1" color="gray" as="p">Switch to "Add Objection" tab to attach existing objections</Text>
                                </Flex>
                            ) : (
                                <ScrollArea style={{ maxHeight: 'min(50vh, 400px)' }}>
                                    <Flex direction="column" pr="2">{attachedObjections.map(renderAttachedObjectionCard)}</Flex>
                                </ScrollArea>
                            )}
                        </Box>
                    </Tabs.Content>

                    <Tabs.Content value="add">
                        <Box py="3">
                            <Flex direction="column" gap="3">
                                <Callout.Root color="blue" size="1">
                                    <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                                    <Callout.Text>
                                        Select from existing objections to attach. To create new ones, visit the{' '}
                                        <Button variant="ghost" size="1" onClick={goToObjectionsPage} style={{ padding: 0, height: 'auto', textDecoration: 'underline' }}>Objections page</Button>.
                                    </Callout.Text>
                                </Callout.Root>

                                <TextField.Root placeholder="Search objections..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} size="1">
                                    <TextField.Slot><MagnifyingGlassIcon style={{ width: 14, height: 14 }} /></TextField.Slot>
                                </TextField.Root>

                                {filteredAvailableObjections.length === 0 ? (
                                    <Flex direction="column" align="center" py="6" gap="2" style={{ color: 'var(--gray-9)' }}>
                                        <FileIcon style={{ width: 48, height: 48, opacity: 0.5 }} />
                                        <Text size="2" as="p">No available objections found</Text>
                                        <Text size="1" color="gray" as="p">Create objections on the Objections page first</Text>
                                        <Button variant="soft" size="1" mt="3" onClick={goToObjectionsPage}>
                                            <ExternalLinkIcon style={{ width: 14, height: 14 }} /> Go to Objections Page
                                        </Button>
                                    </Flex>
                                ) : (
                                    <>
                                        <Text size="1" color="gray">Select objections to attach ({selectedObjections.length} selected):</Text>
                                        <ScrollArea style={{ maxHeight: 'min(40vh,300px)' }}>
                                            <Flex direction="column" gap="2" pr="2">
                                                {filteredAvailableObjections.map((objection) => {
                                                    const isSelected = selectedObjections.includes(String(objection.id));
                                                    return (
                                                        <Card
                                                            key={objection.id}
                                                            variant={isSelected ? 'surface' : 'ghost'}
                                                            style={{ border: `1px solid ${isSelected ? 'var(--accent-9)' : 'var(--gray-a4)'}`, cursor: 'pointer', background: isSelected ? 'var(--accent-a3)' : undefined }}
                                                            onClick={() => setSelectedObjections(prev => prev.includes(String(objection.id)) ? prev.filter(x => x !== String(objection.id)) : [...prev, String(objection.id)])}
                                                        >
                                                            <Flex align="start" gap="2" p="2">
                                                                <Checkbox
                                                                    checked={isSelected}
                                                                    onCheckedChange={() => setSelectedObjections(prev => prev.includes(String(objection.id)) ? prev.filter(x => x !== String(objection.id)) : [...prev, String(objection.id)])}
                                                                    mt="1"
                                                                />
                                                                <Box style={{ flex: 1, minWidth: 0 }}>
                                                                    <Flex gap="1" wrap="wrap" mb="1">
                                                                        {renderStatusBadge(objection.status)}
                                                                        <Badge size="1" variant="outline" color="gray">{CATEGORY_LABELS[objection.category] || objection.category}</Badge>
                                                                    </Flex>
                                                                    <Text weight="medium" size="2" as="p">{objection.title}</Text>
                                                                    {objection.chainage_from && objection.chainage_to && (
                                                                        <Flex align="center" gap="1" mt="1">
                                                                            <TargetIcon style={{ width: 12, height: 12 }} />
                                                                            <Text size="1" color="gray">{objection.chainage_from} - {objection.chainage_to}</Text>
                                                                        </Flex>
                                                                    )}
                                                                    <Text size="1" color="gray" as="p" mt="1" style={{ display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{objection.description}</Text>
                                                                </Box>
                                                            </Flex>
                                                        </Card>
                                                    );
                                                })}
                                            </Flex>
                                        </ScrollArea>
                                        <Flex justify="end" pt="2">
                                            <Button color="indigo" loading={attaching} disabled={selectedObjections.length === 0} onClick={handleAttachObjections}>
                                                <Link2Icon style={{ width: 16, height: 16 }} />
                                                Attach{selectedObjections.length > 0 ? ` (${selectedObjections.length})` : ''} Objection{selectedObjections.length !== 1 ? 's' : ''}
                                            </Button>
                                        </Flex>
                                    </>
                                )}
                            </Flex>
                        </Box>
                    </Tabs.Content>
                </Tabs.Root>

                <Separator size="4" my="3" />

                <Flex justify="between" align="center" gap="2" wrap="wrap">
                    <Button variant="soft" color="gray" size="2" onClick={goToObjectionsPage}>
                        <ExternalLinkIcon style={{ width: 14, height: 14 }} />
                        Go to Objections Page
                    </Button>
                    <Button color="indigo" variant="soft" onClick={handleClose}>Close</Button>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
};

export default ObjectionsModal;
