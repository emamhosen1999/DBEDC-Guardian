import React, { useMemo, useState } from "react";
import {
    Modal,
    ModalContent,
    ModalHeader,
    ModalBody,
    ModalFooter,
    Button,
    Chip,
    Tooltip,
    Tabs,
    Tab,
} from "@heroui/react";
import {
    DocumentArrowUpIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    UserIcon,
    MapPinIcon,
    ArrowPathIcon,
    LockClosedIcon,
    CheckBadgeIcon,
} from "@heroicons/react/24/outline";
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    KeyboardSensor,
    useSensor,
    useSensors,
    closestCorners,
    useDroppable,
} from "@dnd-kit/core";
import {
    SortableContext,
    useSortable,
    verticalListSortingStrategy,
    sortableKeyboardCoordinates,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { getThemeRadius } from "@/Hooks/useThemeRadius.js";

const TYPE_COLORS = {
    Structure: "primary",
    Embankment: "warning",
    Pavement: "secondary",
};

const STATUS_META = {
    new: { label: "New", color: "success" },
    resubmission: { label: "Resubmission", color: "warning" },
    skipped: { label: "Skipped", color: "danger" },
};

const UNASSIGNED_KEY = "__unassigned__";

/**
 * Build initial assignment state from preview payload.
 * Returns: { sheets: [{ ...sheet, assignments: { [containerId]: workItem[] } }] }
 */
function buildInitialState(previewData) {
    if (!previewData?.sheets) return [];

    return previewData.sheets.map((sheet) => {
        const assignments = {};

        // Pre-populate every incharge container (even if empty) so they're drop targets
        (previewData.incharges || []).forEach((ic) => {
            assignments[String(ic.id)] = [];
        });

        // Place auto-assigned works under their auto incharge
        Object.entries(sheet.by_incharge || {}).forEach(([inchargeId, works]) => {
            const key = String(inchargeId);
            if (!assignments[key]) assignments[key] = [];
            assignments[key].push(
                ...works.map((w) => ({ ...w, _originIncharge: w.auto_incharge ?? Number(inchargeId) })),
            );
        });

        // Unassigned bucket
        assignments[UNASSIGNED_KEY] = (sheet.unassigned || []).map((w) => ({
            ...w,
            _originIncharge: null,
        }));

        return {
            sheet: sheet.sheet,
            date: sheet.date,
            stats: sheet.stats,
            skippedList: sheet.skipped_list || [],
            assignments,
        };
    });
}

/**
 * Find which container a work card lives in.
 */
function findContainer(assignments, workId) {
    return Object.keys(assignments).find((key) =>
        (assignments[key] || []).some((w) => w.rfi_number === workId),
    );
}

/* -------------------- Sortable RFI Card -------------------- */
function RfiCard({ work, isOverlay = false }) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: work.rfi_number });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging && !isOverlay ? 0.4 : 1,
    };

    const status = STATUS_META[work.status] || STATUS_META.new;
    const typeColor = TYPE_COLORS[work.type] || "default";
    const wasMoved = work._originIncharge != null && work._currentIncharge != null
        && Number(work._originIncharge) !== Number(work._currentIncharge);

    return (
        <div
            ref={setNodeRef}
            style={style}
            {...attributes}
            {...listeners}
            className={[
                "group relative cursor-grab active:cursor-grabbing select-none",
                "border rounded-lg p-3 bg-content1 hover:shadow-md transition-shadow",
                isOverlay ? "shadow-2xl ring-2 ring-primary" : "",
                wasMoved ? "border-primary/60" : "border-default-200",
            ].join(" ")}
        >
            {wasMoved && (
                <Tooltip content="Manually reassigned" placement="top">
                    <span className="absolute -top-1 -right-1 w-2.5 h-2.5 bg-primary rounded-full ring-2 ring-content1" />
                </Tooltip>
            )}
            <div className="flex items-start justify-between gap-2 mb-2">
                <div className="font-mono text-xs font-semibold text-foreground truncate">
                    {work.rfi_number || "—"}
                </div>
                <Chip size="sm" color={status.color} variant="flat" className="h-5 text-[10px]">
                    {status.label}
                </Chip>
            </div>
            <div className="flex items-center gap-1 text-xs text-default-600 mb-1.5">
                <MapPinIcon className="w-3 h-3 flex-shrink-0" />
                <span className="truncate">{work.location || "—"}</span>
            </div>
            <div className="flex items-center justify-between gap-2">
                <Chip size="sm" color={typeColor} variant="flat" className="h-5 text-[10px]">
                    {work.type || "—"}
                </Chip>
            </div>
            {work.description && (
                <Tooltip content={work.description} placement="top">
                    <div className="mt-1.5 text-[11px] text-default-500 line-clamp-1">
                        {work.description}
                    </div>
                </Tooltip>
            )}
        </div>
    );
}

