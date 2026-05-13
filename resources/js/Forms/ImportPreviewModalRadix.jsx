import {
    closestCorners,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useDroppable,
    useSensor,
    useSensors,
} from "@dnd-kit/core";
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import * as Dialog from "@radix-ui/react-dialog";
import {
    CheckIcon,
    ChevronDownIcon,
    Cross2Icon,
    DragHandleDots2Icon,
    ExclamationTriangleIcon,
    FileTextIcon,
    LockClosedIcon,
    PersonIcon,
    ReloadIcon,
    UploadIcon
} from "@radix-ui/react-icons";
import * as Tabs from "@radix-ui/react-tabs";
import React, { useCallback, useEffect, useMemo, useState } from "react";

/* ─────────────────────────── CONSTANTS ─────────────────────────── */

const TYPE_META = {
  Structure: { color: "#3B82F6", bg: "#EFF6FF", label: "Structure" },
  Embankment: { color: "#D97706", bg: "#FFFBEB", label: "Embankment" },
  Pavement: { color: "#6366F1", bg: "#EEF2FF", label: "Pavement" },
};

const STATUS_META = {
  new: { label: "New", color: "#059669", bg: "#ECFDF5" },
  resubmission: { label: "Resub", color: "#D97706", bg: "#FFFBEB" },
  skipped: { label: "Skipped", color: "#DC2626", bg: "#FEF2F2" },
};

const UNASSIGNED_KEY = "__unassigned__";

const COLUMN_COLORS = [
  { border: "#C7D2FE", bg: "#EEF2FF", accent: "#6366F1", light: "#F5F3FF" },
  { border: "#BBF7D0", bg: "#F0FDF4", accent: "#16A34A", light: "#F0FDF4" },
  { border: "#FDE68A", bg: "#FFFBEB", accent: "#D97706", light: "#FEFCE8" },
  { border: "#FBCFE8", bg: "#FDF2F8", accent: "#DB2777", light: "#FDF4FF" },
  { border: "#BAE6FD", bg: "#F0F9FF", accent: "#0284C7", light: "#F0F9FF" },
  { border: "#D9F99D", bg: "#F7FEE7", accent: "#65A30D", light: "#F7FEE7" },
];

/* ─────────────────────────── HELPERS ─────────────────────────── */

