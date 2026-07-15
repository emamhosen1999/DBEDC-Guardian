import { Panel } from '@/Components/ui/Panel';
import React from 'react';
import { Box, Button, Separator, Badge } from '@radix-ui/themes';
import {
    ShieldExclamationIcon,
    MapPinIcon,
    LinkIcon,
    PencilIcon,
    DocumentArrowUpIcon,
    ClockIcon,
    CheckCircleIcon,
    TrashIcon,
    ChevronDownIcon,
} from '@heroicons/react/24/outline';
import { XCircleIcon as XCircleSolid } from '@heroicons/react/24/solid';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar';
import { statusConfig } from '../config/objectionUiConfig';

export default function ObjectionAccordionItem({
    objection,
    isExpanded,
    onToggle,
    getCategoryChip,
    getTypeChip,
    formatDate,
    openAttachModal,
    openEditModal,
    openStatusModal,
    openHistoryModal,
    handleDeleteObjection,
}) {
    const statusConf = statusConfig[objection.status] || statusConfig['draft'];

    return (
        <Panel
            style={{
                borderRadius: 'var(--radius-3)',
                border: objection.is_active
                    ? '1px solid var(--amber-a6)'
                    : '1px solid var(--gray-a4)',
                background: objection.is_active ? 'var(--amber-a2)' : undefined,
            }}
        >
            <Box
                p="3"
                style={{ cursor: 'pointer', userSelect: 'none' }}
                onClick={onToggle}
            >
                <div className="flex items-center justify-between w-full gap-2">
                    <div className="flex items-center gap-2 flex-1 min-w-0">
                        <ShieldExclamationIcon
                            className={`w-5 h-5 shrink-0 ${objection.is_active ? 'text-warning animate-pulse' : 'text-success'}`}
                        />
                        <div className="min-w-0 flex-1">
                            <h4 className="font-semibold text-sm truncate">{objection.title}</h4>
                            <p className="text-xs text-gray-500 truncate">
                                {objection.chainage_from && objection.chainage_to
                                    ? `${objection.chainage_from} - ${objection.chainage_to}`
                                    : 'No chainage set'}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <Badge color={statusConf.color} variant="soft" size="1">
                            {statusConf.label}
                        </Badge>
                        <div
                            className={`w-5 h-5 rounded-full flex items-center justify-center transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                            style={{ background: 'var(--gray-a3)' }}
                        >
                            <ChevronDownIcon className="w-3 h-3" />
                        </div>
                    </div>
                </div>
            </Box>

            {isExpanded && (
                    <div>
                        <Box px="3" pb="3" pt="0">
                            <Separator size="4" mb="3" />
                            <div className="space-y-2 text-xs">
                                <div className="flex justify-between items-center">
                                    <span className="text-gray-500">Category:</span>
                                    {getCategoryChip(objection.category)}
                                </div>
                                {objection.type && (
                                    <div className="flex justify-between items-center">
                                        <span className="text-gray-500">Type:</span>
                                        {getTypeChip(objection.type)}
                                    </div>
                                )}
                                <div className="flex justify-between items-center">
                                    <span className="text-gray-500">Chainage:</span>
                                    {objection.chainage_from && objection.chainage_to ? (
                                        <span>{objection.chainage_from} - {objection.chainage_to}</span>
                                    ) : (
                                        <span className="text-gray-500">Not set</span>
                                    )}
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-gray-500">Affected RFIs:</span>
                                    <Button size="1" variant="soft" onClick={() => openAttachModal(objection)}>
                                        {objection.daily_works_count || 0} RFI(s)
                                    </Button>
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-gray-500">Created By:</span>
                                    <FlexRowUser objection={objection} />
                                </div>
                                <div className="flex justify-between items-center">
                                    <span className="text-gray-500">Date:</span>
                                    <span>{formatDate(objection.created_at)}</span>
                                </div>
                            </div>
                            <MobileActions
                                objection={objection}
                                openAttachModal={openAttachModal}
                                openEditModal={openEditModal}
                                openStatusModal={openStatusModal}
                                openHistoryModal={openHistoryModal}
                                handleDeleteObjection={handleDeleteObjection}
                            />
                        </Box>
                    </div>
                )}
        </Panel>
    );
}

function FlexRowUser({ objection }) {
    return (
        <div className="flex items-center gap-1.5">
            <ProfileAvatar
                src={objection.created_by?.profile_image_url}
                name={objection.created_by?.name}
                size="xs"
            />
            <span>{objection.created_by?.name || 'Unknown'}</span>
        </div>
    );
}

function MobileActions({
    objection,
    openAttachModal,
    openEditModal,
    openStatusModal,
    openHistoryModal,
    handleDeleteObjection,
}) {
    return (
        <div className="flex flex-wrap gap-2 mt-4">
            <Button size="1" variant="soft" onClick={() => openAttachModal(objection)}>
                <LinkIcon className="w-3 h-3" /> Manage RFIs
            </Button>
            {objection.status === 'draft' && (
                <>
                    <Button size="1" variant="soft" color="gray" onClick={() => openEditModal(objection)}>
                        <PencilIcon className="w-3 h-3" /> Edit
                    </Button>
                    <Button size="1" variant="soft" onClick={() => openStatusModal(objection, 'submit')}>
                        <DocumentArrowUpIcon className="w-3 h-3" /> Submit
                    </Button>
                </>
            )}
            {objection.status === 'submitted' && (
                <Button size="1" variant="soft" color="amber" onClick={() => openStatusModal(objection, 'review')}>
                    <ClockIcon className="w-3 h-3" /> Review
                </Button>
            )}
            {objection.status === 'under_review' && (
                <>
                    <Button size="1" variant="soft" color="green" onClick={() => openStatusModal(objection, 'resolve')}>
                        <CheckCircleIcon className="w-3 h-3" /> Resolve
                    </Button>
                    <Button size="1" variant="soft" color="red" onClick={() => openStatusModal(objection, 'reject')}>
                        <XCircleSolid className="w-3 h-3" /> Reject
                    </Button>
                </>
            )}
            {objection.status_logs?.length > 0 && (
                <Button size="1" variant="soft" color="gray" onClick={() => openHistoryModal(objection)}>
                    <ClockIcon className="w-3 h-3" /> History
                </Button>
            )}
            {objection.status === 'draft' && (
                <Button size="1" variant="soft" color="red" onClick={() => handleDeleteObjection(objection)}>
                    <TrashIcon className="w-3 h-3" /> Delete
                </Button>
            )}
        </div>
    );
}