/* -------------------- Droppable Incharge Column -------------------- */
function InchargeColumn({ id, title, subtitle, works, isUnassigned = false }) {
    const { setNodeRef, isOver } = useDroppable({ id });

    const counts = useMemo(() => {
        const c = { new: 0, resubmission: 0, skipped: 0 };
        works.forEach((w) => {
            if (c[w.status] != null) c[w.status]++;
        });
        return c;
    }, [works]);

    return (
        <div
            className={[
                "flex flex-col w-72 flex-shrink-0 rounded-lg border-2",
                isUnassigned
                    ? "bg-danger/5 border-danger/40"
                    : "bg-default-50 border-default-200",
                isOver ? "ring-2 ring-primary border-primary" : "",
            ].join(" ")}
            style={{ borderRadius: `var(--borderRadius, 8px)` }}
        >
            {/* Header */}
            <div className="p-3 border-b border-default-200">
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2 min-w-0">
                        {isUnassigned ? (
                            <ExclamationTriangleIcon className="w-4 h-4 text-danger flex-shrink-0" />
                        ) : (
                            <UserIcon className="w-4 h-4 text-default-500 flex-shrink-0" />
                        )}
                        <div className="min-w-0">
                            <div className={[
                                "text-sm font-semibold truncate",
                                isUnassigned ? "text-danger" : "text-foreground",
                            ].join(" ")}>
                                {title}
                            </div>
                            {subtitle && (
                                <div className="text-[11px] text-default-500 truncate">
                                    {subtitle}
                                </div>
                            )}
                        </div>
                    </div>
                    <Chip size="sm" variant="flat" color={isUnassigned ? "danger" : "default"} className="h-5 text-[10px]">
                        {works.length}
                    </Chip>
                </div>
                {(counts.new + counts.resubmission) > 0 && (
                    <div className="flex items-center gap-1.5 mt-2">
                        {counts.new > 0 && (
                            <Chip size="sm" variant="dot" color="success" className="h-5 text-[10px]">
                                {counts.new} new
                            </Chip>
                        )}
                        {counts.resubmission > 0 && (
                            <Chip size="sm" variant="dot" color="warning" className="h-5 text-[10px]">
                                {counts.resubmission} resub
                            </Chip>
                        )}
                    </div>
                )}
            </div>

            {/* Drop zone */}
            <div
                ref={setNodeRef}
                className="flex-1 p-2 space-y-2 overflow-y-auto min-h-[200px]"
            >
                <SortableContext
                    items={works.map((w) => w.rfi_number)}
                    strategy={verticalListSortingStrategy}
                >
                    {works.map((work) => (
                        <RfiCard key={work.rfi_number} work={work} />
                    ))}
                </SortableContext>
                {works.length === 0 && (
                    <div className="text-center py-8 text-xs text-default-400 italic">
                        Drop RFIs here
                    </div>
                )}
            </div>
        </div>
    );
}