function buildInitialState(previewData) {
  if (!previewData?.sheets) return [];
  return previewData.sheets.map((sheet) => {
    const assignments = {};
    (previewData.incharges || []).forEach((ic) => {
      assignments[String(ic.id)] = [];
    });
    Object.entries(sheet.by_incharge || {}).forEach(([inchargeId, works]) => {
      const key = String(inchargeId);
      if (!assignments[key]) assignments[key] = [];
      assignments[key].push(
        ...works.map((w) => ({ ...w, _originIncharge: w.auto_incharge ?? Number(inchargeId) }))
      );
    });
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

function findContainer(assignments, workId) {
  return Object.keys(assignments).find((key) =>
    (assignments[key] || []).some((w) => w.rfi_number === workId)
  );
}

function getInitials(name = "") {
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();
}

/* ─────────────────────────── RFI CARD ─────────────────────────── */

const RfiCard = React.memo(function RfiCard({ work, isOverlay = false, isDragActive = false }) {
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
    transition: transition || "transform 150ms ease",
    opacity: isDragging && !isOverlay ? 0 : 1,
    willChange: "transform",
  };

  const status = STATUS_META[work.status] || STATUS_META.new;
  const typeMeta = TYPE_META[work.type];
  const wasMoved =
    work._originIncharge != null &&
    work._currentIncharge != null &&
    Number(work._originIncharge) !== Number(work._currentIncharge);

  return (
    <div
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      data-dragging={isDragging}
      style={{
        ...style,
        background: "#fff",
        border: wasMoved ? "1.5px solid #6366F1" : "1px solid #E5E7EB",
        borderRadius: 10,
        padding: "10px 12px",
        cursor: isOverlay ? "grabbing" : "grab",
        userSelect: "none",
        position: "relative",
        boxShadow: isOverlay
          ? "0 16px 40px rgba(0,0,0,0.15), 0 4px 12px rgba(99,102,241,0.2)"
          : wasMoved
          ? "0 1px 4px rgba(99,102,241,0.15)"
          : "0 1px 2px rgba(0,0,0,0.04)",
        transition: isOverlay ? "none" : style.transition,
        transform: isOverlay ? "rotate(1.5deg) scale(1.03)" : style.transform,
        opacity: isDragging && !isOverlay ? 0 : 1,
      }}
    >
      {wasMoved && (
        <span
          style={{
            position: "absolute",
            top: -4,
            right: -4,
            width: 10,
            height: 10,
            background: "#6366F1",
            borderRadius: "50%",
            border: "2px solid white",
            boxShadow: "0 0 0 1px #6366F1",
          }}
        />
      )}

      <div style={{ display: "flex", alignItems: "flex-start", gap: 8, marginBottom: 8 }}>
        <div
          style={{
            fontSize: 11,
            fontWeight: 600,
            fontFamily: "monospace",
            color: "#111827",
            flex: 1,
            minWidth: 0,
            overflow: "hidden",
            textOverflow: "ellipsis",
            whiteSpace: "nowrap",
            lineHeight: "20px",
          }}
        >
          {work.rfi_number || "—"}
        </div>
        <span
          style={{
            fontSize: 10,
            fontWeight: 600,
            color: status.color,
            background: status.bg,
            padding: "2px 6px",
            borderRadius: 4,
            whiteSpace: "nowrap",
            flexShrink: 0,
          }}
        >
          {status.label}
        </span>
      </div>

      <div
        style={{
          display: "flex",
          alignItems: "center",
          gap: 4,
          marginBottom: 6,
          color: "#6B7280",
          fontSize: 11,
        }}
      >
        <PersonIcon style={{ width: 11, height: 11, flexShrink: 0 }} />
        <span
          style={{
            overflow: "hidden",
            textOverflow: "ellipsis",
            whiteSpace: "nowrap",
          }}
        >
          {work.location || "—"}
        </span>
      </div>

      <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {typeMeta && (
          <span
            style={{
              fontSize: 10,
              fontWeight: 600,
              color: typeMeta.color,
              background: typeMeta.bg,
              padding: "2px 6px",
              borderRadius: 4,
            }}
          >
            {typeMeta.label}
          </span>
        )}
        {work.description && (
          <span
            style={{
              fontSize: 10,
              color: "#9CA3AF",
              overflow: "hidden",
              textOverflow: "ellipsis",
              whiteSpace: "nowrap",
              flex: 1,
            }}
          >
            {work.description}
          </span>
        )}
      </div>
    </div>
  );
});

/* ─────────────────────────── COLUMN ─────────────────────────── */

function InchargeColumn({ id, title, subtitle, works, isUnassigned = false, colorScheme }) {
  const { setNodeRef, isOver } = useDroppable({ id });

  const counts = useMemo(() => {
    const c = { new: 0, resubmission: 0, skipped: 0 };
    works.forEach((w) => { if (c[w.status] != null) c[w.status]++; });
    return c;
  }, [works]);

  const initials = getInitials(title);

  const scheme = isUnassigned
    ? { border: "#FCA5A5", bg: "#FEF2F2", accent: "#DC2626", light: "#FFF5F5" }
    : colorScheme || COLUMN_COLORS[0];

  return (
    <div
      style={{
        display: "flex",
        flexDirection: "column",
        width: 268,
        flexShrink: 0,
        borderRadius: 12,
        border: `1.5px solid ${isOver ? scheme.accent : scheme.border}`,
        background: scheme.light,
        boxShadow: isOver
          ? `0 0 0 3px ${scheme.accent}22, 0 4px 16px ${scheme.accent}15`
          : "0 1px 3px rgba(0,0,0,0.06)",
        transition: "border-color 120ms ease, box-shadow 120ms ease",
        overflow: "hidden",
      }}
    >
      {/* Column Header */}
      <div
        style={{
          padding: "12px 14px",
          borderBottom: `1px solid ${scheme.border}`,
          background: isOver ? `${scheme.accent}08` : "transparent",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          {isUnassigned ? (
            <div
              style={{
                width: 32,
                height: 32,
                borderRadius: 8,
                background: "#FEE2E2",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                flexShrink: 0,
              }}
            >
              <ExclamationTriangleIcon style={{ width: 16, height: 16, color: "#DC2626" }} />
            </div>
          ) : (
            <div
              style={{
                width: 32,
                height: 32,
                borderRadius: 8,
                background: scheme.bg,
                border: `1px solid ${scheme.border}`,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                fontSize: 11,
                fontWeight: 700,
                color: scheme.accent,
                flexShrink: 0,
                letterSpacing: "0.03em",
              }}
            >
              {initials}
            </div>
          )}

          <div style={{ flex: 1, minWidth: 0 }}>
            <div
              style={{
                fontSize: 13,
                fontWeight: 600,
                color: isUnassigned ? "#DC2626" : "#111827",
                overflow: "hidden",
                textOverflow: "ellipsis",
                whiteSpace: "nowrap",
              }}
            >
              {title}
            </div>
            {subtitle && (
              <div
                style={{
                  fontSize: 10,
                  color: "#9CA3AF",
                  overflow: "hidden",
                  textOverflow: "ellipsis",
                  whiteSpace: "nowrap",
                  marginTop: 1,
                }}
              >
                {subtitle}
              </div>
            )}
          </div>

          <span
            style={{
              fontSize: 12,
              fontWeight: 700,
              color: scheme.accent,
              background: scheme.bg,
              border: `1px solid ${scheme.border}`,
              padding: "2px 7px",
              borderRadius: 20,
              flexShrink: 0,
            }}
          >
            {works.length}
          </span>
        </div>

        {(counts.new > 0 || counts.resubmission > 0) && (
          <div style={{ display: "flex", gap: 6, marginTop: 8 }}>
            {counts.new > 0 && (
              <span
                style={{
                  fontSize: 10,
                  fontWeight: 600,
                  color: "#059669",
                  background: "#ECFDF5",
                  padding: "2px 7px",
                  borderRadius: 20,
                }}
              >
                {counts.new} new
              </span>
            )}
            {counts.resubmission > 0 && (
              <span
                style={{
                  fontSize: 10,
                  fontWeight: 600,
                  color: "#D97706",
                  background: "#FFFBEB",
                  padding: "2px 7px",
                  borderRadius: 20,
                }}
              >
                {counts.resubmission} resub
              </span>
            )}
          </div>
        )}
      </div>

      {/* Drop Zone */}
      <div
        ref={setNodeRef}
        style={{
          flex: 1,
          padding: 10,
          display: "flex",
          flexDirection: "column",
          gap: 8,
          overflowY: "auto",
          minHeight: 180,
          maxHeight: 520,
        }}
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
          <div
            style={{
              flex: 1,
              display: "flex",
              flexDirection: "column",
              alignItems: "center",
              justifyContent: "center",
              gap: 6,
              padding: "24px 12px",
              border: `1.5px dashed ${isOver ? scheme.accent : scheme.border}`,
              borderRadius: 8,
              background: isOver ? `${scheme.accent}06` : "transparent",
              transition: "all 120ms ease",
            }}
          >
            <DragHandleDots2Icon
              style={{ width: 20, height: 20, color: isOver ? scheme.accent : "#D1D5DB" }}
            />
            <span
              style={{
                fontSize: 11,
                color: isOver ? scheme.accent : "#9CA3AF",
                fontWeight: 500,
              }}
            >
              {isOver ? "Release to assign" : "Drop RFIs here"}
            </span>
          </div>
        )}
      </div>
    </div>
  );
}

/* ─────────────────────────── SKIPPED SECTION ─────────────────────────── */

function SkippedSection({ skippedList, getInchargeName }) {
  const [open, setOpen] = useState(false);
  if (!skippedList?.length) return null;

  return (
    <div
      style={{
        marginTop: 16,
        border: "1px solid #E5E7EB",
        borderRadius: 10,
        overflow: "hidden",
        background: "#FAFAFA",
      }}
    >
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        style={{
          width: "100%",
          padding: "12px 16px",
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          background: "none",
          border: "none",
          cursor: "pointer",
          gap: 10,
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <LockClosedIcon style={{ width: 14, height: 14, color: "#9CA3AF" }} />
          <span style={{ fontSize: 13, fontWeight: 600, color: "#374151" }}>
            Skipped — Already Completed Pass/Fail
          </span>
          <span
            style={{
              fontSize: 11,
              fontWeight: 600,
              color: "#DC2626",
              background: "#FEE2E2",
              padding: "2px 7px",
              borderRadius: 20,
            }}
          >
            {skippedList.length}
          </span>
        </div>
        <ChevronDownIcon
          style={{
            width: 14,
            height: 14,
            color: "#9CA3AF",
            transform: open ? "rotate(180deg)" : "none",
            transition: "transform 200ms ease",
          }}
        />
      </button>

      {open && (
        <div
          style={{
            padding: "8px 12px 12px",
            borderTop: "1px solid #E5E7EB",
            display: "grid",
            gridTemplateColumns: "repeat(auto-fill, minmax(200px, 1fr))",
            gap: 8,
          }}
        >
          {skippedList.map((w) => (
            <div
              key={w.rfi_number}
              style={{
                border: "1px solid #E5E7EB",
                borderRadius: 8,
                padding: "8px 10px",
                background: "#fff",
                opacity: 0.75,
              }}
            >
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "space-between",
                  marginBottom: 4,
                }}
              >
                <span
                  style={{
                    fontFamily: "monospace",
                    fontSize: 11,
                    fontWeight: 600,
                    color: "#111827",
                  }}
                >
                  {w.rfi_number}
                </span>
                <CheckIcon style={{ width: 13, height: 13, color: "#059669" }} />
              </div>
              <div style={{ fontSize: 10, color: "#9CA3AF" }}>{w.location}</div>
              <div style={{ fontSize: 10, color: "#C4C4C4", marginTop: 2 }}>
                Was: {getInchargeName(w.auto_incharge) || "—"}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ─────────────────────────── PROGRESS BAR ─────────────────────────── */

function ProgressBar({ value }) {
  return (
    <div
      style={{
        height: 3,
        background: "#E5E7EB",
        borderRadius: 2,
        overflow: "hidden",
        width: "100%",
      }}
    >
      <div
        style={{
          height: "100%",
          width: `${value}%`,
          background: "linear-gradient(90deg, #6366F1, #818CF8)",
          borderRadius: 2,
          transition: "width 300ms ease",
        }}
      />
    </div>
  );
}

/* ─────────────────────────── STAT PILL ─────────────────────────── */

function StatPill({ label, value, color = "#6B7280", bg = "#F3F4F6" }) {
  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: 5,
        padding: "4px 10px",
        borderRadius: 20,
        background: bg,
        border: `1px solid ${color}22`,
      }}
    >
      <span style={{ fontSize: 11, fontWeight: 700, color }}>{value}</span>
      <span style={{ fontSize: 10, color: "#9CA3AF", fontWeight: 500 }}>{label}</span>
    </div>
  );
}

/* ─────────────────────────── MAIN MODAL ─────────────────────────── */

export default function ImportPreviewModal({
  isOpen,
  onClose,
  onCancel,
  previewData,
  onConfirm,
  isImporting,
  importProgress,
}) {
  const [sheetStates, setSheetStates] = useState(() => buildInitialState(previewData));
  const [activeSheet, setActiveSheet] = useState(0);
  const [activeWork, setActiveWork] = useState(null);

  useEffect(() => {
    setSheetStates(buildInitialState(previewData));
    setActiveSheet(0);
  }, [previewData]);

  const incharges = previewData?.incharges || [];
  const inchargeMap = useMemo(() => {
    const m = new Map();
    incharges.forEach((ic) => m.set(String(ic.id), ic));
    return m;
  }, [incharges]);

  const getInchargeName = useCallback(
    (id) => {
      if (!id) return null;
      const ic = inchargeMap.get(String(id));
      return ic?.name || `Incharge #${id}`;
    },
    [inchargeMap]
  );

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const currentSheet = sheetStates[activeSheet];
  const assignments = currentSheet?.assignments || {};
  const unassignedCount = (assignments[UNASSIGNED_KEY] || []).length;
  const totalUnassigned = sheetStates.reduce(
    (acc, s) => acc + (s.assignments[UNASSIGNED_KEY] || []).length,
    0
  );

  const handleDragStart = useCallback(
    (event) => {
      const sheet = sheetStates[activeSheet];
      for (const key of Object.keys(sheet.assignments)) {
        const found = sheet.assignments[key].find((w) => w.rfi_number === event.active.id);
        if (found) { setActiveWork(found); return; }
      }
    },
    [sheetStates, activeSheet]
  );

  const handleDragEnd = useCallback(
    (event) => {
      const { active, over } = event;
      setActiveWork(null);
      if (!over) return;

      setSheetStates((prev) => {
        const next = [...prev];
        const sheet = { ...next[activeSheet] };
        const a = { ...sheet.assignments };

        const fromContainer = findContainer(a, active.id);
        if (!fromContainer) return prev;

        let toContainer = String(over.id);
        if (!a[toContainer]) {
          const found = findContainer(a, over.id);
          if (found) toContainer = found;
        }
        if (!a[toContainer]) return prev;

        if (fromContainer === toContainer) {
          const list = [...a[fromContainer]];
          const oldIdx = list.findIndex((w) => w.rfi_number === active.id);
          const newIdx = list.findIndex((w) => w.rfi_number === over.id);
          if (oldIdx < 0 || newIdx < 0 || oldIdx === newIdx) return prev;
          const [moved] = list.splice(oldIdx, 1);
          list.splice(newIdx, 0, moved);
          a[fromContainer] = list;
        } else {
          const fromList = [...a[fromContainer]];
          const toList = [...a[toContainer]];
          const oldIdx = fromList.findIndex((w) => w.rfi_number === active.id);
          if (oldIdx < 0) return prev;
          const [moved] = fromList.splice(oldIdx, 1);
          moved._currentIncharge = toContainer === UNASSIGNED_KEY ? null : Number(toContainer);
          toList.unshift(moved);
          a[fromContainer] = fromList;
          a[toContainer] = toList;
        }

        sheet.assignments = a;
        next[activeSheet] = sheet;
        return next;
      });
    },
    [activeSheet]
  );

  const handleConfirm = useCallback(() => {
    const overrides = {};
    sheetStates.forEach((sheet) => {
      Object.entries(sheet.assignments).forEach(([containerId, works]) => {
        if (containerId === UNASSIGNED_KEY) return;
        const inchargeId = Number(containerId);
        works.forEach((w) => {
          const auto = w.auto_incharge ? Number(w.auto_incharge) : null;
          if (auto !== inchargeId) overrides[w.rfi_number] = inchargeId;
        });
      });
    });
    onConfirm(overrides);
  }, [sheetStates, onConfirm]);

  if (!previewData) return null;

  const blockConfirm = totalUnassigned > 0;

  return (
    <Dialog.Root
      open={isOpen}
      onOpenChange={(v) => { if (!v && !isImporting) onClose(); }}
    >
      <Dialog.Portal>
        <Dialog.Overlay
          style={{
            position: "fixed",
            inset: 0,
            background: "rgba(0,0,0,0.45)",
            backdropFilter: "blur(4px)",
            zIndex: 50,
            animation: "fadeIn 150ms ease",
          }}
        />
        <Dialog.Content
          style={{
            position: "fixed",
            inset: "16px",
            zIndex: 51,
            display: "flex",
            flexDirection: "column",
            background: "#fff",
            borderRadius: 16,
            boxShadow:
              "0 32px 80px rgba(0,0,0,0.18), 0 8px 24px rgba(0,0,0,0.1), 0 0 0 1px rgba(0,0,0,0.06)",
            overflow: "hidden",
            animation: "slideUp 200ms cubic-bezier(0.16, 1, 0.3, 1)",
            maxWidth: 1400,
            margin: "auto",
          }}
          aria-describedby="import-modal-desc"
        >
          <style>{`
            @keyframes fadeIn { from { opacity: 0 } to { opacity: 1 } }
            @keyframes slideUp { from { opacity: 0; transform: translateY(12px) scale(0.98) } to { opacity: 1; transform: none } }
            ::-webkit-scrollbar { width: 5px; height: 5px }
            ::-webkit-scrollbar-track { background: transparent }
            ::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 99px }
            ::-webkit-scrollbar-thumb:hover { background: #D1D5DB }
          `}</style>

          {/* ── Header ── */}
          <div
            style={{
              display: "flex",
              alignItems: "center",
              justifyContent: "space-between",
              padding: "16px 20px",
              borderBottom: "1px solid #F3F4F6",
              background: "#FAFAFA",
              flexShrink: 0,
            }}
          >
            <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
              <div
                style={{
                  width: 40,
                  height: 40,
                  borderRadius: 10,
                  background: "#EEF2FF",
                  border: "1px solid #C7D2FE",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  flexShrink: 0,
                }}
              >
                <FileTextIcon style={{ width: 18, height: 18, color: "#6366F1" }} />
              </div>
              <div>
                <Dialog.Title
                  style={{
                    fontSize: 15,
                    fontWeight: 700,
                    color: "#111827",
                    margin: 0,
                    lineHeight: 1.3,
                  }}
                >
                  Import Preview & Incharge Assignment
                </Dialog.Title>
                <p
                  id="import-modal-desc"
                  style={{
                    fontSize: 11,
                    color: "#9CA3AF",
                    margin: 0,
                    marginTop: 2,
                  }}
                >
                  Drag RFIs between columns to reassign before importing
                </p>
              </div>
            </div>

            <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
              <button
                onClick={() => setSheetStates(buildInitialState(previewData))}
                disabled={isImporting}
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 6,
                  padding: "6px 12px",
                  border: "1px solid #E5E7EB",
                  borderRadius: 8,
                  background: "#fff",
                  cursor: "pointer",
                  fontSize: 12,
                  fontWeight: 600,
                  color: "#6B7280",
                }}
              >
                <ReloadIcon style={{ width: 12, height: 12 }} />
                Reset
              </button>
              <Dialog.Close asChild>
                <button
                  onClick={onClose}
                  disabled={isImporting}
                  style={{
                    width: 32,
                    height: 32,
                    borderRadius: 8,
                    border: "1px solid #E5E7EB",
                    background: "#fff",
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    cursor: "pointer",
                    color: "#9CA3AF",
                  }}
                >
                  <Cross2Icon style={{ width: 14, height: 14 }} />
                </button>
              </Dialog.Close>
            </div>
          </div>

          {/* ── Sheet Tabs ── */}
          {sheetStates.length > 1 && (
            <Tabs.Root
              value={String(activeSheet)}
              onValueChange={(v) => setActiveSheet(Number(v))}
            >
              <Tabs.List
                style={{
                  display: "flex",
                  gap: 2,
                  padding: "8px 20px 0",
                  borderBottom: "1px solid #F3F4F6",
                  background: "#FAFAFA",
                  flexShrink: 0,
                }}
              >
                {sheetStates.map((s, idx) => (
                  <Tabs.Trigger
                    key={idx}
                    value={String(idx)}
                    style={{
                      padding: "7px 14px",
                      fontSize: 12,
                      fontWeight: 600,
                      borderRadius: "8px 8px 0 0",
                      border: "none",
                      cursor: "pointer",
                      background: String(activeSheet) === String(idx) ? "#fff" : "transparent",
                      color: String(activeSheet) === String(idx) ? "#6366F1" : "#9CA3AF",
                      borderBottom: String(activeSheet) === String(idx)
                        ? "2px solid #6366F1"
                        : "2px solid transparent",
                      transition: "all 150ms ease",
                      display: "flex",
                      alignItems: "center",
                      gap: 6,
                    }}
                  >
                    Sheet {s.sheet}
                    <span
                      style={{
                        fontSize: 10,
                        fontWeight: 700,
                        color: "#9CA3AF",
                        background: "#F3F4F6",
                        padding: "1px 6px",
                        borderRadius: 20,
                      }}
                    >
                      {s.date}
                    </span>
                  </Tabs.Trigger>
                ))}
              </Tabs.List>
            </Tabs.Root>
          )}

          {/* ── Stats Bar ── */}
          {currentSheet && (
            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 8,
                padding: "10px 20px",
                borderBottom: "1px solid #F3F4F6",
                background: "#FAFAFA",
                flexWrap: "wrap",
                flexShrink: 0,
              }}
            >
              <StatPill label="Total" value={currentSheet.stats.total} color="#6B7280" bg="#F3F4F6" />
              <StatPill label="New" value={currentSheet.stats.new} color="#059669" bg="#ECFDF5" />
              <StatPill label="Resubmissions" value={currentSheet.stats.resubmissions} color="#D97706" bg="#FFFBEB" />
              <StatPill label="Skipped" value={currentSheet.stats.skipped} color="#DC2626" bg="#FEF2F2" />

              {unassignedCount > 0 && (
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 5,
                    padding: "4px 10px",
                    borderRadius: 20,
                    background: "#DC2626",
                    marginLeft: "auto",
                  }}
                >
                  <ExclamationTriangleIcon style={{ width: 11, height: 11, color: "#fff" }} />
                  <span style={{ fontSize: 11, fontWeight: 700, color: "#fff" }}>
                    {unassignedCount} Unassigned
                  </span>
                </div>
              )}
            </div>
          )}

          {/* ── Kanban Board ── */}
          <div
            style={{
              flex: 1,
              overflow: "hidden",
              display: "flex",
              flexDirection: "column",
            }}
          >
            <div
              style={{
                flex: 1,
                overflowX: "auto",
                overflowY: "auto",
                padding: "16px 20px",
              }}
            >
              {currentSheet && (
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCorners}
                  onDragStart={handleDragStart}
                  onDragEnd={handleDragEnd}
                  onDragCancel={() => setActiveWork(null)}
                >
                  <div style={{ display: "flex", gap: 12, alignItems: "flex-start", minWidth: "max-content" }}>
                    {unassignedCount > 0 && (
                      <InchargeColumn
                        id={UNASSIGNED_KEY}
                        title="Unassigned"
                        subtitle="Jurisdiction not detected"
                        works={assignments[UNASSIGNED_KEY] || []}
                        isUnassigned
                      />
                    )}
                    {incharges.map((ic, idx) => (
                      <InchargeColumn
                        key={ic.id}
                        id={String(ic.id)}
                        title={ic.name}
                        subtitle={ic.designation}
                        works={assignments[String(ic.id)] || []}
                        colorScheme={COLUMN_COLORS[idx % COLUMN_COLORS.length]}
                      />
                    ))}
                  </div>

                  <DragOverlay dropAnimation={null}>
                    {activeWork ? <RfiCard work={activeWork} isOverlay /> : null}
                  </DragOverlay>
                </DndContext>
              )}

              {currentSheet && (
                <SkippedSection
                  skippedList={currentSheet.skippedList}
                  getInchargeName={getInchargeName}
                />
              )}
            </div>
          </div>

          {/* ── Footer ── */}
          <div
            style={{
              borderTop: "1px solid #F3F4F6",
              background: "#FAFAFA",
              flexShrink: 0,
            }}
          >
            {isImporting && (
              <div style={{ padding: "8px 20px 0" }}>
                <ProgressBar value={importProgress || 0} />
              </div>
            )}
            <div
              style={{
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                padding: "12px 20px",
                gap: 12,
              }}
            >
              <div style={{ fontSize: 11, color: blockConfirm ? "#DC2626" : "#9CA3AF", display: "flex", alignItems: "center", gap: 5 }}>
                {blockConfirm ? (
                  <>
                    <ExclamationTriangleIcon style={{ width: 13, height: 13 }} />
                    Resolve {totalUnassigned} unassigned RFI{totalUnassigned !== 1 ? "s" : ""} to proceed
                  </>
                ) : (
                  "Drag cards to correct assignments. Blue dot = reassigned."
                )}
              </div>

              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <button
                  onClick={onCancel}
                  disabled={isImporting}
                  style={{
                    padding: "8px 16px",
                    border: "1px solid #E5E7EB",
                    borderRadius: 8,
                    background: "#fff",
                    fontSize: 13,
                    fontWeight: 600,
                    color: "#6B7280",
                    cursor: "pointer",
                  }}
                >
                  Cancel
                </button>
                <button
                  onClick={handleConfirm}
                  disabled={blockConfirm || isImporting}
                  style={{
                    padding: "8px 18px",
                    border: "none",
                    borderRadius: 8,
                    background: blockConfirm || isImporting ? "#E5E7EB" : "#6366F1",
                    fontSize: 13,
                    fontWeight: 600,
                    color: blockConfirm || isImporting ? "#9CA3AF" : "#fff",
                    cursor: blockConfirm || isImporting ? "not-allowed" : "pointer",
                    display: "flex",
                    alignItems: "center",
                    gap: 7,
                    transition: "background 150ms ease",
                    boxShadow: blockConfirm || isImporting ? "none" : "0 2px 8px rgba(99,102,241,0.35)",
                  }}
                >
                  {isImporting ? (
                    <>
                      <ReloadIcon style={{ width: 13, height: 13, animation: "spin 1s linear infinite" }} />
                      Importing… {importProgress || 0}%
                    </>
                  ) : (
                    <>
                      <UploadIcon style={{ width: 13, height: 13 }} />
                      Confirm Import
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}