/* -------------------- Skipped (Read-only) Section -------------------- */
function SkippedSection({ skippedList, getInchargeName }) {
    const [open, setOpen] = useState(false);

    if (!skippedList || skippedList.length === 0) return null;

    return (
        <div className="mt-4 border border-default-200 rounded-lg bg-default-50/50">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="w-full px-4 py-3 flex items-center justify-between gap-2 hover:bg-default-100 transition-colors rounded-t-lg"
            >
                <div className="flex items-center gap-2">
                    <LockClosedIcon className="w-4 h-4 text-default-500" />
                    <span className="text-sm font-semibold text-foreground">
                        Skipped (Already Completed Pass/Fail)
                    </span>
                    <Chip size="sm" variant="flat" color="danger" className="h-5 text-[10px]">
                        {skippedList.length}
                    </Chip>
                </div>
                <span className="text-xs text-default-500">
                    {open ? "Hide" : "Show"}
                </span>
            </button>
            {open && (
                <div className="p-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 border-t border-default-200">
                    {skippedList.map((w) => (
                        <div
                            key={w.rfi_number}
                            className="border border-default-200 rounded-md p-2 bg-content1 opacity-75"
                        >
                            <div className="flex items-center justify-between gap-2 mb-1">
                                <div className="font-mono text-xs font-semibold truncate">
                                    {w.rfi_number}
                                </div>
                                <CheckBadgeIcon className="w-4 h-4 text-success flex-shrink-0" />
                            </div>
                            <div className="text-[11px] text-default-500 truncate">
                                {w.location}
                            </div>
                            <div className="text-[10px] text-default-400 mt-1">
                                Was assigned to: {getInchargeName(w.auto_incharge) || "—"}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* -------------------- Main Modal -------------------- */
export default function ImportPreviewModal({
    isOpen,
    onClose,
    previewData,
    onConfirm,
    isImporting,
    importProgress,
}) {
    const [sheetStates, setSheetStates] = useState(() => buildInitialState(previewData));
    const [activeSheet, setActiveSheet] = useState(0);
    const [activeWork, setActiveWork] = useState(null); // for drag overlay

    // Re-init when previewData changes
    React.useEffect(() => {
        setSheetStates(buildInitialState(previewData));
        setActiveSheet(0);
    }, [previewData]);

    const incharges = previewData?.incharges || [];
    const inchargeMap = useMemo(() => {
        const m = new Map();
        incharges.forEach((ic) => m.set(String(ic.id), ic));
        return m;
    }, [incharges]);

    const getInchargeName = (id) => {
        if (!id) return null;
        const ic = inchargeMap.get(String(id));
        return ic?.name || `Incharge #${id}`;
    };

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const currentSheet = sheetStates[activeSheet];
    const assignments = currentSheet?.assignments || {};
    const unassignedCount = (assignments[UNASSIGNED_KEY] || []).length;
    const totalUnassignedAcrossSheets = sheetStates.reduce(
        (acc, s) => acc + (s.assignments[UNASSIGNED_KEY] || []).length,
        0,
    );

    const handleDragStart = (event) => {
        const id = event.active.id;
        const sheet = sheetStates[activeSheet];
        for (const key of Object.keys(sheet.assignments)) {
            const found = sheet.assignments[key].find((w) => w.rfi_number === id);
            if (found) {
                setActiveWork(found);
                return;
            }
        }
    };

    const handleDragEnd = (event) => {
        const { active, over } = event;
        setActiveWork(null);
        if (!over) return;

        setSheetStates((prev) => {
            const next = [...prev];
            const sheet = { ...next[activeSheet] };
            const a = { ...sheet.assignments };

            const fromContainer = findContainer(a, active.id);
            if (!fromContainer) return prev;

            // Determine target container - over.id might be a container id or a work id
            let toContainer = String(over.id);
            if (!a[toContainer]) {
                // dropped on a card; find its container
                const found = findContainer(a, over.id);
                if (found) toContainer = found;
            }
            if (!a[toContainer]) return prev;

            if (fromContainer === toContainer) {
                // Reorder within same column
                const list = [...a[fromContainer]];
                const oldIndex = list.findIndex((w) => w.rfi_number === active.id);
                const newIndex = list.findIndex((w) => w.rfi_number === over.id);
                if (oldIndex < 0 || newIndex < 0 || oldIndex === newIndex) return prev;
                const [moved] = list.splice(oldIndex, 1);
                list.splice(newIndex, 0, moved);
                a[fromContainer] = list;
            } else {
                // Move between columns
                const fromList = [...a[fromContainer]];
                const toList = [...a[toContainer]];
                const oldIndex = fromList.findIndex((w) => w.rfi_number === active.id);
                if (oldIndex < 0) return prev;
                const [moved] = fromList.splice(oldIndex, 1);
                // Track the new incharge for visual indicator
                moved._currentIncharge = toContainer === UNASSIGNED_KEY ? null : Number(toContainer);
                toList.unshift(moved);
                a[fromContainer] = fromList;
                a[toContainer] = toList;
            }

            sheet.assignments = a;
            next[activeSheet] = sheet;
            return next;
        });
    };

    const handleResetAll = () => {
        setSheetStates(buildInitialState(previewData));
    };

    const handleConfirmClick = () => {
        // Build overrides map: only include works whose container differs from their auto_incharge
        const overrides = {};
        sheetStates.forEach((sheet) => {
            Object.entries(sheet.assignments).forEach(([containerId, works]) => {
                if (containerId === UNASSIGNED_KEY) return; // shouldn't reach here if validated
                const inchargeId = Number(containerId);
                works.forEach((w) => {
                    const auto = w.auto_incharge ? Number(w.auto_incharge) : null;
                    if (auto !== inchargeId) {
                        overrides[w.rfi_number] = inchargeId;
                    }
                });
            });
        });
        onConfirm(overrides);
    };

    if (!previewData) return null;

    const blockConfirm = totalUnassignedAcrossSheets > 0;

    return (
        <Modal
            isOpen={isOpen}
            onClose={isImporting ? undefined : onClose}
            size="full"
            radius="none"
            scrollBehavior="inside"
            classNames={{
                base: "max-h-screen",
                backdrop: "bg-black/60 backdrop-blur-sm",
                header: "border-b border-divider",
                body: "p-0",
                footer: "border-t border-divider",
            }}
            style={{
                fontFamily: `var(--fontFamily, "Inter")`,
            }}
        >
            <ModalContent>
                {(close) => (
                    <>
                        <ModalHeader className="flex flex-col gap-2 px-6 py-3">
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <div
                                        className="p-2 rounded-lg"
                                        style={{
                                            background: `color-mix(in srgb, var(--theme-primary) 15%, transparent)`,
                                            color: "var(--theme-primary)",
                                        }}
                                    >
                                        <DocumentTextIcon className="w-5 h-5" />
                                    </div>
                                    <div>
                                        <h2 className="text-lg font-semibold text-foreground">
                                            Import Preview & Incharge Validation
                                        </h2>
                                        <p className="text-xs text-default-500">
                                            Drag RFIs between incharge columns to correct auto-detected assignments before importing
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    variant="light"
                                    startContent={<ArrowPathIcon className="w-4 h-4" />}
                                    onPress={handleResetAll}
                                    isDisabled={isImporting}
                                >
                                    Reset All
                                </Button>
                            </div>
                        </ModalHeader>

                        <ModalBody className="flex flex-col">
                            {/* Sheet tabs */}
                            {sheetStates.length > 1 && (
                                <div className="px-6 pt-3">
                                    <Tabs
                                        selectedKey={String(activeSheet)}
                                        onSelectionChange={(k) => setActiveSheet(Number(k))}
                                        variant="underlined"
                                        size="sm"
                                    >
                                        {sheetStates.map((s, idx) => (
                                            <Tab
                                                key={String(idx)}
                                                title={
                                                    <div className="flex items-center gap-2">
                                                        <span>Sheet {s.sheet}</span>
                                                        <Chip size="sm" variant="flat" className="h-5 text-[10px]">
                                                            {s.date}
                                                        </Chip>
                                                    </div>
                                                }
                                            />
                                        ))}
                                    </Tabs>
                                </div>
                            )}

                            {/* Stats bar */}
                            {currentSheet && (
                                <div className="px-6 py-3 flex flex-wrap items-center gap-3 border-b border-divider bg-default-50/50">
                                    <Chip size="sm" variant="flat" color="primary">
                                        Date: {currentSheet.date}
                                    </Chip>
                                    <Chip size="sm" variant="flat">
                                        Total: {currentSheet.stats.total}
                                    </Chip>
                                    <Chip size="sm" variant="flat" color="success">
                                        New: {currentSheet.stats.new}
                                    </Chip>
                                    <Chip size="sm" variant="flat" color="warning">
                                        Resubmissions: {currentSheet.stats.resubmissions}
                                    </Chip>
                                    <Chip size="sm" variant="flat" color="danger">
                                        Skipped: {currentSheet.stats.skipped}
                                    </Chip>
                                    {unassignedCount > 0 && (
                                        <Chip size="sm" variant="solid" color="danger" startContent={<ExclamationTriangleIcon className="w-3 h-3" />}>
                                            {unassignedCount} Unassigned
                                        </Chip>
                                    )}
                                </div>
                            )}

                            {/* Kanban board */}
                            <div className="flex-1 overflow-x-auto overflow-y-hidden px-6 py-4">
                                {currentSheet && (
                                    <DndContext
                                        sensors={sensors}
                                        collisionDetection={closestCorners}
                                        onDragStart={handleDragStart}
                                        onDragEnd={handleDragEnd}
                                        onDragCancel={() => setActiveWork(null)}
                                    >
                                        <div className="flex gap-3 h-full">
                                            {/* Unassigned (only render if has items) */}
                                            {unassignedCount > 0 && (
                                                <InchargeColumn
                                                    id={UNASSIGNED_KEY}
                                                    title="Unassigned"
                                                    subtitle="Jurisdiction not detected"
                                                    works={assignments[UNASSIGNED_KEY] || []}
                                                    isUnassigned
                                                />
                                            )}

                                            {/* All Supervision Engineers as columns */}
                                            {incharges.map((ic) => (
                                                <InchargeColumn
                                                    key={ic.id}
                                                    id={String(ic.id)}
                                                    title={ic.name}
                                                    subtitle={ic.designation}
                                                    works={assignments[String(ic.id)] || []}
                                                />
                                            ))}
                                        </div>

                                        <DragOverlay>
                                            {activeWork ? <RfiCard work={activeWork} isOverlay /> : null}
                                        </DragOverlay>
                                    </DndContext>
                                )}

                                {/* Skipped read-only section */}
                                {currentSheet && (
                                    <div className="px-0 mt-4">
                                        <SkippedSection
                                            skippedList={currentSheet.skippedList}
                                            getInchargeName={getInchargeName}
                                        />
                                    </div>
                                )}
                            </div>
                        </ModalBody>

                        <ModalFooter className="flex items-center justify-between gap-2 px-6 py-3">
                            <div className="text-xs text-default-500">
                                {blockConfirm ? (
                                    <span className="text-danger flex items-center gap-1">
                                        <ExclamationTriangleIcon className="w-4 h-4" />
                                        Assign all {totalUnassignedAcrossSheets} unassigned RFI(s) before importing
                                    </span>
                                ) : (
                                    <span>Drag-and-drop to reassign incharges. Moved cards have a blue dot.</span>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    color="default"
                                    variant="bordered"
                                    onPress={close}
                                    isDisabled={isImporting}
                                    radius={getThemeRadius()}
                                    size="sm"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    color="primary"
                                    onPress={handleConfirmClick}
                                    isLoading={isImporting}
                                    isDisabled={blockConfirm}
                                    radius={getThemeRadius()}
                                    size="sm"
                                    startContent={!isImporting ? <DocumentArrowUpIcon className="w-4 h-4" /> : null}
                                >
                                    {isImporting
                                        ? `Importing... ${importProgress || 0}%`
                                        : "Confirm Import"}
                                </Button>
                            </div>
                        </ModalFooter>
                    </>
                )}
            </ModalContent>
        </Modal>
    );
}
