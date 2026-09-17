import { Panel } from '@/Components/ui/Panel';
/**
 * BiometricPanel.jsx
 * Biometric Devices tab — sub-tabs: Devices | ADMS Logs | Webhook Config
 * Pure Radix UI.
 */
import React, { useState, useCallback, useEffect, useMemo } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Box, Button, Callout, Checkbox, Code, Dialog, Flex, Grid, IconButton, Progress, ScrollArea, Select, Separator, Spinner, Switch, Table, Tabs, Text, TextField, Tooltip } from '@radix-ui/themes';
import {
    ActivityLogIcon, ArrowRightIcon, CheckCircledIcon, ChevronDownIcon, ChevronLeftIcon,
    ChevronRightIcon, CopyIcon, Cross2Icon, DesktopIcon, DotsVerticalIcon,
    EnvelopeClosedIcon, GearIcon, InfoCircledIcon, Link2Icon, LockClosedIcon,
    LockOpen1Icon, MagnifyingGlassIcon, MixerHorizontalIcon, MobileIcon,
    Pencil1Icon, PlusIcon, ReloadIcon,
    TrashIcon, HeartIcon, CheckIcon, CrossCircledIcon, ExclamationTriangleIcon,
    DownloadIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import TablePagination from '@/Components/TablePagination.jsx';
import DateTimePicker from '@/Components/DateTimePicker';
import * as XLSX from 'xlsx';
import { jsPDF } from 'jspdf';
import 'jspdf-autotable';

const EMPTY_DEVICE = {
    name: '', serial_number: '', ip_address: '', location: '',
    model: '', protocol: 'push_sdk', is_active: true,
};

/**
 * A device can hold TWO unrelated secrets. They are not interchangeable and an
 * admin pasting one where the other belongs will silently break the integration,
 * so every place they surface must name which one it is.
 *
 *  - webhook → biometric_devices.auth_token — legacy Push SDK webhook
 *              (POST /api/biometric/webhook, X-Device-Token header).
 *  - adms    → biometric_devices.adms_token — ADMS push protocol
 *              (/iclock/*, ?token= or X-ADMS-Token), enforced by
 *              EnsureAdmsDeviceAuthorized.
 */
const TOKEN_META = {
    webhook: {
        title: 'Push SDK Auth Token',
        action: 'Regenerate Push SDK auth token (webhook)',
        note: <>Send this as the <Code>X-Device-Token</Code> header on <Code>/api/biometric/webhook</Code>. This is the Push SDK webhook token — <strong>not</strong> the ADMS device token.</>,
    },
    adms: {
        title: 'ADMS Device Token',
        action: 'Regenerate ADMS device token (/iclock)',
        note: <>Paste this into the device's ADMS push settings so it is sent as <Code>?token=</Code> or the <Code>X-ADMS-Token</Code> header on <Code>/iclock/*</Code>. This is the ADMS device token — <strong>not</strong> the Push SDK webhook token.</>,
    },
};

/** Normalise a DateTimePicker `datetime` value (YYYY-MM-DDTHH:MM) to YYYY-MM-DD HH:MM:SS. */
const toSqlDateTime = (val) => {
    const formatted = val.replace('T', ' ');
    return formatted.length === 16 ? `${formatted}:00` : formatted;
};

/**
 * Command status → badge.
 *
 * `unsupported` is deliberately NOT red and NOT worded as an error. It is set by
 * BiometricDeviceCommand::markAsExecuted() when the device acks with -1004
 * ("not supported on this model") or -1 ("unsupported or no data") — see
 * docs/zkteco-adms-capability-matrix.md §4. That is a permanent capability fact
 * about the hardware, not something anyone should retry or escalate.
 *
 * Keys mirror BiometricDeviceCommand::STATUSES exactly; do not invent values here.
 */
const COMMAND_STATUS_META = {
    pending:     { color: 'gray',  label: 'Pending' },
    sent:        { color: 'blue',  label: 'Sent' },
    executed:    { color: 'green', label: 'Executed' },
    failed:      { color: 'red',   label: 'Failed' },
    unsupported: { color: 'amber', label: 'Not supported' },
};

/**
 * Ziggy throws on an unknown route name, which would take the whole sub-tab down
 * with it. The capability / probe / settings endpoints ship in a separate change,
 * so ask the router at runtime and degrade to an explanation instead of a crash.
 */
const hasRoute = (name) => {
    try {
        return typeof route === 'function' && route().has(name);
    } catch {
        return false;
    }
};

const CAP_ROUTES = {
    catalogue: 'biometric-devices.settings-catalogue',
    snapshot:  'biometric-devices.capabilities',
    probe:     'biometric-devices.probe',
    save:      'biometric-devices.settings.update',
};

/**
 * Stored-template routes. Both ship in a separate change, so every use goes
 * through hasRoute() first — a missing route explains itself instead of taking
 * the sub-tab down with a Ziggy throw.
 */
const TEMPLATE_ROUTES = {
    list:    'biometric-devices.templates',
    restore: 'biometric-devices.restore-templates',
    // The destructive mirror of `restore`. It removes fingerprint(s) from the
    // TERMINAL and leaves our stored copy in place — see DELETE_SCOPE below.
    delete:  'biometric-devices.delete-template',
};

/**
 * The two scopes a `DATA DELETE FINGERTMP` can have, and the reason they are a
 * deliberate choice in the UI rather than an inferred one.
 *
 * The protocol expresses "every finger for this PIN" by leaving `FID` off the
 * wire entirely — so the difference between deleting one enrolment and deleting
 * all of somebody's enrolments on that terminal is a single absent field. That
 * is far too large a difference to leave implicit, so the dialog makes the
 * admin pick, states which one the button will do, and repeats it in the
 * acknowledgement.
 */
const DELETE_SCOPE = {
    single: 'single',
    all:    'all',
};

/** Is this row addressable as one finger slot? 0-9 only — the protocol's range. */
const hasFingerSlot = (row) => Number.isInteger(row?.fingerIndex) && row.fingerIndex >= 0 && row.fingerIndex <= 9;

/** Remediation for ATTLOG rows the importer could not attribute to anyone. */
const ATTLOG_ROUTES = {
    linkUser: 'biometric-devices.attlogs.link-user',
};

/**
 * ADMS is device-initiated: the server cannot read a terminal on demand, it can
 * only queue a command the device collects on its next poll. So every number on
 * the capabilities screen is "what the device last volunteered", and is only
 * meaningful next to the age of the snapshot it came from.
 */
const SNAPSHOT_STALE_AFTER_MS = 24 * 60 * 60 * 1000;

/**
 * Unwrap one entry from a snapshot pool.
 *
 * The documented `options` map stores each device answer as
 * `{ value, unsupported, source, probed_at }`, so returning the bare object
 * would render "[object Object]" on screen. An entry the device explicitly
 * rejected reads as *no answer*, never as its (absent) value.
 */
const unwrapSnapshotValue = (v) => {
    if (v && typeof v === 'object' && !Array.isArray(v)) {
        if (v.unsupported === true) return null;
        const inner = v.value;
        return (inner === undefined || inner === null || inner === '') ? null : inner;
    }
    return (v === undefined || v === null || v === '') ? null : v;
};

/**
 * Normalise an option key for lookup: case-insensitive, tilde-insensitive.
 *
 * Real MB460 firmware answers `~MaxUserCount` where the matrix says
 * `MaxUserCount`, and a lookup that only knows one spelling silently finds
 * nothing. Both must land in the same bucket.
 */
const normaliseOptionKey = (k) => String(k ?? '').trim().replace(/^~+/, '').toLowerCase();

/**
 * Three device facts that are NOT the same fact, plus the absence of any fact.
 * An admin acts differently on each, so they must never collapse into one
 * greyed-out box:
 *
 *  - `answered`    the device was asked and gave a value.
 *  - `unsupported` the device explicitly rejected the key with −1004,
 *                  "not supported on this model" (docs §4). Permanent hardware
 *                  fact; nothing to retry, nothing to escalate.
 *  - `omitted`     the device called the reply a success and then simply left
 *                  the key out. Real case: `MThreshold` was requested of an
 *                  MB460 and never came back. That is firmware declining to
 *                  answer, not the model lacking the feature — the distinction
 *                  matters because one is worth re-probing and the other is not.
 *  - `unprobed`    nobody has ever asked. Blank here means "not asked", never
 *                  "zero" and never "unavailable".
 */
const KEY_STATE_META = {
    answered:    { color: 'green',  label: 'Answered by the device' },
    unsupported: { color: 'amber',  label: 'Not supported on this model' },
    omitted:     { color: 'orange', label: "Device didn't answer for this key" },
    unprobed:    { color: 'gray',   label: 'Not yet probed' },
};

const KEY_STATE_NOTE = {
    unsupported: 'The device answered −1004 for this key — this model cannot do it. Re-probing will not change the answer.',
    omitted:     'The key was requested, the device called the reply a success, and then left this key out of it. That is firmware declining to answer, not the model lacking the feature — worth re-probing, unlike a −1004.',
    unprobed:    'This key has never been asked for on this device. Blank means "not asked", not "unavailable" and not "zero".',
};

/**
 * Index a snapshot's raw `options` map by normalised key.
 *
 * Each entry is `{ value, unsupported, source, probed_at }`. When the same key
 * arrives under two spellings, an entry that actually answered beats one that
 * did not — a device that answered `~MaxUserCount` has told us the number
 * whatever it did with the plain spelling.
 */
const buildOptionIndex = (snapshot) => {
    const index = new Map();
    const pool = snapshot?.options;
    if (pool && typeof pool === 'object' && !Array.isArray(pool)) {
        Object.entries(pool).forEach(([k, v]) => {
            if (!v || typeof v !== 'object' || Array.isArray(v)) return;
            const nk = normaliseOptionKey(k);
            const existing = index.get(nk);
            if (!existing || (existing.unsupported && !v.unsupported)) {
                index.set(nk, { ...v, key: k });
            }
        });
    }
    return index;
};

/**
 * Resolve one key to `answered` / `unsupported` / `omitted` / `unprobed`.
 *
 * The backend flags both an explicit −1004 and a silent omission as
 * `unsupported: true` — they are alike in that neither key should be offered —
 * and separates them with `source === 'omitted'`. Printing "−1004" over an
 * omission would be telling the admin something the device never said.
 */
const readKeyState = (optionIndex, key) => {
    const entry = optionIndex instanceof Map ? optionIndex.get(normaliseOptionKey(key)) : null;
    if (!entry) return 'unprobed';
    if (entry.unsupported) return entry.source === 'omitted' ? 'omitted' : 'unsupported';
    return 'answered';
};

/** The first non-`unprobed` state across a set of spellings for the same fact. */
const readKeyStateAny = (optionIndex, keys) => {
    let seen = 'unprobed';
    for (const k of keys) {
        const s = readKeyState(optionIndex, k);
        if (s === 'answered') return 'answered';
        if (s !== 'unprobed') seen = s;
    }
    return seen;
};

/**
 * Pull a value out of a capability snapshot without betting on one envelope
 * shape. The snapshot may expose device answers flat, split into counts/maxima,
 * or as the raw `GET OPTION` key map — accept all of them rather than guessing.
 *
 * Every key is also tried `~`-prefixed. Real MB460 firmware answers `INFO` with
 * the SDK parameter spellings — `~MaxUserCount`, `~DeviceName`, `~Platform` —
 * not the plain ones from the matrix, and a lookup that only knows the plain
 * spelling silently finds nothing against real hardware.
 */
const pickSnapshotValue = (snapshot, keys) => {
    if (!snapshot) return null;
    const pools = [
        snapshot, snapshot.counts, snapshot.maxima, snapshot.capacity,
        snapshot.identity, snapshot.options, snapshot.settings, snapshot.values,
    ];
    const spellings = [];
    keys.forEach(k => {
        spellings.push(k);
        if (!String(k).startsWith('~')) spellings.push(`~${k}`);
    });
    for (const pool of pools) {
        if (!pool || typeof pool !== 'object') continue;
        for (const key of spellings) {
            const v = unwrapSnapshotValue(pool[key]);
            if (v !== null) return v;
        }
    }
    return null;
};

/**
 * Coerce a device-reported count. Negative values are ADMS return codes
 * (-1004 / -1) leaking through as "no answer", never quantities — treat them as
 * unknown rather than rendering "-1004 users".
 */
const toCount = (v) => {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) && n >= 0 ? n : null;
};

/**
 * Capacity axes per docs §5.2. Each carries the device option keys it can be
 * satisfied by, most specific first, plus snake_case aliases a normalising
 * backend is likely to emit.
 */
const CAPACITY_METRICS = [
    {
        id: 'users', label: 'Users',
        usedKeys: ['UserCount', 'user_count', 'users_count', 'users'],
        maxKeys:  ['MaxUserCount', 'max_user_count', 'max_users'],
    },
    {
        id: 'fingerprints', label: 'Fingerprints',
        usedKeys: ['FPCount', 'fp_count', 'finger_count', 'fingerprints'],
        maxKeys:  ['MaxFingerCount', 'max_finger_count', 'max_fingerprints'],
    },
    {
        id: 'faces', label: 'Faces',
        usedKeys: ['FaceCount', 'face_count', 'faces'],
        maxKeys:  ['MaxFaceCount', 'max_face_count', 'max_faces'],
    },
    {
        id: 'attendance', label: 'Attendance records',
        usedKeys: ['AttLogCount', 'attlog_count', 'att_log_count', 'TransactionCount', 'attendance_records'],
        maxKeys:  ['MaxAttLogCount', 'max_attlog_count', 'max_att_log_count', 'max_attendance_records'],
    },
];

/**
 * Device-reported identity (docs §5.5). Kept explicitly separate from the
 * admin-entered name/location/model on the Devices tab: one is what the unit
 * says it is, the other is what somebody typed.
 */
const IDENTITY_FIELDS = [
    { id: 'firmware', label: 'Firmware version', keys: ['FWVersion', 'firmware_version', 'firmware', 'FirmVer'] },
    { id: 'platform', label: 'Platform',         keys: ['Platform', 'platform', '~Platform'] },
    { id: 'mac',      label: 'MAC address',      keys: ['MACAddress', 'mac_address', 'MAC', 'mac'] },
    { id: 'name',     label: 'Device-reported name', keys: ['DeviceName', 'device_name', '~DeviceName'] },
    { id: 'serial',   label: 'Device-reported serial', keys: ['SerialNumber', '~SerialNumber', 'serial_number'] },
    { id: 'ip',       label: 'Device-reported IP', keys: ['IPAddress', 'ip_address'] },
];

/**
 * Biometric engines the terminal can report as present or absent.
 *
 * `FvFunOn = 0` and `PvFunOn = 0` — which is exactly what a real MB460 answers —
 * mean the unit has *no finger-vein and no palm-vein engine at all*. That is a
 * different sentence from "the engine is there and nobody is enrolled", and the
 * two must never collapse into the same "0" on screen: one is a permanent fact
 * about the model, the other is a work item.
 *
 * `snapshot.flags` is the preferred source (documented shape, already decoded).
 * The raw option keys are the fallback for a snapshot that predates it — a key
 * that is simply absent means "the device was never asked", a third state again.
 */
const ENGINE_FLAGS = [
    { id: 'fingerprint', label: 'Fingerprint', keys: ['FingerFunOn'] },
    { id: 'face',        label: 'Face',        keys: ['FaceFunOn'] },
    { id: 'finger_vein', label: 'Finger vein', keys: ['FvFunOn'] },
    { id: 'palm_vein',   label: 'Palm vein',   keys: ['PvFunOn'] },
    { id: 'user_photo',  label: 'User photo',  keys: ['PhotoFunOn'] },
];

/**
 * Resolve one engine to `true` (present), `false` (absent on this model) or
 * `null` (never reported). Anything that cannot be read as a definite 0/1 stays
 * `null` rather than being guessed into a boolean.
 */
const readEngineFlag = (snapshot, engine) => {
    const fromFlags = snapshot?.flags?.[engine.id];
    if (fromFlags === true || fromFlags === false) return fromFlags;
    const raw = pickSnapshotValue(snapshot, engine.keys);
    if (raw === null) return null;
    const s = String(raw).trim().toLowerCase();
    if (s === '1' || s === 'true' || s === 'on' || s === 'yes') return true;
    if (s === '0' || s === 'false' || s === 'off' || s === 'no') return false;
    return null;
};

const TEMPLATE_TYPE_META = {
    fingerprint: { color: 'green',  label: 'Fingerprint' },
    face:        { color: 'purple', label: 'Face' },
    palm:        { color: 'orange', label: 'Palm' },
};

/** Template sizes are a few hundred bytes to a few KB; bytes alone read badly. */
const formatBytes = (n) => {
    const v = toCount(n);
    if (v === null) return '—';
    if (v < 1024) return `${v} B`;
    return `${(v / 1024).toFixed(1)} KB`;
};

/** Backend reason keys are snake_case; make them readable without inventing text. */
const prettyReason = (r) => String(r).replace(/[_-]+/g, ' ').replace(/^\w/, c => c.toUpperCase());

/**
 * `reasons` from restore-templates is a breakdown of why rows were skipped. It
 * may arrive as a key→count map, a key→list map, or a list of objects; all three
 * collapse to the same `{ reason, count }` rows so nothing is dropped silently.
 */
const normaliseReasons = (raw) => {
    if (!raw) return [];
    if (Array.isArray(raw)) {
        return raw.map(r => (r && typeof r === 'object')
            ? { reason: String(r.reason ?? r.key ?? r.label ?? 'Unknown'), count: toCount(r.count ?? r.total) }
            : { reason: String(r), count: null });
    }
    if (typeof raw === 'object') {
        return Object.entries(raw).map(([reason, v]) => ({
            reason,
            count: Array.isArray(v) ? v.length : toCount(v),
        }));
    }
    return [{ reason: String(raw), count: null }];
};

/**
 * Two settings carry more operational weight than the rest (docs §4b) and are
 * pinned above the grouped form so they are not lost among forty checkboxes.
 */
const PROMINENT_KEYS = {
    MThreshold: {
        heading: '1:N match threshold',
        note: 'The dial behind false-accept vs. false-reject complaints. Lower accepts more readily (risking wrong matches); higher is stricter (risking rejected genuine fingers). Change in small steps and re-test on site.',
    },
    AlarmReRec: {
        heading: 'Duplicate-punch window',
        note: 'Device-side duplicate suppression, in minutes. Attendance already de-duplicates server-side, so raising this does not change what is recorded — it stops the device sending punches we only throw away.',
    },
};

/** Turn a catalogue group key into something readable if the backend sent a slug. */
const prettyGroup = (g) => {
    if (!g) return 'Other';
    if (/[A-Z ]/.test(g) && !/_/.test(g)) return g;
    return g.replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
};

/**
 * Normalise the settings catalogue. The endpoint may hand back a keyed object
 * or a list; both are accepted so a shape change upstream degrades to "fewer
 * fields" rather than an empty screen.
 */
const normaliseCatalogue = (raw) => {
    const entries = Array.isArray(raw)
        ? raw
        : (raw && typeof raw === 'object')
            ? Object.entries(raw).map(([key, v]) => ({ key, ...(v && typeof v === 'object' ? v : {}) }))
            : [];

    return entries
        .map(e => ({
            key:         e.key ?? e.name,
            group:       e.group ?? e.category ?? 'Other',
            label:       e.label ?? e.title ?? e.key ?? e.name,
            type:        String(e.type ?? e.value_type ?? 'string').toLowerCase(),
            danger:      Boolean(e.danger ?? e.dangerous),
            description: e.description ?? e.help ?? e.hint ?? null,
            choices:     e.options ?? e.choices ?? e.enum ?? null,
            min:         e.min ?? null,
            max:         e.max ?? null,
            unit:        e.unit ?? null,
        }))
        .filter(e => e.key);
};

const isBoolType = (type) => type === 'bool' || type === 'boolean' || type === 'switch';
const isNumberType = (type) => type === 'int' || type === 'integer' || type === 'number' || type === 'float';

/** Render every value as a string so "changed?" is one unambiguous comparison. */
const asFormValue = (entry, raw) => {
    if (raw === null || raw === undefined) return '';
    if (isBoolType(entry.type)) {
        return (raw === true || raw === 1 || raw === '1' || raw === 'true' || raw === 'on') ? '1' : '0';
    }
    return String(raw);
};

/* ── Devices sub-tab ── */
function DevicesTab({ devices, setDevices, employees, isMobile }) {
    const [editDevice, setEditDevice]   = useState(null);
    const [dialogOpen, setDialogOpen]   = useState(false);
    const [form, setForm]               = useState(EMPTY_DEVICE);
    const [saving, setSaving]           = useState(false);
    const [pinging, setPinging]         = useState(null);
    const [tokenDialog, setTokenDialog] = useState({ open: false, device: null, token: '', kind: 'webhook' });
    const [downloadingDevice, setDownloadingDevice] = useState(null);
    const [regeneratingToken, setRegeneratingToken] = useState(null);

    // Bulk selection state
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkLoading, setBulkLoading] = useState(false);
    const [bulkDeleteDialog, setBulkDeleteDialog] = useState(false);

    // Device commands state
    const [commandDevice, setCommandDevice] = useState(null);
    const [commandType, setCommandType] = useState('REBOOT');
    const [commandPayload, setCommandPayload] = useState('');
    const [logStartDate, setLogStartDate] = useState('');
    const [logEndDate, setLogEndDate] = useState('');
    const [sendingCommand, setSendingCommand] = useState(false);
    const [commandScheduledAt, setCommandScheduledAt] = useState('');
    const [commandHistory, setCommandHistory] = useState([]);
    const [loadingCommands, setLoadingCommands] = useState(false);
    const [isCommandOpen, setIsCommandOpen] = useState(false);

    const openAdd = () => { setEditDevice(null); setForm(EMPTY_DEVICE); setDialogOpen(true); };
    const openEdit = d => {
        setEditDevice(d);
        setForm({ name: d.name, serial_number: d.serial_number, ip_address: d.ip_address ?? '',
            location: d.location ?? '', model: d.model ?? '', protocol: d.protocol ?? 'push_sdk', is_active: d.is_active });
        setDialogOpen(true);
    };

    const save = async () => {
        if (!form.name.trim() || !form.serial_number.trim())
            return showToast.error('Name and serial number are required.');
        setSaving(true);
        try {
            if (editDevice) {
                const { data } = await axios.put(route('biometric-devices.update', editDevice.id), form);
                setDevices(p => p.map(d => d.id === editDevice.id ? data.device : d));
                showToast.success('Device updated.');
            } else {
                const { data } = await axios.post(route('biometric-devices.store'), form);
                setDevices(p => [...p, data.device]);
                showToast.success('Device registered.');
            }
            setDialogOpen(false);
        } catch (e) {
            const msg = e.response?.data?.errors
                ? Object.values(e.response.data.errors).flat().join(' ')
                : e.response?.data?.message ?? 'Failed to save.';
            showToast.error(msg);
        } finally {
            setSaving(false);
        }
    };

    const deleteDevice = async d => {
        if (!confirm(`Delete "${d.name}"? All mappings will be removed.`)) return;
        try {
            await axios.delete(route('biometric-devices.destroy', d.id));
            setDevices(p => p.filter(x => x.id !== d.id));
            showToast.success('Device deleted.');
        } catch { showToast.error('Failed to delete.'); }
    };

    const ping = async d => {
        if (!d.ip_address) return showToast.error('No IP address configured.');
        setPinging(d.id);
        try {
            const { data } = await axios.post(route('biometric-devices.ping', d.id));
            data.success
                ? showToast.success(`Reachable (${data.latency}ms)`)
                : showToast.error('Device unreachable');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Ping failed.');
        } finally { setPinging(null); }
    };

    const handleDownloadLogs = async (device) => {
        setDownloadingDevice(device.id);
        try {
            const { data } = await axios.post(route('biometric-devices.download-logs', device.id));
            showToast.success(data.message || 'Log download initiated.');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to initiate log download.');
        } finally {
            setDownloadingDevice(null);
        }
    };

    const regen = async d => {
        if (!confirm(`Regenerate the Push SDK auth token for "${d.name}"? The device must be reconfigured with the new X-Device-Token before it can post again.`)) return;
        setRegeneratingToken(`webhook:${d.id}`);
        try {
            const { data } = await axios.post(route('biometric-devices.regenerate-token', d.id));
            setTokenDialog({ open: true, device: d, token: data.auth_token, kind: 'webhook' });
            showToast.success('Push SDK auth token regenerated.');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to regenerate Push SDK auth token.');
        } finally { setRegeneratingToken(null); }
    };

    const regenAdmsToken = async d => {
        if (d.protocol !== 'adms') {
            showToast.error('ADMS device tokens only apply to ADMS protocol devices.');
            return;
        }
        if (!confirm(`Regenerate the ADMS device token for "${d.name}"? The new token must be entered on the device or its /iclock pushes will be rejected in strict mode.`)) return;
        setRegeneratingToken(`adms:${d.id}`);
        try {
            const { data } = await axios.post(route('biometric-devices.regenerate-adms-token', d.id));
            setTokenDialog({ open: true, device: d, token: data.adms_token, kind: 'adms' });
            showToast.success(data.message || 'ADMS device token regenerated.');
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to regenerate ADMS device token.');
        } finally { setRegeneratingToken(null); }
    };

    /* ── bulk selection handlers ── */
    const toggleSelect = (id) => {
        setSelectedIds(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id]);
    };

    const toggleSelectAll = () => {
        if (selectedIds.length === devices.length && selectedIds.length > 0) {
            setSelectedIds([]);
        } else {
            setSelectedIds(devices.map(d => d.id));
        }
    };

    const clearSelection = () => setSelectedIds([]);

    const handleBulkPing = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.ping'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to ping devices.');
        } finally {
            setBulkLoading(false);
        }
    };

    const handleBulkDelete = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.delete'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message);
            setDevices(p => p.filter(d => !selectedIds.includes(d.id)));
            clearSelection();
            setBulkDeleteDialog(false);
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to delete devices.');
        } finally {
            setBulkLoading(false);
        }
    };

    const openCommandModal = async (device) => {
        if (device.protocol !== 'adms') {
            showToast.error('Commands only supported for ADMS protocol devices.');
            return;
        }
        setCommandDevice(device);
        setCommandType('REBOOT');
        setCommandPayload('');
        setLogStartDate('');
        setLogEndDate('');
        setCommandScheduledAt('');
        setLoadingCommands(true);
        setIsCommandOpen(true);
        try {
            const { data } = await axios.get(
                route('api.biometric-devices.commands.index', device.id),
            );
            setCommandHistory(data.commands ?? []);
        } catch {
            showToast.error('Failed to load command history.');
        } finally {
            setLoadingCommands(false);
        }
    };

    const sendCommand = async () => {
        if (!commandDevice) return;
        setSendingCommand(true);
        try {
            let payload = null;
            if (commandType === 'SET_TIME') {
                payload = { time: commandPayload || new Date().toISOString().slice(0, 19).replace('T', ' ') };
            } else if (commandType === 'ADD_USER' || commandType === 'UPDATE_USER') {
                try   { payload = JSON.parse(commandPayload); }
                catch { showToast.error('Invalid JSON payload.'); setSendingCommand(false); return; }
            } else if (commandType === 'DELETE_USER') {
                payload = { pin: commandPayload };
            } else if (commandType === 'CHECK_ATTLOG') {
                if (logStartDate && logEndDate) {
                    const formatDateTime = (val, isEndDate = false) => {
                        let formatted = val.replace('T', ' ');
                        if (formatted.length === 16) {
                            formatted += ':00';
                        }
                        // When the user selects only a date without explicitly setting time,
                        // the browser defaults to 00:00. For end dates this means midnight at
                        // the START of that day, excluding the entire day from results.
                        // Auto-set to 23:59:59 so the full end date is included.
                        if (isEndDate && formatted.endsWith(' 00:00:00')) {
                            formatted = formatted.replace(' 00:00:00', ' 23:59:59');
                        }
                        return formatted;
                    };
                    payload = {
                        start_time: formatDateTime(logStartDate),
                        end_time: formatDateTime(logEndDate, true),
                    };
                } else if (logStartDate || logEndDate) {
                    showToast.error('Please specify both start and end date/time, or leave both empty.');
                    setSendingCommand(false);
                    return;
                }
            }

            // CHECK_ATTLOG is routed through initiateLogDownload(), which creates its
            // own command and ignores scheduled_at — so never offer/send it there.
            const scheduledAt = (commandType !== 'CHECK_ATTLOG' && commandScheduledAt)
                ? toSqlDateTime(commandScheduledAt)
                : null;

            const { data } = await axios.post(
                route('api.biometric-devices.commands.queue', commandDevice.id),
                { device_id: commandDevice.id, command_type: commandType, payload, scheduled_at: scheduledAt },
            );
            showToast.success(
                scheduledAt
                    ? `Command scheduled for ${new Date(scheduledAt.replace(' ', 'T')).toLocaleString()}: ${data.command.adms_string}`
                    : `Command queued: ${data.command.adms_string}`,
            );

            const { data: historyData } = await axios.get(
                route('api.biometric-devices.commands.index', commandDevice.id),
            );
            setCommandHistory(historyData.commands ?? []);
            setCommandType('REBOOT');
            setCommandPayload('');
            setLogStartDate('');
            setLogEndDate('');
            setCommandScheduledAt('');
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to queue command.');
        } finally {
            setSendingCommand(false);
        }
    };

    const handleBulkDownloadLogs = async () => {
        setBulkLoading(true);
        try {
            const { data } = await axios.post(route('biometric-devices.bulk.download-logs'), {
                device_ids: selectedIds,
            });
            showToast.success(data.message || 'Bulk log download initiated.');
            clearSelection();
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to initiate bulk download.');
        } finally {
            setBulkLoading(false);
        }
    };

    const hasSelectedAdms = useMemo(() => {
        return devices.some(d => selectedIds.includes(d.id) && d.protocol === 'adms');
    }, [devices, selectedIds]);

    const copy = t => navigator.clipboard.writeText(t).then(() => showToast.success('Copied!'));

    return (
        <Box>
            <Flex justify="end" mb="3">
                <Button size="2" onClick={openAdd}><PlusIcon /> Add Device</Button>
            </Flex>

            {/* Bulk Actions Toolbar */}
            {selectedIds.length > 0 && (
                <Panel size="2" variant="surface" mb="3" style={{ background: 'var(--indigo-a3)', border: '1px solid var(--indigo-a7)' }}>
                    <Flex align="center" justify="between" gap="3">
                        <Flex align="center" gap="2">
                            <CheckIcon style={{ color: 'var(--indigo-9)' }} />
                            <Text size="2" weight="medium">{selectedIds.length} device(s) selected</Text>
                        </Flex>
                        <Flex gap="2">
                            <Button size="2" variant="soft" color="indigo" disabled={bulkLoading} onClick={handleBulkPing}>
                                {bulkLoading ? <Spinner size="1" /> : 'Ping'}
                            </Button>
                            {hasSelectedAdms && (
                                <Button size="2" variant="soft" color="green" disabled={bulkLoading} onClick={handleBulkDownloadLogs}>
                                    {bulkLoading ? <Spinner size="1" /> : <><DownloadIcon /> Download Logs</>}
                                </Button>
                            )}
                            <Button size="2" variant="soft" color="red" disabled={bulkLoading} onClick={() => setBulkDeleteDialog(true)}>
                                {bulkLoading ? <Spinner size="1" /> : <><TrashIcon /> Delete</>}
                            </Button>
                            <IconButton size="2" variant="ghost" color="gray" onClick={clearSelection} aria-label="Clear selection">
                                <Cross2Icon />
                            </IconButton>
                        </Flex>
                    </Flex>
                </Panel>
            )}

            {devices.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <DesktopIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No devices registered</Text>
                    <Text size="2" color="gray">Click "Add Device" to get started.</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell style={{ width: 40 }}>
                                    <Checkbox
                                        checked={selectedIds.length === devices.length && devices.length > 0}
                                        onCheckedChange={toggleSelectAll}
                                    />
                                </Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>IP / Location</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Protocol</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Last Ping</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {devices.map(d => (
                                <Table.Row key={d.id}>
                                    <Table.Cell>
                                        <Checkbox
                                            checked={selectedIds.includes(d.id)}
                                            onCheckedChange={() => toggleSelect(d.id)}
                                        />
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold" size="2" as="div">{d.name}</Text>
                                        {d.model && <Text size="1" color="gray">{d.model}</Text>}
                                    </Table.Cell>
                                    <Table.Cell><Code size="1">{d.serial_number}</Code></Table.Cell>
                                    <Table.Cell>
                                        {d.ip_address && <Text size="1" as="div">{d.ip_address}</Text>}
                                        {d.location   && <Text size="1" color="gray">{d.location}</Text>}
                                        {!d.ip_address && !d.location && <Text size="1" color="gray">—</Text>}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={d.protocol === 'adms' ? 'green' : 'blue'} variant="soft" size="1">
                                            {d.protocol === 'adms' ? 'ADMS' : 'Push SDK'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{d.last_ping ? new Date(d.last_ping).toLocaleString() : 'Never'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={d.is_active ? 'green' : 'red'} variant="soft" size="1">
                                            {d.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="1">
                                            <Tooltip content="Ping device">
                                                <IconButton size="1" variant="soft" color="indigo" onClick={() => ping(d)} disabled={pinging === d.id}>
                                                    {pinging === d.id ? <Spinner size="1" /> : <ReloadIcon />}
                                                </IconButton>
                                            </Tooltip>
                                            {d.protocol === 'adms' ? (
                                                <>
                                                    <Tooltip content="Download Logs">
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="green"
                                                            onClick={() => handleDownloadLogs(d)}
                                                            disabled={downloadingDevice === d.id}
                                                        >
                                                            {downloadingDevice === d.id ? <Spinner size="1" /> : <DownloadIcon />}
                                                        </IconButton>
                                                    </Tooltip>
                                                    <Tooltip content="Device Commands">
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="violet"
                                                            onClick={() => openCommandModal(d)}
                                                        >
                                                            <DotsVerticalIcon />
                                                        </IconButton>
                                                    </Tooltip>
                                                    <Tooltip content={TOKEN_META.adms.action}>
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="amber"
                                                            onClick={() => regenAdmsToken(d)}
                                                            disabled={regeneratingToken === `adms:${d.id}`}
                                                        >
                                                            {regeneratingToken === `adms:${d.id}` ? <Spinner size="1" /> : <LockClosedIcon />}
                                                        </IconButton>
                                                    </Tooltip>
                                                </>
                                            ) : (
                                                <Tooltip content={TOKEN_META.webhook.action}>
                                                    <IconButton
                                                        size="1"
                                                        variant="soft"
                                                        color="amber"
                                                        onClick={() => regen(d)}
                                                        disabled={regeneratingToken === `webhook:${d.id}`}
                                                    >
                                                        {regeneratingToken === `webhook:${d.id}` ? <Spinner size="1" /> : <LockOpen1Icon />}
                                                    </IconButton>
                                                </Tooltip>
                                            )}
                                            <Tooltip content="Edit device">
                                                <IconButton size="1" variant="soft" color="gray" onClick={() => openEdit(d)}>
                                                    <Pencil1Icon />
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip content="Delete device">
                                                <IconButton size="1" variant="soft" color="red" onClick={() => deleteDevice(d)}>
                                                    <TrashIcon />
                                                </IconButton>
                                            </Tooltip>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            {/* Add/Edit Device Dialog */}
            <Dialog.Root open={dialogOpen} onOpenChange={setDialogOpen}>
                <Dialog.Content style={{ maxWidth: 480 }}>
                    <Dialog.Title>{editDevice ? 'Edit Device' : 'Register Device'}</Dialog.Title>
                    <Flex direction="column" gap="3" mt="3">
                        {[
                            { key: 'name', label: 'Device Name *', ph: 'Main Entrance' },
                            { key: 'serial_number', label: 'Serial Number *', ph: 'ABJM12345678', disabled: !!editDevice },
                            { key: 'ip_address', label: 'IP Address', ph: '192.168.1.100' },
                            { key: 'location', label: 'Location', ph: '3rd Floor, Block B' },
                            { key: 'model', label: 'Model', ph: 'ZKTeco K40' },
                        ].map(({ key, label, ph, disabled }) => (
                            <Box key={key}>
                                <Text size="2" weight="medium" as="div" mb="1">{label}</Text>
                                <TextField.Root size="2" value={form[key]} placeholder={ph}
                                    disabled={disabled}
                                    onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))} />
                            </Box>
                        ))}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">Protocol</Text>
                            <Select.Root size="2" value={form.protocol}
                                onValueChange={v => setForm(f => ({ ...f, protocol: v }))}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    <Select.Item value="push_sdk">Push SDK — K40, K60, iFace</Select.Item>
                                    <Select.Item value="adms">ADMS — MB460, MB360</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Flex justify="between" align="center">
                            <Box>
                                <Text size="2" weight="medium" as="div">Active</Text>
                                <Text size="1" color="gray">Inactive devices skip attendance events.</Text>
                            </Box>
                            <Switch size="2" checked={form.is_active}
                                onCheckedChange={v => setForm(f => ({ ...f, is_active: v }))} />
                        </Flex>
                    </Flex>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={save} disabled={saving}>
                            {saving ? <><Spinner size="1" /> Saving…</> : (editDevice ? 'Update' : 'Register')}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Token Dialog */}
            <Dialog.Root open={tokenDialog.open} onOpenChange={o => setTokenDialog(p => ({ ...p, open: o }))}>
                <Dialog.Content style={{ maxWidth: 460 }}>
                    <Dialog.Title>New {TOKEN_META[tokenDialog.kind]?.title} — {tokenDialog.device?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        This value is shown once. Copy it now and paste it into the device — it cannot be retrieved again.
                    </Dialog.Description>
                    <Panel variant="surface" mt="3" style={{ background: 'var(--amber-a3)' }}>
                        <Flex align="start" gap="2">
                            <ExclamationTriangleIcon style={{ color: 'var(--amber-11)', flexShrink: 0, marginTop: 2 }} />
                            <Text size="2" color="amber">
                                {TOKEN_META[tokenDialog.kind]?.note}
                            </Text>
                        </Flex>
                    </Panel>
                    <Flex align="center" gap="2" mt="3">
                        <Code size="2" style={{ flex: 1, background: 'var(--gray-a4)', borderRadius: 'var(--radius-2)', padding: '8px 12px', wordBreak: 'break-all' }}>
                            {tokenDialog.token}
                        </Code>
                        <IconButton variant="soft" size="2" onClick={() => copy(tokenDialog.token)}>
                            <CopyIcon />
                        </IconButton>
                    </Flex>
                    <Flex justify="end" mt="5">
                        <Dialog.Close>
                            <Button><CheckCircledIcon /> Done</Button>
                        </Dialog.Close>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Device Commands Modal */}
            <Dialog.Root open={isCommandOpen} onOpenChange={setIsCommandOpen}>
                <Dialog.Content style={{ maxWidth: 620 }}>
                    <Dialog.Title>Device Commands — {commandDevice?.name}</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Queue commands to this ADMS device.
                    </Dialog.Description>

                    <Flex direction="column" gap="4" mt="4">

                        {/* Single command */}
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="2">Single Command</Text>
                            <Flex direction="column" gap="3">
                                <Select.Root value={commandType} onValueChange={setCommandType}>
                                    <Select.Trigger
                                        style={{ width: '100%' }}
                                        placeholder="Select command type"
                                    />
                                    <Select.Content>
                                        <Select.Item value="REBOOT">Reboot Device</Select.Item>
                                        <Select.Item value="SET_TIME">Set Device Time</Select.Item>
                                        <Select.Item value="ADD_USER">Add User</Select.Item>
                                        <Select.Item value="UPDATE_USER">Update User</Select.Item>
                                        <Select.Item value="DELETE_USER">Delete User</Select.Item>
                                        <Select.Item value="GET_USERINFO">Get Enrolled Users (device roster)</Select.Item>
                                        <Select.Item value="CLEAR_LOG">Clear Attendance Logs</Select.Item>
                                        <Select.Item value="CLEAR_DATA">Clear All Data</Select.Item>
                                        <Select.Item value="CHECK_ATTLOG">Download Attendance Logs</Select.Item>
                                    </Select.Content>
                                </Select.Root>

                                {(commandType === 'SET_TIME' ||
                                  commandType === 'DELETE_USER' ||
                                  commandType === 'ADD_USER' ||
                                  commandType === 'UPDATE_USER') && (
                                    <Box>
                                        <Text size="2" as="div" mb="1">
                                            {commandType === 'SET_TIME'    && 'Time (YYYY-MM-DD HH:MM:SS)'}
                                            {commandType === 'DELETE_USER' && 'User PIN / ID'}
                                            {(commandType === 'ADD_USER' || commandType === 'UPDATE_USER') &&
                                                'User Data (JSON)'}
                                        </Text>
                                        <TextField.Root
                                            value={commandPayload}
                                            onChange={e => setCommandPayload(e.target.value)}
                                            placeholder={
                                                commandType === 'SET_TIME'
                                                    ? '2026-05-12 18:30:00'
                                                    : commandType === 'DELETE_USER'
                                                    ? '42'
                                                    : '{"pin":"42","name":"John Doe","card":"123456"}'
                                            }
                                            size="2"
                                        />
                                        {(commandType === 'ADD_USER' || commandType === 'UPDATE_USER') && (
                                            <Text size="1" color="gray" mt="1" as="div">
                                                Example: {'{"pin":"42","name":"John Doe","card":"123456","privilege":0}'}
                                            </Text>
                                        )}
                                    </Box>
                                )}

                                {commandType === 'CHECK_ATTLOG' && (
                                    <Flex direction="column" gap="3">
                                        <Box>
                                            <Text size="2" weight="medium" as="div" mb="1">Start Date & Time (Optional)</Text>
                                            <DateTimePicker
                                                mode="datetime"
                                                value={logStartDate}
                                                onChange={setLogStartDate}
                                            />
                                        </Box>
                                        <Box>
                                            <Text size="2" weight="medium" as="div" mb="1">End Date & Time (Optional)</Text>
                                            <DateTimePicker
                                                mode="datetime"
                                                value={logEndDate}
                                                onChange={setLogEndDate}
                                            />
                                        </Box>
                                        <Text size="1" color="gray" mt="1" as="div">
                                            Leave both fields empty to sync all records from the device.
                                        </Text>
                                    </Flex>
                                )}

                                {commandType !== 'CHECK_ATTLOG' && (
                                    <Box>
                                        <Text size="2" weight="medium" as="div" mb="1">Run At (Optional)</Text>
                                        <DateTimePicker
                                            mode="datetime"
                                            value={commandScheduledAt}
                                            onChange={setCommandScheduledAt}
                                        />
                                        <Text size="1" color="gray" mt="1" as="div">
                                            Leave empty to run at the next device contact. A scheduled command is held
                                            back until this time, then released on the next contact after it.
                                        </Text>
                                    </Box>
                                )}

                                <Button
                                    onClick={sendCommand}
                                    disabled={sendingCommand}
                                >
                                    {sendingCommand ? <><Spinner size="1" /> Sending…</> : 'Send Command'}
                                </Button>
                            </Flex>
                        </Box>

                        {/* Command history */}
                        <Box>
                            <Flex justify="between" align="center" mb="2">
                                <Text size="2" weight="medium">Command History</Text>
                                <IconButton
                                    variant="ghost"
                                    size="1"
                                    onClick={() => {
                                        setLoadingCommands(true);
                                        axios.get(route('api.biometric-devices.commands.index', commandDevice.id))
                                            .then(({ data }) => setCommandHistory(data.commands ?? []))
                                            .catch(() => showToast.error('Failed to refresh command history.'))
                                            .finally(() => setLoadingCommands(false));
                                    }}
                                    disabled={loadingCommands}
                                    aria-label="Refresh command history"
                                >
                                    {loadingCommands
                                        ? <Spinner size="1" />
                                        : <ReloadIcon />}
                                </IconButton>
                            </Flex>

                            {loadingCommands ? (
                                <Flex justify="center" py="4">
                                    <Spinner size="3" />
                                </Flex>
                            ) : commandHistory.length === 0 ? (
                                <Panel variant="surface">
                                    <Flex justify="center" py="4">
                                        <Text size="2" color="gray">No commands sent yet.</Text>
                                    </Flex>
                                </Panel>
                            ) : (
                                <Box style={{ overflowX: 'auto' }}>
                                    <Table.Root variant="surface">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Scheduled</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Created</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {commandHistory.map(cmd => (
                                                <Table.Row key={cmd.id}>
                                                    <Table.Cell>
                                                        <Code size="1">{cmd.command_type}</Code>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        {(() => {
                                                            // `unsupported` is a distinct status the backend sets from the
                                                            // device's own -1004 ack — it means this model cannot do this,
                                                            // which is a capability fact, not a failure. Anything the map
                                                            // does not know falls through showing the raw status rather
                                                            // than being silently recoloured as an error.
                                                            const meta = COMMAND_STATUS_META[cmd.status]
                                                                ?? { color: 'gray', label: cmd.status };
                                                            const isUnsupported = cmd.status === 'unsupported';
                                                            return (
                                                                <Flex direction="column" gap="1" align="start">
                                                                    <Badge color={meta.color} variant="soft" size="1">
                                                                        {meta.label}
                                                                    </Badge>
                                                                    {isUnsupported && (
                                                                        <Text size="1" color="amber" style={{ maxWidth: 220 }}>
                                                                            {cmd.error_message || 'Not supported on this model'}
                                                                            {' — the device rejected the command as unavailable on this hardware. Nothing to retry.'}
                                                                        </Text>
                                                                    )}
                                                                    {!isUnsupported && cmd.status === 'failed' && cmd.error_message && (
                                                                        <Text size="1" color="red" style={{ maxWidth: 220 }}>
                                                                            {cmd.error_message}
                                                                        </Text>
                                                                    )}
                                                                    {cmd.return_code !== null && cmd.return_code !== undefined && cmd.return_code !== '' && (
                                                                        <Tooltip content="Return code from the device acknowledgement">
                                                                            <Code size="1" variant="soft">Return={cmd.return_code}</Code>
                                                                        </Tooltip>
                                                                    )}
                                                                </Flex>
                                                            );
                                                        })()}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        {cmd.scheduled_at ? (
                                                            <Flex direction="column" gap="1" align="start">
                                                                <Badge color="cyan" variant="soft" size="1">Scheduled</Badge>
                                                                <Text size="1" color="gray" style={{ whiteSpace: 'nowrap' }}>
                                                                    {new Date(cmd.scheduled_at).toLocaleString()}
                                                                </Text>
                                                            </Flex>
                                                        ) : (
                                                            <Text size="1" color="gray">Immediate</Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Text size="1" color="gray">
                                                            {new Date(cmd.created_at).toLocaleString()}
                                                        </Text>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            )}
                        </Box>
                    </Flex>

                    <Flex justify="end" mt="5">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Close</Button>
                        </Dialog.Close>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Bulk Delete Dialog */}
            <Dialog.Root open={bulkDeleteDialog} onOpenChange={setBulkDeleteDialog}>
                <Dialog.Content style={{ maxWidth: 420 }}>
                    <Dialog.Title>Delete Devices</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        Are you sure you want to delete <Text weight="bold">{selectedIds.length} device(s)</Text>?
                        All user mappings and commands will be removed. This action cannot be undone.
                    </Dialog.Description>
                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close>
                            <Button variant="soft" color="gray">Cancel</Button>
                        </Dialog.Close>
                        <Button color="red" onClick={handleBulkDelete} disabled={bulkLoading}>
                            {bulkLoading ? <><Spinner size="1" /> Deleting…</> : <><TrashIcon /> Delete</>}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── Logs sub-tab ── */
function LogsTab({ isMobile }) {
    const [logs, setLogs]       = useState([]);
    const [loading, setLoading] = useState(false);
    /* Page, size and search live in the URL under a `lg_` prefix so they
       survive leaving the panel and coming back. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'lg_page',
        debounceKeys: ['lg_q'],
        defaults: { lg_q: '', lg_page: 1, lg_per: 20 },
    });
    const search = f.values.lg_q;
    const [total, setTotal] = useState(0);
    const pagination = { currentPage: f.values.lg_page, perPage: f.values.lg_per, total };

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.logs'), {
                params: { page: pagination.currentPage, per_page: pagination.perPage }
            });
            setLogs(data.logs ?? []);
            setTotal(data.total || 0);
        } catch { showToast.error('Failed to load logs.'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage]);

    // Follows the URL. (This used to call load() on every change, so the
    // pager never actually left page 1.)
    useEffect(() => { load(); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.message?.toLowerCase().includes(search.toLowerCase()) ||
            l.type?.toLowerCase().includes(search.toLowerCase())),
        [logs, search]);

    const handlePageChange = f.setPage;
    const handleRowsPerPageChange = (newPerPage) => f.set('lg_per', newPerPage);

    const levelColor = l => ({ error: 'red', warning: 'amber', info: 'blue' }[l] ?? 'gray');

    return (
        <Box>
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <TextField.Root placeholder="Search logs…" size="2" style={{ maxWidth: 360, flex: 1 }}
                    value={f.draft.lg_q}
                    onChange={e => f.setDraft('lg_q', e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    {search && (
                        <TextField.Slot side="right">
                            <IconButton size="1" variant="ghost" color="gray" onClick={() => f.setDraft('lg_q', '')}><Cross2Icon /></IconButton>
                        </TextField.Slot>
                    )}
                </TextField.Root>
                <Button size="2" variant="soft" color="indigo" onClick={() => load()} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filtered.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <ActivityLogIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">{search ? 'No matching logs' : 'No ADMS logs yet'}</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell style={{ width: 80 }}>Level</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Message</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell style={{ width: 160 }}>Time</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filtered.map(log => (
                                <Table.Row key={log.id}>
                                    <Table.Cell>
                                        <Badge color={levelColor(log.level)} variant="soft" size="1">
                                            {(log.level ?? 'info').toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.message}</Text>
                                        {log.context && Object.keys(log.context).length > 0 && (
                                            <Code size="1" style={{ display: 'block', marginTop: 4, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                                {JSON.stringify(log.context, null, 2)}
                                            </Code>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{new Date(log.created_at).toLocaleString()}</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}
        </Box>
    );
}

/* ── OPERLOG sub-tab ── */
function OperLogTab({ isMobile }) {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    /* Page, size and search live in the URL under a `ol_` prefix so they
       survive leaving the panel and coming back. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'ol_page',
        debounceKeys: ['ol_q'],
        defaults: { ol_q: '', ol_page: 1, ol_per: 20 },
    });
    const search = f.values.ol_q;
    const [total, setTotal] = useState(0);
    const pagination = { currentPage: f.values.ol_page, perPage: f.values.ol_per, total };

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.operlogs'), {
                params: { page: pagination.currentPage, per_page: pagination.perPage }
            });
            setLogs(data.logs ?? []);
            setTotal(data.total || 0);
        } catch { showToast.error('Failed to load OPERLOG entries.'); }
        finally { setLoading(false); }
    }, [pagination.currentPage, pagination.perPage]);

    // Follows the URL. (This used to call load() on every change, so the
    // pager never actually left page 1.)
    useEffect(() => { load(); }, [load]);

    const filtered = useMemo(() =>
        logs.filter(l => !search ||
            l.operation_type?.toLowerCase().includes(search.toLowerCase()) ||
            l.user_pin?.toLowerCase().includes(search.toLowerCase())),
        [logs, search]);

    const handlePageChange = f.setPage;
    const handleRowsPerPageChange = (newPerPage) => f.set('ol_per', newPerPage);

    return (
        <Box>
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <TextField.Root placeholder="Search OPERLOG…" size="2" style={{ maxWidth: 360, flex: 1 }}
                    value={f.draft.ol_q}
                    onChange={e => f.setDraft('ol_q', e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                    {search && (
                        <TextField.Slot side="right">
                            <IconButton size="1" variant="ghost" color="gray" onClick={() => f.setDraft('ol_q', '')}><Cross2Icon /></IconButton>
                        </TextField.Slot>
                    )}
                </TextField.Root>
                <Button size="2" variant="soft" color="indigo" onClick={() => load()} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filtered.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <LockClosedIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">{search ? 'No matching logs' : 'No OPERLOG entries yet'}</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Operation</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>User PIN</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Occurred At</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Details</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filtered.map(log => (
                                <Table.Row key={log.id}>
                                    <Table.Cell>
                                        <Badge color="blue" variant="soft" size="1">
                                            {log.operation_type || 'Unknown'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.user_pin || '—'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{log.serial_number}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.occurred_at ? new Date(log.occurred_at).toLocaleString() : '—'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{log.raw_data}</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}
        </Box>
    );
}

/* ── Webhook sub-tab ── */
function WebhookTab() {
    const webhookUrl = `${window.location.origin}/api/biometric/webhook`;
    // Derived, not hardcoded — this panel must be correct on every environment
    // (local, staging, production). `host` keeps the port when there is one.
    const admsUrl    = `${window.location.origin}/iclock/cdata`;
    const admsDomain = window.location.host;
    const copy = t => navigator.clipboard.writeText(t).then(() => showToast.success('Copied to clipboard.'));

    return (
        <Flex direction="column" gap="4">

            {/* Push SDK */}
            <Panel variant="surface">
                <Flex direction="column" gap="3">
                    <Flex align="center" gap="2">
                        <Badge color="blue" variant="soft">Push SDK</Badge>
                        <Text size="2" weight="medium">
                            For devices with Push SDK (K40, K60, iFace series)
                        </Text>
                    </Flex>
                    <Text size="2" color="gray">
                        Use the device's auth token as the{' '}
                        <Code>X-Device-Token</Code> request header.
                        Regenerate the token per device from the Devices tab.
                    </Text>
                    <Flex align="center" gap="2">
                        <Box flexGrow="1" style={{ minWidth: 0 }}>
                            <Code
                                size="2"
                                style={{
                                    display: 'block',
                                    background: 'var(--gray-a4)',
                                    borderRadius: 'var(--radius-2)',
                                    padding: '8px 12px',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                }}
                            >
                                POST {webhookUrl}
                            </Code>
                        </Box>
                        <Tooltip content="Copy URL">
                            <IconButton
                                variant="soft"
                                size="2"
                                onClick={() => copy(webhookUrl)}
                                aria-label="Copy webhook URL"
                            >
                                <CopyIcon width={16} height={16} />
                            </IconButton>
                        </Tooltip>
                    </Flex>
                </Flex>
            </Panel>

            {/* ADMS */}
            <Panel variant="surface">
                <Flex direction="column" gap="3">
                    <Flex align="center" gap="2">
                        <Badge color="green" variant="soft">ADMS</Badge>
                        <Text size="2" weight="medium">
                            For ZKTeco MB460 / MB360 (ADMS Push Protocol)
                        </Text>
                    </Flex>

                    <Text size="2" color="gray">
                        The MB460 has two push-server fields:{' '}
                        <strong>Enable</strong> and{' '}
                        <strong>Server Domain Name</strong>.
                        The device automatically appends{' '}
                        <Code size="1">/iclock/cdata</Code> to whatever you enter —
                        enter only the domain or IP, no path, no{' '}
                        <Code size="1">https://</Code>.
                    </Text>

                    {/* Server Domain Name field value */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">
                            Server Domain Name — paste this into the device:
                        </Text>
                        <Flex align="center" gap="2">
                            <Box flexGrow="1" style={{ minWidth: 0 }}>
                                <Code
                                    size="2"
                                    style={{
                                        display: 'block',
                                        background: 'var(--gray-a4)',
                                        borderRadius: 'var(--radius-2)',
                                        padding: '8px 12px',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap',
                                    }}
                                >
                                    {admsDomain}
                                </Code>
                            </Box>
                            <Tooltip content="Copy domain">
                                <IconButton
                                    variant="soft"
                                    size="2"
                                    onClick={() => copy(admsDomain)}
                                    aria-label="Copy domain"
                                >
                                    <CopyIcon width={16} height={16} />
                                </IconButton>
                            </Tooltip>
                        </Flex>
                    </Box>

                    {/* Resulting full URL for reference */}
                    <Box>
                        <Text size="1" color="gray" as="div" mb="1">
                            Full URL (for reference, device will construct this automatically):
                        </Text>
                        <Box flexGrow="1" style={{ minWidth: 0 }}>
                            <Code
                                size="2"
                                style={{
                                    display: 'block',
                                    background: 'var(--gray-a4)',
                                    borderRadius: 'var(--radius-2)',
                                    padding: '8px 12px',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                }}
                            >
                                {admsUrl}
                            </Code>
                        </Box>
                    </Box>

                    {/* DHCP warning */}
                    <Panel variant="surface" style={{ backgroundColor: 'var(--amber-a3)' }}>
                        <Flex align="start" gap="2">
                            <ExclamationTriangleIcon
                                width={16}
                                height={16}
                                color="var(--amber-11)"
                                style={{ flexShrink: 0, marginTop: 2 }}
                            />
                            <Flex direction="column" gap="1">
                                <Text size="2" color="amber" weight="medium">
                                    Device IP is on DHCP
                                </Text>
                                <Text size="1" color="amber">
                                    If your device gets its IP via DHCP, the IP may change over time.
                                    Consider setting a static IP reservation on your router or configuring
                                    a static IP on the device itself.
                                </Text>
                            </Flex>
                        </Flex>
                    </Panel>
                </Flex>
            </Panel>

            {/* Integration Checklist */}
            <Panel variant="surface">
                <Text size="2" weight="medium" as="div" mb="3">Integration Checklist</Text>
                <Flex direction="column" gap="2">
                    {[
                        'Register the device with the correct serial number in the Devices tab.',
                        'For Push SDK: use the unlock icon in the Devices tab to generate the Push SDK auth token, then configure it as X-Device-Token on the device.',
                        'For ADMS: set the device Server Domain Name to the domain above, then use the lock icon in the Devices tab to generate the ADMS device token and enter it on the device.',
                        'Enroll fingerprints on the device, then link device IDs to employees.',
                        'Verify events arrive via the ADMS Logs tab.',
                    ].map((step, i) => (
                        <Flex key={i} align="start" gap="2">
                            <CheckCircledIcon style={{ color: 'var(--green-9)', flexShrink: 0, marginTop: 2 }} />
                            <Text size="2" color="gray">{step}</Text>
                        </Flex>
                    ))}
                </Flex>
            </Panel>
        </Flex>
    );
}

/* ── Health sub-tab ── */
function HealthTab({ isMobile }) {
    const [healthData, setHealthData] = useState({ devices: [], summary: {} });
    const [loading, setLoading] = useState(true);
    /* The status filter lives in the URL under an `hl_` prefix. */
    const f = useQueryFilters({ mode: 'client', debounceKeys: [], defaults: { hl_status: 'all' } });
    const filterStatus = f.values.hl_status;
    const setFilterStatus = (v) => f.set('hl_status', v);

    const loadHealth = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.health'));
            setHealthData(data);
        } catch (e) {
            showToast.error('Failed to load health metrics.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        loadHealth();
        const interval = setInterval(loadHealth, 30000); // Auto-refresh every 30 seconds
        return () => clearInterval(interval);
    }, [loadHealth]);

    const filteredDevices = useMemo(() => {
        if (filterStatus === 'all') return healthData.devices;
        return healthData.devices.filter(d => d.status === filterStatus);
    }, [healthData.devices, filterStatus]);

    const statusColor = s => ({
        healthy: 'green',
        warning: 'amber',
        critical: 'red',
    }[s] ?? 'gray');

    const statusIcon = s => ({
        healthy: <CheckIcon />,
        warning: <ExclamationTriangleIcon />,
        critical: <CrossCircledIcon />,
    }[s] ?? null);

    const formatTime = (iso) => iso ? new Date(iso).toLocaleString() : 'Never';

    return (
        <Box>
            {/* Summary cards */}
            <Grid columns={{ initial: '2', sm: '4' }} gap="3" mb="4">
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Overall Health</Text>
                        <Text size="4" weight="bold" color={healthData.summary.overall_health_score >= 80 ? 'green' : healthData.summary.overall_health_score >= 50 ? 'amber' : 'red'}>
                            {healthData.summary.overall_health_score ?? 0}%
                        </Text>
                    </Flex>
                </Panel>
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Online</Text>
                        <Text size="4" weight="bold" color="green">
                            {healthData.summary.online ?? 0}
                        </Text>
                    </Flex>
                </Panel>
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Offline</Text>
                        <Text size="4" weight="bold" color="red">
                            {healthData.summary.offline ?? 0}
                        </Text>
                    </Flex>
                </Panel>
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Total Devices</Text>
                        <Text size="4" weight="bold">
                            {healthData.summary.total ?? 0}
                        </Text>
                    </Flex>
                </Panel>
            </Grid>

            {/* Filter toolbar */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <Select.Root value={filterStatus} onValueChange={setFilterStatus} size="2">
                    <Select.Trigger style={{ width: 180 }} />
                    <Select.Content>
                        <Select.Item value="all">All Status</Select.Item>
                        <Select.Item value="healthy">Healthy</Select.Item>
                        <Select.Item value="warning">Warning</Select.Item>
                        <Select.Item value="critical">Critical</Select.Item>
                    </Select.Content>
                </Select.Root>
                <Button size="2" variant="soft" color="indigo" onClick={loadHealth} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {/* Health table */}
            {loading ? (
                <Flex justify="center" py="9"><Spinner size="3" /></Flex>
            ) : filteredDevices.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="9" gap="2">
                    <HeartIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No devices found</Text>
                </Flex>
            ) : (
                <Box style={{ overflowX: 'auto' }}>
                    <Table.Root variant="surface" size="2">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Serial</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Health Score</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Last Heartbeat</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Latency</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Uptime</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {filteredDevices.map(device => (
                                <Table.Row key={device.id}>
                                    <Table.Cell>
                                        <Flex direction="column">
                                            <Text weight="bold" size="2">{device.name}</Text>
                                            <Text size="1" color="gray">{device.ip_address}</Text>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{device.serial_number}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={statusColor(device.status)} variant="soft" size="1">
                                            <Flex align="center" gap="1">
                                                {statusIcon(device.status)}
                                                {device.status.toUpperCase()}
                                            </Flex>
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text weight="bold" color={device.health_score >= 80 ? 'green' : device.health_score >= 50 ? 'amber' : 'red'}>
                                            {device.health_score}%
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{formatTime(device.last_heartbeat)}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{device.latency ? `${device.latency}ms` : 'N/A'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{device.uptime_days}d</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}
        </Box>
    );
}

/* ── ATTLOG sub-tab ── */
const STATUS_META = {
    processed:    { color: 'green',  label: 'Processed' },
    unknown_user: { color: 'orange', label: 'Unknown User' },
    failed:       { color: 'red',    label: 'Failed' },
    wrong_device: { color: 'red',    label: 'Wrong Device' },
    duplicate:    { color: 'gray',   label: 'Duplicate' },
    // Captured by a log-download session but NOT yet turned into attendance —
    // the label must not read as if these punches already count.
    downloaded:   { color: 'blue',   label: 'Downloaded (not imported)' },
};

function AttLogTab({ isMobile, devices = [], employees = [] }) {
    const [logs,     setLogs]     = useState([]);
    const [stats,    setStats]    = useState({ total: 0, processed: 0, unknown_user: 0, downloaded: 0, failed: 0 });
    const [loading,  setLoading]  = useState(false);
    /* Search, status, device and page live in the URL under a `bl_` prefix so
       they survive leaving the panel and coming back. The fetch effect below
       follows the URL; nothing else needs to trigger it. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'bl_page',
        debounceKeys: ['bl_q'],
        defaults: { bl_q: '', bl_status: 'all', bl_dev: 'all', bl_page: 1, bl_per: 20 },
    });
    const search   = f.values.bl_q;
    const status   = f.values.bl_status;
    const deviceId = f.values.bl_dev;
    const [total, setTotal] = useState(0);
    const pagination = { currentPage: f.values.bl_page, perPage: f.values.bl_per, total };

    /* ── unknown-user remediation ──
     * These rows are punches whose PIN matched nobody. The importer minted a
     * soft-deleted placeholder for each and parked the row; nothing revisits
     * that state, so without this action they sit forever. */
    const [linkRow,     setLinkRow]     = useState(null);
    const [linkUserId,  setLinkUserId]  = useState('');
    const [linkFilter,  setLinkFilter]  = useState('');
    const [linking,     setLinking]     = useState(false);

    const canLink = hasRoute(ATTLOG_ROUTES.linkUser);

    const linkTarget = useMemo(
        () => employees.find(e => String(e.id) === String(linkUserId)) ?? null,
        [employees, linkUserId],
    );

    // The picker is a plain Select, so it is filtered rather than scrolled — a
    // roster of a few hundred is unusable otherwise. Capped so an accidental
    // empty filter cannot render the whole company at once.
    const linkCandidates = useMemo(() => {
        const q = linkFilter.trim().toLowerCase();
        const matches = employees.filter(e =>
            !q || String(e.name ?? '').toLowerCase().includes(q)
               || String(e.employee_id ?? '').toLowerCase().includes(q));
        return matches.slice(0, 50);
    }, [employees, linkFilter]);

    const openLink = (log) => {
        setLinkRow(log);
        setLinkUserId('');
        setLinkFilter('');
    };

    const submitLink = async () => {
        if (!linkRow || !linkTarget || !canLink) return;
        setLinking(true);
        try {
            const { data } = await axios.post(route(ATTLOG_ROUTES.linkUser), {
                pin: String(linkRow.user_pin),
                user_id: linkTarget.id,
            });
            const relinked = data.logs_relinked ?? data.linked_rows ?? data.relinked ?? 0;
            showToast.success(
                data.message
                ?? `PIN ${linkRow.user_pin} linked to ${linkTarget.name}. ${relinked} punch(es) re-queued for import.`,
            );
            setLinkRow(null);
            // Both the rows and the counters above them are now wrong; the
            // endpoint moved these punches out of unknown_user.
            fetchLogs();
        } catch (e) {
            // The endpoint refuses re-keying an employee who already carries a
            // different ID, a PIN a live user holds, and a PIN with nothing
            // unresolved. Those messages name the exact conflict, so they are
            // surfaced verbatim rather than replaced with a generic failure.
            showToast.error(e.response?.data?.message ?? 'Failed to link this PIN to an employee.');
        } finally {
            setLinking(false);
        }
    };

    const fetchLogs = React.useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.attlogs'), {
                params: {
                    search: search || undefined,
                    status: status !== 'all' ? status : undefined,
                    device_id: deviceId !== 'all' ? deviceId : undefined,
                    page: pagination.currentPage,
                    per_page: pagination.perPage,
                },
            });
            const items = data.logs?.data ?? data.logs ?? [];
            setLogs(items);
            setTotal(data.logs?.total ?? items.length);
            if (data.stats) setStats(data.stats);
        } catch {
            showToast.error('Failed to load att logs.');
        } finally {
            setLoading(false);
        }
    }, [search, status, deviceId, pagination.currentPage, pagination.perPage]);

    React.useEffect(() => { fetchLogs(); }, [fetchLogs]);

    // Each writes the URL once; the hook returns to page 1 on a filter change
    // and the effect above refetches. (Previously the handlers fetched directly
    // *and* the effect fired, so every change issued two requests.)
    const triggerSearch = (val) => f.setDraft('bl_q', val);
    const triggerStatus = (val) => f.set('bl_status', val);
    const triggerDevice = (val) => f.set('bl_dev', val);
    const handlePageChange = f.setPage;
    const handleRowsPerPageChange = (newPerPage) => f.set('bl_per', newPerPage);

    return (
        <Box>
            {/* Stats */}
            <Flex wrap="wrap" gap="2" mb="4">
                <Badge size="2" variant="soft" color="blue"   radius="full"><Text weight="bold">{stats.total}</Text> <Text style={{ opacity: 0.7 }}>Total</Text></Badge>
                <Badge size="2" variant="soft" color="green"  radius="full"><Text weight="bold">{stats.processed}</Text> <Text style={{ opacity: 0.7 }}>Processed</Text></Badge>
                <Badge size="2" variant="soft" color="orange" radius="full"><Text weight="bold">{stats.unknown_user}</Text> <Text style={{ opacity: 0.7 }}>Unknown User</Text></Badge>
                {/*
                  * `downloaded` rows were captured by a log-download session but have NOT
                  * been replayed into attendance. They are not attendance and must never
                  * be read alongside "Processed" as though they counted — hence the
                  * explicit "awaiting import" wording and the pointer at the action that
                  * actually converts them.
                  */}
                <Tooltip content="Punches pulled off a device by a download session. They are NOT attendance yet — run Import on the session in the Downloads tab to turn them into attendance records.">
                    <Badge size="2" variant="soft" color="blue" radius="full">
                        <Text weight="bold">{stats.downloaded ?? 0}</Text> <Text style={{ opacity: 0.7 }}>Downloaded — awaiting import</Text>
                    </Badge>
                </Tooltip>
                <Badge size="2" variant="soft" color="red"    radius="full"><Text weight="bold">{stats.failed}</Text> <Text style={{ opacity: 0.7 }}>Failed/Rejected</Text></Badge>
                <Button size="1" variant="soft" color="gray" ml="auto" onClick={() => fetchLogs()}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
            </Flex>

            {/* Unknown-user backlog. These rows never resolve themselves — nothing
              * in the pipeline revisits the unknown_user state — so an admin has
              * to be told the pile exists and how to clear it. */}
            {(stats.unknown_user ?? 0) > 0 && (
                <Callout.Root color="orange" mb="3" size="1">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>
                        <strong>{stats.unknown_user} punch(es) matched no employee.</strong> Each arrived for a
                        device PIN nobody carries, so a soft-deleted placeholder was created and the punch was
                        parked. Nothing revisits this state automatically — they stay out of attendance until
                        the PIN is linked to a real employee.
                        {!canLink && <> The <Code size="1">{ATTLOG_ROUTES.linkUser}</Code> endpoint is not
                            registered on this server yet, so they cannot be resolved from here.</>}
                        {canLink && employees.length === 0 && <> No employee list reached this page, so the
                            picker has nobody to offer — open Settings → Biometric Devices, which loads one.</>}
                    </Callout.Text>
                </Callout.Root>
            )}

            {/* Filters */}
            <Flex gap="3" mb="3" wrap="wrap" align="center">
                <TextField.Root placeholder="Search PIN or name…" size="2" style={{ maxWidth: 280 }}
                    value={f.draft.bl_q}
                    onChange={e => triggerSearch(e.target.value)}>
                    <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
                <Select.Root size="2" value={status} onValueChange={triggerStatus}>
                    <Select.Trigger style={{ width: 160 }} />
                    <Select.Content>
                        <Select.Item value="all">All Status</Select.Item>
                        <Select.Item value="processed">Processed</Select.Item>
                        <Select.Item value="unknown_user">Unknown User</Select.Item>
                        <Select.Item value="failed">Failed</Select.Item>
                        <Select.Item value="wrong_device">Wrong Device</Select.Item>
                        <Select.Item value="duplicate">Duplicate</Select.Item>
                        <Select.Item value="downloaded">Downloaded (not imported)</Select.Item>
                    </Select.Content>
                </Select.Root>
                <Select.Root size="2" value={deviceId} onValueChange={triggerDevice}>
                    <Select.Trigger style={{ width: 200 }} placeholder="Filter by device" />
                    <Select.Content>
                        <Select.Item value="all">All Devices</Select.Item>
                        {devices.map(d => (
                            <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
                {loading && <Spinner size="2" />}
                <Text size="1" color="gray" ml="auto">{pagination.total} records</Text>
            </Flex>

            {/* Table */}
            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface" size="1">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>PIN</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Punch Time</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            {!isMobile && <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>}
                            <Table.ColumnHeaderCell>Action</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {logs.map(log => {
                            const meta = STATUS_META[log.punch_status] ?? { color: 'gray', label: log.punch_status };
                            const isUnknown = log.punch_status === 'unknown_user';
                            return (
                                <Table.Row key={log.id} style={isUnknown ? { background: 'var(--orange-a2)' } : undefined}>
                                    <Table.Cell>
                                        <Code size="1" variant="soft">{log.user_pin}</Code>
                                    </Table.Cell>
                                    <Table.Cell>
                                        {log.user ? (
                                            <Flex direction="column">
                                                <Text size="1" weight="medium">{log.user.name}</Text>
                                                {isUnknown && (
                                                    <Badge size="1" color="orange" variant="soft" radius="full">Auto-created</Badge>
                                                )}
                                            </Flex>
                                        ) : (
                                            <Text size="1" color="gray">—</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1" color="gray">{log.device?.name ?? log.serial_number}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="1">{log.punch_time ? new Date(log.punch_time).toLocaleString() : '—'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color={log.check_type === 'out' ? 'red' : 'green'} radius="full">
                                            {log.check_type?.toUpperCase() ?? 'IN'}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" variant="soft" color={meta.color} radius="full">{meta.label}</Badge>
                                    </Table.Cell>
                                    {!isMobile && (
                                        <Table.Cell>
                                            <Text size="1" color="gray" style={{ maxWidth: 260, display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                {log.punch_status_reason ?? '—'}
                                            </Text>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell>
                                        {/* Only unknown_user rows are actionable: every other status
                                          * already resolved to somebody, and re-keying an employee
                                          * is not something to offer where there is nothing wrong. */}
                                        {isUnknown ? (
                                            <Tooltip content={
                                                !canLink
                                                    ? 'The link-user endpoint is not registered on this server yet.'
                                                    : employees.length === 0
                                                        ? 'No employee list is available on this page, so there is nobody to pick.'
                                                        : `Attribute PIN ${log.user_pin} to a real employee and re-queue its punches.`
                                            }>
                                                <Button
                                                    size="1"
                                                    variant="soft"
                                                    color="orange"
                                                    disabled={!canLink || employees.length === 0 || !log.user_pin}
                                                    onClick={() => openLink(log)}
                                                >
                                                    <Link2Icon /> Link to employee
                                                </Button>
                                            </Tooltip>
                                        ) : (
                                            <Text size="1" color="gray">—</Text>
                                        )}
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}
                        {!loading && logs.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={isMobile ? 7 : 8}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No att logs found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}

            {/* Link-to-employee confirmation.
              *
              * This is not a labelling fix. It re-keys an identity: the chosen
              * employee takes this PIN as their employee_id, which is what every
              * biometric punch — past and future — is attributed by. So the
              * dialog spells out both halves of the consequence before the
              * button is live, and the server refuses the dangerous cases
              * (employee already carries a different ID, PIN held by a live or
              * a genuinely-deleted user) regardless of what is clicked here. */}
            <Dialog.Root open={Boolean(linkRow)} onOpenChange={o => { if (!o) setLinkRow(null); }}>
                <Dialog.Content style={{ maxWidth: 540 }}>
                    <Dialog.Title>Link PIN {linkRow?.user_pin} to an employee</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        This punch arrived for a device PIN no employee carries, so the importer parked it
                        and created a placeholder. Pick the person the PIN actually belongs to.
                    </Dialog.Description>

                    <Box mt="4">
                        <Text size="2" weight="medium" as="div" mb="1">Employee</Text>
                        <TextField.Root
                            size="2"
                            placeholder="Filter by name or employee ID…"
                            value={linkFilter}
                            onChange={e => setLinkFilter(e.target.value)}
                            mb="2"
                        >
                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                        </TextField.Root>
                        <Select.Root size="2" value={linkUserId} onValueChange={setLinkUserId}>
                            <Select.Trigger style={{ width: '100%' }} placeholder="Select an employee" />
                            <Select.Content>
                                {linkCandidates.map(e => (
                                    <Select.Item key={e.id} value={String(e.id)}>
                                        {e.name}{e.employee_id ? ` — ${e.employee_id}` : ' — no employee ID'}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                        {linkCandidates.length === 0 && (
                            <Text size="1" color="gray" as="div" mt="1">
                                No employee matches this filter.
                            </Text>
                        )}
                        {employees.length > linkCandidates.length && (
                            <Text size="1" color="gray" as="div" mt="1">
                                Showing {linkCandidates.length} of {employees.length} employees — narrow the filter to see the rest.
                            </Text>
                        )}
                    </Box>

                    <Callout.Root color="amber" mt="3" size="1">
                        <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                        <Callout.Text>
                            Confirming does two things at once:
                            {' '}<strong>(1)</strong> it sets{' '}
                            {linkTarget ? <strong>{linkTarget.name}</strong> : 'the chosen employee'}'s
                            {' '}<Code size="1">employee_id</Code> to <Code size="1">{linkRow?.user_pin}</Code>,
                            so every future punch from this PIN resolves to them automatically; and
                            {' '}<strong>(2)</strong> it re-points the stranded punches for this PIN and puts them
                            back in the <Code size="1">downloaded</Code> state, which re-queues them for import
                            into attendance. Past attendance is re-attributed by that ID.
                        </Callout.Text>
                    </Callout.Root>

                    {linkTarget?.employee_id && String(linkTarget.employee_id) !== String(linkRow?.user_pin ?? '') && (
                        <Callout.Root color="red" mt="3" size="1">
                            <Callout.Icon><CrossCircledIcon /></Callout.Icon>
                            <Callout.Text>
                                {linkTarget.name} already carries employee ID{' '}
                                <Code size="1">{linkTarget.employee_id}</Code>. Linking a different PIN would
                                re-key them and silently move their attendance history, so the server will
                                refuse this. Fix the employee record first if the ID is genuinely wrong.
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    <Text size="1" color="gray" as="div" mt="3">
                        The punches are re-queued, not imported on the spot —{' '}
                        <Code size="1">biometric:import-downloaded</Code> replays them into attendance on its
                        next run.
                    </Text>

                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button onClick={submitLink} disabled={!canLink || linking || !linkTarget}>
                            {linking ? <><Spinner size="1" /> Linking…</> : <><Link2Icon /> Link and re-queue</>}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── Downloads sub-tab ── */
function DownloadsTab({ isMobile, devices = [] }) {
    const [sessions, setSessions] = useState([]);
    const [loading, setLoading] = useState(false);
    /* Device filter and page live in the URL under a `dl_` prefix so they
       survive leaving the panel and coming back. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'dl_page',
        debounceKeys: [],
        defaults: { dl_dev: 'all', dl_page: 1, dl_per: 20 },
    });
    const selectedDevice = f.values.dl_dev;
    const [total, setTotal] = useState(0);
    const pagination = { currentPage: f.values.dl_page, perPage: f.values.dl_per, total };
    const [downloadingSessionLogs, setDownloadingSessionLogs] = useState(null);
    const [importingSession, setImportingSession] = useState(null);

    const downloadSessionPunches = async (session) => {
        setDownloadingSessionLogs(session.id);
        try {
            const { data } = await axios.get(route('biometric-devices.download-sessions.logs', session.id));
            const groupedRows = data.logs ?? [];
            if (groupedRows.length === 0) {
                showToast.info('No attendance logs found for this session.');
                return;
            }

            const exportData = groupedRows.map(r => ({
                'Employee ID': r.pin,
                'Employee Name': r.name,
                'Date': r.date,
                'In Time': r.inTime,
                'Out Time': r.outTime
            }));

            const worksheet = XLSX.utils.json_to_sheet(exportData);
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, 'Attendance Report');
            
            const filename = `attendance_report_device_${session.device?.name.replace(/\s+/g, '_')}_session_${session.id}.xlsx`;
            XLSX.writeFile(workbook, filename);
            showToast.success('Attendance report downloaded successfully.');
        } catch (err) {
            console.error('Failed to download session logs:', err);
            showToast.error('Failed to download attendance logs.');
        } finally {
            setDownloadingSessionLogs(null);
        }
    };

    const downloadSessionPunchesPDF = async (session) => {
        setDownloadingSessionLogs(session.id);
        try {
            const { data } = await axios.get(route('biometric-devices.download-sessions.logs', session.id));
            const groupedRows = data.logs ?? [];
            if (groupedRows.length === 0) {
                showToast.info('No attendance logs found for this session.');
                return;
            }

            const doc = new jsPDF({ orientation: 'portrait' });
            
            // Title
            doc.setFontSize(16);
            doc.text('Biometric Attendance Logs Download Report', 14, 15);
            doc.setFontSize(10);
            
            // Meta Info
            doc.text(`Device: ${session.device?.name || 'Unknown'} (${session.device?.serial_number || '—'})`, 14, 22);
            doc.text(`Session ID: ${session.id} | Trigger: ${session.trigger_type.toUpperCase()}`, 14, 28);
            doc.text(`Date Range: ${session.command?.payload?.start_time && session.command?.payload?.end_time ? `${session.command.payload.start_time} to ${session.command.payload.end_time}` : 'Full Sync'}`, 14, 34);
            doc.text(`Generated on: ${new Date().toLocaleString()}`, 14, 40);

            const tableRows = groupedRows.map(r => [
                r.pin,
                r.name,
                r.date,
                r.inTime,
                r.outTime
            ]);

            doc.autoTable({
                startY: 46,
                head: [['Employee ID', 'Employee Name', 'Date', 'In Time', 'Out Time']],
                body: tableRows,
                theme: 'striped',
                headStyles: { fillColor: [43, 108, 176] }
            });

            const filename = `attendance_report_device_${session.device?.name.replace(/\s+/g, '_')}_session_${session.id}.pdf`;
            doc.save(filename);
            showToast.success('PDF report downloaded successfully.');
        } catch (err) {
            console.error('Failed to download session logs PDF:', err);
            showToast.error('Failed to download PDF logs.');
        } finally {
            setDownloadingSessionLogs(null);
        }
    };

    const fetchHistory = useCallback(async () => {
        const deviceFilter = selectedDevice;
        const page = pagination.currentPage;
        const pp = pagination.perPage;
        setLoading(true);
        try {
            const { data } = await axios.get(route('biometric-devices.download-history'), {
                params: {
                    device_id: deviceFilter !== 'all' ? deviceFilter : undefined,
                    page: page,
                    per_page: pp,
                }
            });
            const items = data.sessions?.data ?? data.sessions ?? [];
            setSessions(items);
            setTotal(data.sessions?.total ?? items.length);
        } catch (e) {
            showToast.error('Failed to load download history.');
        } finally {
            setLoading(false);
        }
    }, [selectedDevice, pagination.currentPage, pagination.perPage]);

    // Downloaded punches sit in biometric_att_logs with punch_status = 'downloaded';
    // they are NOT attendance until this import turns them into records.
    const importSessionLogs = async (session) => {
        if (!confirm(
            `Import the punches from session #${session.id} (${session.device?.name ?? 'Unknown device'}) into attendance?\n\n`
            + 'This writes attendance records. Already-imported punches are skipped as duplicates.'
        )) return;

        setImportingSession(session.id);
        try {
            const { data } = await axios.post(route('biometric-devices.download-sessions.import', session.id));
            // The endpoint's own message already spells out the counts; only compose
            // one if it is missing, so the toast never repeats itself.
            showToast.success(
                data.message
                ?? `Imported ${data.imported ?? 0} punch(es). ${data.duplicates ?? 0} duplicate(s), `
                   + `${data.skipped_unknown ?? 0} skipped (unknown user), ${data.failed ?? 0} failed.`
            );
            fetchHistory();
        } catch (err) {
            showToast.error(err.response?.data?.message ?? 'Failed to import session logs into attendance.');
        } finally {
            setImportingSession(null);
        }
    };

    // Follows the URL.
    useEffect(() => {
        fetchHistory();
    }, [fetchHistory]);

    // Auto-refresh every 10 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            fetchHistory();
        }, 10000);
        return () => clearInterval(interval);
    }, [fetchHistory]);

    const handlePageChange = f.setPage;
    const handleRowsPerPageChange = (newPerPage) => f.set('dl_per', newPerPage);

    const handleDeviceFilterChange = (val) => f.set('dl_dev', val);

    // Calculate summary statistics
    const stats = useMemo(() => {
        const total = pagination.total;
        const completed = sessions.filter(s => s.status === 'completed').length;
        const inProgress = sessions.filter(s => s.status === 'in_progress' || s.status === 'pending').length;
        const failed = sessions.filter(s => s.status === 'failed').length;
        return { total, completed, inProgress, failed };
    }, [sessions, pagination.total]);

    const statusBadge = (status) => {
        const config = {
            pending: { color: 'yellow', label: 'Pending' },
            in_progress: { color: 'blue', label: 'In Progress' },
            completed: { color: 'green', label: 'Completed' },
            failed: { color: 'red', label: 'Failed' },
            partial: { color: 'orange', label: 'Partial' }
        }[status] ?? { color: 'gray', label: status };

        return <Badge color={config.color} variant="soft" size="1">{config.label.toUpperCase()}</Badge>;
    };

    const triggerBadge = (trigger) => {
        const config = {
            manual: { color: 'plum', label: 'Manual' },
            scheduled: { color: 'cyan', label: 'Scheduled' },
            reconnect: { color: 'indigo', label: 'Reconnect' },
            bulk: { color: 'violet', label: 'Bulk' }
        }[trigger] ?? { color: 'gray', label: trigger };

        return <Badge color={config.color} variant="soft" size="1">{config.label}</Badge>;
    };

    const formatDuration = (start, end) => {
        if (!start || !end) return '—';
        const ms = new Date(end) - new Date(start);
        const sec = Math.max(0, Math.floor(ms / 1000));
        if (sec < 60) return `${sec}s`;
        return `${Math.floor(sec / 60)}m ${sec % 60}s`;
    };

    return (
        <Box>
            {/* Stats Summary Cards */}
            <Grid columns={{ initial: '3', sm: '3' }} gap="3" mb="4">
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Total Sessions</Text>
                        <Text size="4" weight="bold">{stats.total}</Text>
                    </Flex>
                </Panel>
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">In Progress / Pending</Text>
                        <Text size="4" weight="bold" color="blue">{stats.inProgress}</Text>
                    </Flex>
                </Panel>
                <Panel variant="surface">
                    <Flex direction="column" gap="1">
                        <Text size="1" color="gray">Completed Successfully</Text>
                        <Text size="4" weight="bold" color="green">{stats.completed}</Text>
                    </Flex>
                </Panel>
            </Grid>

            {/* Filter toolbar */}
            <Flex gap="3" mb="3" wrap="wrap" align="center">
                <Select.Root size="2" value={selectedDevice} onValueChange={handleDeviceFilterChange}>
                    <Select.Trigger style={{ width: 220 }} placeholder="Filter by device" />
                    <Select.Content>
                        <Select.Item value="all">All Devices</Select.Item>
                        {devices.filter(d => d.protocol === 'adms').map(d => (
                            <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
                <Button size="1" variant="soft" color="gray" onClick={() => fetchHistory()} disabled={loading}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                </Button>
                {(loading || downloadingSessionLogs || importingSession) && <Spinner size="2" />}
            </Flex>

            {/* Downloads Table */}
            <Box style={{ overflowX: 'auto' }}>
                <Table.Root variant="surface" size="2">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Device</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Date Range</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Trigger</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Records (Processed/Total)</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Duplicates</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Failed</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Duration</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Started At</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Initiated By</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Action</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {sessions.map(session => (
                            <Table.Row key={session.id}>
                                <Table.Cell>
                                    <Flex direction="column">
                                        <Text weight="bold" size="2">{session.device?.name ?? '—'}</Text>
                                        <Text size="1" color="gray">{session.device?.serial_number ?? '—'}</Text>
                                    </Flex>
                                </Table.Cell>
                                <Table.Cell>
                                    {session.command?.payload?.start_time && session.command?.payload?.end_time ? (
                                        <Tooltip content={`${session.command.payload.start_time} to ${session.command.payload.end_time}`}>
                                            <Text size="1" style={{ whiteSpace: 'nowrap' }}>
                                                {session.command.payload.start_time.split(' ')[0]} to {session.command.payload.end_time.split(' ')[0]}
                                            </Text>
                                        </Tooltip>
                                    ) : (
                                        <Badge color="gray" variant="soft">Full Sync</Badge>
                                    )}
                                </Table.Cell>
                                <Table.Cell>{triggerBadge(session.trigger_type)}</Table.Cell>
                                <Table.Cell>
                                    <Flex direction="column" gap="1" align="start">
                                        {statusBadge(session.status)}
                                        {session.error_message && (
                                            <Tooltip content={session.error_message}>
                                                <Text size="1" color="red" style={{ maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {session.error_message}
                                                </Text>
                                            </Tooltip>
                                        )}
                                    </Flex>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2">{session.processed_count} / {session.total_records}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2" color="gray">{session.duplicate_count}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2" color={session.failed_count > 0 ? 'red' : 'gray'}>{session.failed_count}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2">{formatDuration(session.started_at, session.completed_at)}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="1" color="gray">
                                        {session.created_at ? new Date(session.created_at).toLocaleString() : '—'}
                                    </Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="1">{session.creator?.name ?? 'System'}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    {(session.status === 'completed' || session.status === 'partial') ? (
                                        <Flex gap="2">
                                            <Tooltip content="Download Excel Logs">
                                                <IconButton
                                                    size="1"
                                                    variant="soft"
                                                    color="green"
                                                    onClick={() => downloadSessionPunches(session)}
                                                    disabled={downloadingSessionLogs === session.id}
                                                >
                                                    {downloadingSessionLogs === session.id ? <Spinner size="1" /> : <DownloadIcon />}
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip content="Download PDF Logs">
                                                <IconButton
                                                    size="1"
                                                    variant="soft"
                                                    color="red"
                                                    onClick={() => downloadSessionPunchesPDF(session)}
                                                    disabled={downloadingSessionLogs === session.id}
                                                >
                                                    {downloadingSessionLogs === session.id ? <Spinner size="1" /> : <DownloadIcon />}
                                                </IconButton>
                                            </Tooltip>
                                            <Tooltip content="Import downloaded punches into attendance">
                                                <IconButton
                                                    size="1"
                                                    variant="soft"
                                                    color="indigo"
                                                    onClick={() => importSessionLogs(session)}
                                                    disabled={importingSession === session.id}
                                                >
                                                    {importingSession === session.id ? <Spinner size="1" /> : <ArrowRightIcon />}
                                                </IconButton>
                                            </Tooltip>
                                        </Flex>
                                    ) : (
                                        <Text size="1" color="gray">—</Text>
                                    )}
                                </Table.Cell>
                            </Table.Row>
                        ))}
                        {sessions.length === 0 && !loading && (
                            <Table.Row>
                                <Table.Cell colSpan={11}>
                                    <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                        No download sessions found.
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Pagination */}
            {pagination.total > 0 && (
                <TablePagination
                    pagination={pagination}
                    onPageChange={handlePageChange}
                    onRowsPerPageChange={handleRowsPerPageChange}
                    loading={loading}
                />
            )}
        </Box>
    );
}

/* ── Capabilities & Device Settings sub-tab ── */

/**
 * One capacity meter. Refuses to draw a bar it cannot honestly draw.
 *
 * This is a capacity-planning surface, so a confidently wrong percentage here is
 * worse than a blank: an admin acts on "94% full" and does not act on "unknown".
 * Every path that cannot produce a trustworthy ratio therefore drops the bar and
 * says why, in the caller's own words where it has better ones.
 *
 *  - `supported === false`   the device reported no such store (−1004, or the
 *                            engine switched off) → "not available on this model"
 *  - `known === false`       never probed → "—", explicitly not "zero"
 *  - `used === null`         probed, but this counter went unanswered
 *  - no usable maximum       show the live count, headroom unknown
 *  - `used > max`            the denominator is provably not a record count —
 *                            a real MB460 answers FPCount = 26 against
 *                            ~MaxFingerCount = 20, because some maxima are
 *                            expressed in thousands. Dividing gives 130%;
 *                            clamping gives a full red bar on a near-empty
 *                            device. Both are lies, so neither is drawn.
 */
function CapacityMeter({ label, used, max, reportedMax = null, supported = true, known = true, unsupportedNote }) {
    const shell = (children) => (
        <Panel variant="surface">
            <Flex direction="column" gap="1">
                <Text size="1" color="gray">{label}</Text>
                {children}
            </Flex>
        </Panel>
    );

    // The device told us this store does not exist. Permanent hardware fact.
    if (supported === false) {
        return shell(
            <>
                <Badge color="amber" variant="soft" size="1" style={{ width: 'fit-content' }}>
                    Not available on this model
                </Badge>
                <Text size="1" color="gray">
                    {unsupportedNote
                        ?? 'The device reported no such store — it answered −1004, or the matching engine is switched off. This is a fact about the hardware, not a count of zero.'}
                </Text>
            </>,
        );
    }

    // Supported, but nothing has ever been asked. Distinct from "zero enrolled".
    if (known === false) {
        return shell(
            <>
                <Text size="4" weight="bold" color="gray">—</Text>
                <Text size="1" color="gray">Never probed. Blank means "not asked", not "zero".</Text>
            </>,
        );
    }

    if (used === null || used === undefined) {
        return shell(
            <>
                <Text size="4" weight="bold" color="gray">—</Text>
                <Text size="1" color="gray">Not reported by the device.</Text>
            </>,
        );
    }

    const usable = (typeof max === 'number' && Number.isFinite(max) && max > 0) ? max : null;

    if (usable === null) {
        // The device may well have reported *a* figure that simply could not be
        // used as a denominator — either its unit is undeclared, or it is below
        // the live count. Saying "did not report" in that case would be false, so
        // the reported figure is shown and named as unusable instead.
        const withheld = (typeof reportedMax === 'number' && Number.isFinite(reportedMax) && reportedMax > 0);
        return shell(
            <>
                <Text size="4" weight="bold">{used.toLocaleString()}</Text>
                {withheld ? (
                    <>
                        <Badge color="amber" variant="soft" size="1" style={{ width: 'fit-content' }}>
                            Maximum unusable
                        </Badge>
                        <Text size="1" color="amber">
                            The device reported a maximum of {reportedMax.toLocaleString()}, which cannot be
                            divided into: some ZKTeco maxima are literal counts and others are expressed in
                            thousands, and this key's unit is undeclared
                            {reportedMax < used ? ` — it is also below the live count of ${used.toLocaleString()}` : ''}.
                            Headroom unknown; no percentage is shown rather than a wrong one.
                        </Text>
                    </>
                ) : (
                    <Text size="1" color="gray">Device did not report a usable maximum — headroom unknown.</Text>
                )}
            </>,
        );
    }

    // A maximum below the live count cannot be a maximum. Refuse the ratio
    // rather than clamp it: 100% on an almost-empty terminal is the single most
    // misleading thing this screen could say.
    if (used > usable) {
        return shell(
            <>
                <Text size="4" weight="bold">{used.toLocaleString()}</Text>
                <Flex align="center" gap="1" wrap="wrap">
                    <Badge color="amber" variant="soft" size="1">Maximum unusable</Badge>
                </Flex>
                <Text size="1" color="amber">
                    The device reported a maximum of {usable.toLocaleString()}, below the live count of{' '}
                    {used.toLocaleString()} — so that figure is not a record count (some ZKTeco maxima
                    are expressed in thousands). Headroom unknown; no percentage is shown rather than a
                    wrong one.
                </Text>
            </>,
        );
    }

    const pct = Math.min(100, Math.round((used / usable) * 100));
    const color = pct >= 90 ? 'red' : pct >= 75 ? 'amber' : 'green';

    return (
        <Panel variant="surface">
            <Flex direction="column" gap="2">
                <Text size="1" color="gray">{label}</Text>
                <Flex align="baseline" gap="2">
                    <Text size="4" weight="bold" color={color}>{used.toLocaleString()}</Text>
                    <Text size="1" color="gray">/ {usable.toLocaleString()}</Text>
                </Flex>
                <Progress value={pct} color={color} size="1" />
                <Text size="1" color={color}>{pct}% used · {(usable - used).toLocaleString()} free</Text>
            </Flex>
        </Panel>
    );
}

/**
 * One catalogue-driven control.
 *
 * `state` is the device's own answer about this key and drives whether the
 * control is offered at all. The three unusable states are deliberately worded
 * differently (see KEY_STATE_META): "this model cannot", "the device declined to
 * answer" and "nobody has asked yet" lead to three different next actions, and
 * collapsing them into one grey box loses the only information that decides
 * which one to take.
 */
function SettingField({ entry, value, dirty, state = 'unprobed', locked, queuedValue, onChange }) {
    // Both -1004 and a silent omission mean the key must not be offered; only
    // the explanation differs. An unprobed key stays editable — asking for it is
    // exactly how it stops being unprobed.
    const unsupported = state === 'unsupported' || state === 'omitted';
    const disabled = unsupported || locked;
    const heading = PROMINENT_KEYS[entry.key]?.heading ?? entry.label;

    let control;
    if (isBoolType(entry.type)) {
        control = (
            <Switch
                size="2"
                checked={value === '1'}
                disabled={disabled}
                onCheckedChange={v => onChange(entry.key, v ? '1' : '0')}
            />
        );
    } else if (Array.isArray(entry.choices) && entry.choices.length > 0) {
        control = (
            <Select.Root size="2" value={value || undefined} disabled={disabled} onValueChange={v => onChange(entry.key, v)}>
                <Select.Trigger style={{ width: '100%' }} placeholder="Not set" />
                <Select.Content>
                    {entry.choices.map(c => {
                        const val = String(typeof c === 'object' ? (c.value ?? c.key) : c);
                        const lbl = typeof c === 'object' ? (c.label ?? val) : val;
                        return <Select.Item key={val} value={val}>{lbl}</Select.Item>;
                    })}
                </Select.Content>
            </Select.Root>
        );
    } else {
        control = (
            <TextField.Root
                size="2"
                type={isNumberType(entry.type) ? 'number' : 'text'}
                value={value}
                disabled={disabled}
                min={entry.min ?? undefined}
                max={entry.max ?? undefined}
                placeholder={unsupported ? 'Unavailable' : 'Not set'}
                onChange={e => onChange(entry.key, e.target.value)}
            />
        );
    }

    return (
        <Box>
            <Flex align="center" gap="2" mb="1" wrap="wrap">
                <Text size="2" weight="medium">{heading}</Text>
                <Code size="1" variant="soft">{entry.key}</Code>
                {entry.unit && <Text size="1" color="gray">({entry.unit})</Text>}
                <Tooltip content={KEY_STATE_NOTE[state] ?? 'The device returned a value for this key when it was last probed.'}>
                    <Badge color={KEY_STATE_META[state]?.color ?? 'gray'} variant="soft" size="1">
                        {KEY_STATE_META[state]?.label ?? state}
                    </Badge>
                </Tooltip>
                {dirty && <Badge color="indigo" variant="soft" size="1">Changed</Badge>}
                {queuedValue !== undefined && (
                    <Tooltip content="A SET OPTION command carrying this value is waiting for the device to poll.">
                        <Badge color="cyan" variant="soft" size="1">Queued: {queuedValue}</Badge>
                    </Tooltip>
                )}
            </Flex>
            {control}
            {/* Unusable keys are greyed out WITH the reason rather than hidden
              * (docs §5.3): an admin must be able to tell "this unit cannot do it"
              * apart from "the device ignored the question" apart from "the UI is
              * broken". Each gets its own sentence — see KEY_STATE_NOTE. */}
            {unsupported ? (
                <Text size="1" color={KEY_STATE_META[state]?.color ?? 'amber'} as="div" mt="1">
                    {KEY_STATE_NOTE[state]}
                </Text>
            ) : PROMINENT_KEYS[entry.key]?.note ? (
                <Text size="1" color="gray" as="div" mt="1">{PROMINENT_KEYS[entry.key].note}</Text>
            ) : entry.description ? (
                <Text size="1" color="gray" as="div" mt="1">{entry.description}</Text>
            ) : null}
        </Box>
    );
}

/**
 * Normalise one stored-template row. The endpoint is written by another agent,
 * so both the eager-loaded relation shape and a flattened one are accepted; a
 * field that is genuinely absent renders as "—" rather than "undefined".
 *
 * `template_data` is deliberately absent from this mapping. The API does not
 * return the raw template and this view must never ask for, display, or offer a
 * download of one — it is the biometric itself, not a reference to it.
 */
const normaliseTemplate = (t, i) => ({
    id:           t.id ?? `${t.device_user_id ?? t.pin ?? 'row'}-${t.template_type ?? ''}-${i}`,
    userId:       t.user_id ?? t.user?.id ?? null,
    employeeName: t.user?.name ?? t.employee?.name ?? t.user_name ?? t.employee_name ?? null,
    employeeCode: t.user?.employee_id ?? t.employee?.employee_id ?? t.employee_id ?? t.employee_code ?? null,
    pin:          t.pin ?? t.device_user_id ?? t.user_pin ?? null,
    type:         String(t.template_type ?? t.type ?? '').toLowerCase(),
    fingerIndex:  t.finger_index ?? null,
    size:         t.template_size ?? t.size ?? null,
    version:      t.template_version ?? null,
    // The terminal this template was captured from — i.e. the unit that is
    // holding it. An on-device delete is addressed at exactly that unit, so the
    // id has to survive normalisation, not just the display name.
    deviceId:     t.source_device_id ?? t.device?.id ?? t.biometric_device_id ?? null,
    deviceName:   t.source_device_name ?? t.device?.name ?? t.device_name ?? null,
    deviceSerial: t.source_device_serial ?? t.device?.serial_number ?? t.serial_number ?? null,
    capturedAt:   t.captured_at ?? t.created_at ?? t.updated_at ?? null,
    // The endpoint says outright which rows a restore can actually carry.
    // Absent (older payload) is treated as "fingerprint only", which is what
    // DATA UPDATE FINGERTMP can express — never as "everything is restorable".
    restorable:   t.restorable ?? (String(t.template_type ?? t.type ?? '').toLowerCase() === 'fingerprint'),
    notRestorableReason: t.not_restorable_reason ?? null,
});

/**
 * Stored biometric templates, and the one action that can put them back.
 *
 * ── Why this lives inside the Capabilities sub-tab ───────────────────────────
 * A ninth top-level tab was the obvious move and is the wrong one. Templates are
 * not a separate subject from capability — they are the same subject seen from
 * the other side. The Capacity grid two blocks up says "this terminal holds 26
 * fingerprints and 0 faces"; this table says "and we hold templates for 13
 * people". Those two numbers are only meaningful next to each other, and the
 * question an admin actually arrives with — "this unit was wiped/replaced, can I
 * put the enrolments back?" — needs both halves plus a target device.
 *
 * That target is the deciding argument. `restore-templates` takes a device id,
 * and this sub-tab is the only place in the panel with a device already in hand.
 * Splitting the two halves across two tabs would mean answering one question in
 * two places, and would take the tab strip to nine — already past what fits on a
 * phone at eight.
 *
 * It is rendered outside the capability-endpoint guard on purpose: templates
 * have been captured since day one and are readable whether or not the newer
 * probe/settings routes exist yet.
 */
function TemplatesSection({ devices = [] }) {
    const admsDevices = useMemo(() => devices.filter(d => d.protocol === 'adms'), [devices]);

    const [rows, setRows]         = useState([]);
    const [loading, setLoading]   = useState(false);
    const [loaded, setLoaded]     = useState(false);
    /* Search and type filter live in the URL under a `tp_` prefix. */
    const f = useQueryFilters({
        mode: 'client',
        debounceKeys: ['tp_q'],
        defaults: { tp_q: '', tp_type: 'all' },
    });
    const search = f.values.tp_q;
    const typeFilter = f.values.tp_type;
    const setType = (v) => f.set('tp_type', v);

    const [restoreOpen, setRestoreOpen] = useState(false);
    const [targetId, setTargetId]       = useState('');
    const [acknowledged, setAck]        = useState(false);
    const [restoring, setRestoring]     = useState(false);
    const [result, setResult]           = useState(null);

    // On-device delete. Row-scoped, so it has its own dialog state.
    const [deleteRow, setDeleteRow]       = useState(null);
    const [deleteScope, setDeleteScope]   = useState(DELETE_SCOPE.single);
    const [deleteAck, setDeleteAck]       = useState(false);
    const [deleting, setDeleting]         = useState(false);
    const [deleteResult, setDeleteResult] = useState(null);

    const canList    = hasRoute(TEMPLATE_ROUTES.list);
    const canRestore = hasRoute(TEMPLATE_ROUTES.restore);
    const canDelete  = hasRoute(TEMPLATE_ROUTES.delete);

    const targetDevice = useMemo(
        () => admsDevices.find(d => String(d.id) === String(targetId)) ?? null,
        [admsDevices, targetId],
    );

    const load = useCallback(async () => {
        if (!hasRoute(TEMPLATE_ROUTES.list)) return;
        setLoading(true);
        try {
            const { data } = await axios.get(route(TEMPLATE_ROUTES.list));
            const raw = data.templates?.data ?? data.templates ?? data.data ?? (Array.isArray(data) ? data : []);
            setRows((Array.isArray(raw) ? raw : []).map(normaliseTemplate));
        } catch {
            showToast.error('Failed to load stored biometric templates.');
        } finally {
            setLoaded(true);
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter(r => {
            if (typeFilter !== 'all' && r.type !== typeFilter) return false;
            if (!q) return true;
            return [r.employeeName, r.employeeCode, r.pin, r.deviceName, r.deviceSerial]
                .some(v => v && String(v).toLowerCase().includes(q));
        });
    }, [rows, search, typeFilter]);

    const summary = useMemo(() => {
        const employees = new Set();
        const byType = {};
        let restorable = 0;
        rows.forEach(r => {
            if (r.pin) employees.add(String(r.pin));
            byType[r.type || 'unknown'] = (byType[r.type || 'unknown'] ?? 0) + 1;
            if (r.restorable) restorable++;
        });
        return { total: rows.length, employees: employees.size, byType, restorable };
    }, [rows]);

    const openRestore = () => {
        setResult(null);
        setAck(false);
        setTargetId(admsDevices.length === 1 ? String(admsDevices[0].id) : '');
        setRestoreOpen(true);
    };

    const submitRestore = async () => {
        if (!targetDevice || !canRestore) return;
        setRestoring(true);
        try {
            // `confirm_restore` is not decoration: the endpoint refuses with 422
            // unless it is explicitly true, which is the server half of the same
            // gate as the acknowledgement checkbox above. Omitting user_ids means
            // "every user we hold a template for" — the fleet-replacement case
            // this screen exists for.
            const { data } = await axios.post(route(TEMPLATE_ROUTES.restore, targetDevice.id), {
                confirm_restore: true,
            });
            setResult({
                queued:  toCount(data.queued),
                skipped: toCount(data.skipped),
                users:   Array.isArray(data.users) ? data.users.length : toCount(data.users),
                reasons: normaliseReasons(data.reasons),
                message: data.message ?? null,
            });
            setAck(false);
            // Deliberately not "restored" and deliberately no progress indicator:
            // nothing has reached the hardware. ADMS is device-initiated, so the
            // writes sit in the command queue until the terminal polls.
            showToast.success(
                data.message
                ?? `${toCount(data.queued) ?? 0} template write(s) queued for ${targetDevice.name}. The device collects them on its next poll.`,
            );
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to queue the template restore.');
        } finally {
            setRestoring(false);
        }
    };

    // ── On-device delete ────────────────────────────────────────────────────
    //
    // The target is the row's OWN source terminal, never a picked one. A row in
    // this table is the record of "this template was captured from that unit",
    // so that unit is the only device this row can be said to exist on; letting
    // an admin aim the delete at some other terminal would be aiming it at a
    // slot nothing here describes.

    const deleteDevice = useMemo(() => {
        if (!deleteRow?.deviceId) return null;
        return devices.find(d => String(d.id) === String(deleteRow.deviceId)) ?? null;
    }, [devices, deleteRow]);

    // How many fingerprints we hold for this PIN on this same terminal — what
    // "all fingers" actually means for this person, as a number.
    const deleteSiblingCount = useMemo(() => {
        if (!deleteRow) return 0;
        return rows.filter(r =>
            r.type === 'fingerprint'
            && String(r.pin) === String(deleteRow.pin)
            && String(r.deviceId) === String(deleteRow.deviceId),
        ).length;
    }, [rows, deleteRow]);

    /**
     * Why a given row cannot be deleted from its device, or null when it can.
     *
     * Face and palm are refused outright rather than attempted: the command is
     * `DATA DELETE FINGERTMP`, a fingerprint verb, and their stored finger index
     * is the -1 "no finger" sentinel, which the server rejects rather than put
     * on the wire as `FID=-1`. The action is still rendered for them, disabled,
     * carrying this sentence — a hidden control teaches nobody why.
     */
    const deleteBlockedReason = useCallback((r) => {
        if (!canDelete) {
            return 'The on-device delete endpoint is not registered on this server yet.';
        }
        if (r.type !== 'fingerprint') {
            return `Only fingerprint templates can be deleted from a device: the command is DATA DELETE FINGERTMP, and a ${r.type || 'non-fingerprint'} template has no finger index to address. This row stays stored here either way.`;
        }
        if (!r.pin) {
            return 'This row has no device PIN, so there is no address to delete on the terminal.';
        }
        if (!r.deviceId) {
            return 'This template is not attributed to a source device, so there is no terminal to delete it from.';
        }
        const device = devices.find(d => String(d.id) === String(r.deviceId));
        if (!device) {
            return 'The terminal this template came from is no longer registered here.';
        }
        if (device.protocol !== 'adms') {
            return `${device.name} is not an ADMS device. DATA DELETE FINGERTMP is an ADMS command and only ADMS terminals collect one.`;
        }
        if (!device.is_active) {
            return `${device.name} is marked inactive, so commands queued for it would never be collected.`;
        }
        return null;
    }, [canDelete, devices]);

    const openDelete = (r) => {
        setDeleteResult(null);
        setDeleteAck(false);
        // Default to the narrower action. "All fingers" is only ever reached by
        // choosing it. A row with no usable slot can only express "all".
        setDeleteScope(hasFingerSlot(r) ? DELETE_SCOPE.single : DELETE_SCOPE.all);
        setDeleteRow(r);
    };

    const closeDelete = (open) => {
        if (!open) {
            setDeleteRow(null);
            setDeleteAck(false);
            setDeleteResult(null);
        }
    };

    const submitDelete = async () => {
        if (!deleteRow || !deleteDevice || !canDelete) return;
        setDeleting(true);
        try {
            // `confirm_delete` is the server half of the acknowledgement below —
            // the endpoint answers 422 without it. `fid` is sent ONLY for a
            // single-finger delete: the protocol says "all fingers for this PIN"
            // by omitting the field, so an omission here is meaningful, not lazy.
            const payload = { pin: String(deleteRow.pin), confirm_delete: true };
            if (deleteScope === DELETE_SCOPE.single && hasFingerSlot(deleteRow)) {
                payload.fid = deleteRow.fingerIndex;
            }
            const { data } = await axios.post(route(TEMPLATE_ROUTES.delete, deleteDevice.id), payload);
            setDeleteResult({
                commandId:  data.command_id ?? null,
                allFingers: data.all_fingers === true,
                fid:        data.fid ?? null,
                message:    data.message ?? null,
            });
            setDeleteAck(false);
            // "Queued", never "deleted": nothing has reached the terminal yet.
            showToast.success(
                data.message
                ?? `Delete queued for ${deleteDevice.name}. The device collects it on its next poll.`,
            );
            // The list is deliberately NOT reloaded. Our stored copy is untouched
            // by design, so the row must stay exactly where it is — removing it
            // would tell the admin we deleted something we did not.
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to queue the on-device delete.');
        } finally {
            setDeleting(false);
        }
    };

    const deleteIsAllFingers = deleteScope === DELETE_SCOPE.all || !hasFingerSlot(deleteRow);

    return (
        <Box>
            <Flex align="center" justify="between" gap="3" mb="1" wrap="wrap">
                <Flex align="center" gap="2">
                    <LockClosedIcon />
                    <Text size="2" weight="medium">Stored Biometric Templates</Text>
                    {loaded && !loading && (
                        <Badge color="gray" variant="soft" size="1">{summary.total} stored</Badge>
                    )}
                </Flex>
                <Flex gap="2" align="center">
                    <Button size="2" variant="soft" color="gray" onClick={load} disabled={!canList || loading}>
                        {loading ? <Spinner size="1" /> : <ReloadIcon />} Refresh
                    </Button>
                    <Tooltip content={
                        !canRestore
                            ? 'The restore endpoint is not registered on this server yet.'
                            : admsDevices.length === 0
                                ? 'Restoring writes ADMS commands, which only ADMS-protocol terminals collect.'
                                : 'Queues the stored templates to be written back onto a terminal.'
                    }>
                        <Button
                            size="2"
                            onClick={openRestore}
                            disabled={!canRestore || admsDevices.length === 0 || rows.length === 0}
                        >
                            <ArrowRightIcon /> Restore to device
                        </Button>
                    </Tooltip>
                </Flex>
            </Flex>

            <Text size="1" color="gray" as="div" mb="3">
                Fingerprint and face templates the terminals have pushed to us since ADMS was
                switched on. The raw template is <strong>never</strong> returned by the API and is
                not displayable or downloadable here — only the fact that one exists, who it belongs
                to, and where it came from.
            </Text>

            <Text size="1" color="gray" as="div" mb="3">
                The per-row action in <strong>On device</strong> deletes a template{' '}
                <strong>from the terminal</strong>. It does <strong>not</strong> delete anything from
                this system — the stored copy above is what makes a restore possible, so it stays,
                and the row stays with it.
            </Text>

            {!canList ? (
                <Callout.Root color="amber" size="1">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>
                        <Code size="1">{TEMPLATE_ROUTES.list}</Code> is not registered on this server yet,
                        so stored templates cannot be listed. They are still being captured — nothing is
                        being lost, and nothing else on this page is affected.
                    </Callout.Text>
                </Callout.Root>
            ) : (
                <>
                    {/* Summary */}
                    <Flex wrap="wrap" gap="2" mb="3">
                        <Badge size="2" variant="soft" color="blue" radius="full">
                            <Text weight="bold">{summary.total}</Text> <Text style={{ opacity: 0.7 }}>Templates</Text>
                        </Badge>
                        <Badge size="2" variant="soft" color="violet" radius="full">
                            <Text weight="bold">{summary.employees}</Text> <Text style={{ opacity: 0.7 }}>Enrolled PINs</Text>
                        </Badge>
                        {Object.entries(summary.byType).map(([t, n]) => (
                            <Badge key={t} size="2" variant="soft" radius="full"
                                color={TEMPLATE_TYPE_META[t]?.color ?? 'gray'}>
                                <Text weight="bold">{n}</Text>{' '}
                                <Text style={{ opacity: 0.7 }}>{TEMPLATE_TYPE_META[t]?.label ?? prettyReason(t)}</Text>
                            </Badge>
                        ))}
                    </Flex>

                    {/* Filters */}
                    <Flex gap="3" mb="3" wrap="wrap" align="center">
                        <TextField.Root
                            placeholder="Search employee, PIN or device…"
                            size="2"
                            style={{ maxWidth: 300 }}
                            value={f.draft.tp_q}
                            onChange={e => f.setDraft('tp_q', e.target.value)}
                        >
                            <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                        </TextField.Root>
                        <Select.Root size="2" value={typeFilter} onValueChange={setType}>
                            <Select.Trigger style={{ width: 170 }} />
                            <Select.Content>
                                <Select.Item value="all">All types</Select.Item>
                                <Select.Item value="fingerprint">Fingerprint</Select.Item>
                                <Select.Item value="face">Face</Select.Item>
                                <Select.Item value="palm">Palm</Select.Item>
                            </Select.Content>
                        </Select.Root>
                        {loading && <Spinner size="2" />}
                        <Text size="1" color="gray" ml="auto">{filtered.length} shown</Text>
                    </Flex>

                    {/* Table */}
                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface" size="1">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>PIN</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Size</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Source device</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Captured</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">On device</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {filtered.map(r => {
                                    const meta = TEMPLATE_TYPE_META[r.type] ?? { color: 'gray', label: r.type || 'Unknown' };
                                    const blocked = deleteBlockedReason(r);
                                    return (
                                        <Table.Row key={r.id}>
                                            <Table.Cell>
                                                {r.employeeName ? (
                                                    <Flex direction="column">
                                                        <Text size="1" weight="medium">{r.employeeName}</Text>
                                                        {r.employeeCode && <Text size="1" color="gray">{r.employeeCode}</Text>}
                                                    </Flex>
                                                ) : (
                                                    <Tooltip content="The template is stored, but the PIN it was captured under no longer resolves to an employee.">
                                                        <Badge size="1" color="orange" variant="soft" radius="full">Unlinked</Badge>
                                                    </Tooltip>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell><Code size="1" variant="soft">{r.pin ?? '—'}</Code></Table.Cell>
                                            <Table.Cell>
                                                <Flex align="center" gap="1" wrap="wrap">
                                                    <Badge size="1" variant="soft" color={meta.color} radius="full">{meta.label}</Badge>
                                                    {r.type === 'fingerprint' && r.fingerIndex !== null && r.fingerIndex !== undefined && (
                                                        <Text size="1" color="gray">#{r.fingerIndex}</Text>
                                                    )}
                                                    {/* Stored and listed, but a restore cannot carry it:
                                                      * DATA UPDATE FINGERTMP is a fingerprint command.
                                                      * Saying so on the row is better than letting the
                                                      * skipped count explain it after the fact. */}
                                                    {!r.restorable && (
                                                        <Tooltip content={r.notRestorableReason ?? 'Only fingerprint templates can be written back to a device. This one is stored and listed, but a restore will skip it.'}>
                                                            <Badge size="1" variant="soft" color="gray" radius="full">Listed only</Badge>
                                                        </Tooltip>
                                                    )}
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1">{formatBytes(r.size)}</Text>
                                                {r.version && <Text size="1" color="gray"> · {r.version}</Text>}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text size="1">{r.deviceName ?? '—'}</Text>
                                                    {r.deviceSerial && <Text size="1" color="gray">{r.deviceSerial}</Text>}
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1" color="gray">
                                                    {r.capturedAt ? new Date(r.capturedAt).toLocaleString() : '—'}
                                                </Text>
                                            </Table.Cell>
                                            {/* Deletes on the TERMINAL, not here. Kept visible but
                                              * disabled where it cannot apply, with the reason on the
                                              * tooltip — face and palm in particular are not a
                                              * missing feature, they are a command that does not
                                              * exist for them. */}
                                            <Table.Cell align="right">
                                                <Tooltip content={
                                                    blocked
                                                        ?? `Queue a delete of ${hasFingerSlot(r) ? `fingerprint #${r.fingerIndex}` : 'this enrolment'} for PIN ${r.pin} on ${r.deviceName ?? 'its terminal'}. Removes it from the device only — the copy stored here is kept.`
                                                }>
                                                    {/* span: a disabled button emits no pointer
                                                      * events, so the tooltip explaining WHY would
                                                      * never open without a wrapper that does. */}
                                                    <span style={{ display: 'inline-flex' }}>
                                                        <IconButton
                                                            size="1"
                                                            variant="soft"
                                                            color="red"
                                                            disabled={Boolean(blocked)}
                                                            onClick={() => openDelete(r)}
                                                            aria-label={`Delete this template from ${r.deviceName ?? 'the device'}`}
                                                        >
                                                            <TrashIcon />
                                                        </IconButton>
                                                    </span>
                                                </Tooltip>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                                {!loading && filtered.length === 0 && (
                                    <Table.Row>
                                        <Table.Cell colSpan={7}>
                                            <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                                {rows.length === 0
                                                    ? 'No biometric templates have been captured yet. Terminals push them on enrolment.'
                                                    : 'No templates match this filter.'}
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </>
            )}

            {/* Restore confirmation. Writing biometric data onto hardware is not
              * something to trigger from a single unlabelled button, so the target
              * device is named in full and the acknowledgement repeats it. */}
            <Dialog.Root open={restoreOpen} onOpenChange={setRestoreOpen}>
                <Dialog.Content style={{ maxWidth: 560 }}>
                    <Dialog.Title>Restore templates to a device</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        This writes stored fingerprint and face templates onto a terminal. It adds
                        enrolments to the device; it does not remove anything already on it.
                    </Dialog.Description>

                    <Box mt="4">
                        <Text size="2" weight="medium" as="div" mb="1">Target device</Text>
                        <Select.Root size="2" value={targetId} onValueChange={v => { setTargetId(v); setAck(false); }}>
                            <Select.Trigger style={{ width: '100%' }} placeholder="Select the device to write to" />
                            <Select.Content>
                                {admsDevices.map(d => (
                                    <Select.Item key={d.id} value={String(d.id)}>
                                        {d.name} — {d.serial_number}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <Callout.Root color="amber" mt="3" size="1">
                        <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                        <Callout.Text>
                            The restore is <strong>queued, not applied</strong>. ADMS is device-initiated:
                            the server cannot write to a terminal on demand, so these commands sit in the
                            queue until the device next polls — seconds to minutes later, and never at all
                            if the unit is offline. Watch Device Commands on the Devices tab for the acks.
                        </Callout.Text>
                    </Callout.Root>

                    {targetDevice && (
                        <>
                            <Flex align="start" gap="2" mt="3">
                                <Checkbox checked={acknowledged} onCheckedChange={v => setAck(Boolean(v))} />
                                <Text size="2">
                                    Write biometric data onto <strong>{targetDevice.name}</strong>{' '}
                                    (<Code size="1">{targetDevice.serial_number}</Code>
                                    {targetDevice.location ? ` at ${targetDevice.location}` : ''}). Up to{' '}
                                    {summary.restorable} of {summary.total} stored template
                                    {summary.total === 1 ? '' : 's'}, covering {summary.employees} PIN
                                    {summary.employees === 1 ? '' : 's'}, will be queued for it.
                                </Text>
                            </Flex>
                            {summary.restorable < summary.total && (
                                <Text size="1" color="gray" as="div" mt="2" ml="6">
                                    {summary.total - summary.restorable} stored template
                                    {summary.total - summary.restorable === 1 ? ' is' : 's are'} not restorable —
                                    the write-back command is <Code size="1">DATA UPDATE FINGERTMP</Code>, so face and
                                    palm templates are listed here but never pushed. They will appear in the skipped
                                    breakdown.
                                </Text>
                            )}
                            <Text size="1" color="gray" as="div" mt="2" ml="6">
                                Templates the target already holds are skipped, so re-running this is safe and does
                                not duplicate enrolments.
                            </Text>
                        </>
                    )}

                    {/* Outcome. Reported in the dialog rather than a toast because the
                      * skipped/reasons breakdown is the part worth reading. */}
                    {result && (
                        <Panel variant="surface" mt="4">
                            <Text size="2" weight="medium" as="div" mb="2">Queued</Text>
                            <Flex wrap="wrap" gap="2" mb="2">
                                <Badge size="2" variant="soft" color="green" radius="full">
                                    <Text weight="bold">{result.queued ?? 0}</Text> <Text style={{ opacity: 0.7 }}>Queued</Text>
                                </Badge>
                                <Badge size="2" variant="soft" color="amber" radius="full">
                                    <Text weight="bold">{result.skipped ?? 0}</Text> <Text style={{ opacity: 0.7 }}>Skipped</Text>
                                </Badge>
                                {result.users !== null && result.users !== undefined && (
                                    <Badge size="2" variant="soft" color="violet" radius="full">
                                        <Text weight="bold">{result.users}</Text> <Text style={{ opacity: 0.7 }}>Employees</Text>
                                    </Badge>
                                )}
                            </Flex>
                            {result.reasons.length > 0 ? (
                                <Box>
                                    <Text size="1" color="gray" as="div" mb="1">Why rows were skipped</Text>
                                    {result.reasons.map(r => (
                                        <Flex key={r.reason} align="center" gap="2" mb="1" wrap="wrap">
                                            <Badge color="gray" variant="soft" size="1">{r.count ?? '—'}</Badge>
                                            <Text size="1">{prettyReason(r.reason)}</Text>
                                        </Flex>
                                    ))}
                                </Box>
                            ) : (
                                <Text size="1" color="gray">No skip reasons reported.</Text>
                            )}
                            <Text size="1" color="gray" as="div" mt="2">
                                Nothing has changed on the hardware yet. These are pending commands.
                            </Text>
                        </Panel>
                    )}

                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Close</Button></Dialog.Close>
                        <Button
                            onClick={submitRestore}
                            disabled={!canRestore || restoring || !targetDevice || !acknowledged}
                        >
                            {restoring ? <><Spinner size="1" /> Queueing…</> : 'Queue restore'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* On-device delete.
              *
              * The one thing this dialog exists to make unmistakable: the delete
              * lands on the TERMINAL, and our stored copy survives it. That
              * asymmetry is the whole safety property — device-only is
              * recoverable from what we hold, the reverse is not — so it is
              * stated in the title, the description, the acknowledgement and the
              * outcome, not once in small print. */}
            <Dialog.Root open={Boolean(deleteRow)} onOpenChange={closeDelete}>
                <Dialog.Content style={{ maxWidth: 580 }}>
                    <Dialog.Title>Delete from the device</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        This removes fingerprint template(s) from the terminal itself. The copy stored
                        in this system is <strong>not</strong> deleted — it stays listed here and can be
                        restored to a terminal afterwards.
                    </Dialog.Description>

                    {deleteRow && (
                        <>
                            <Panel variant="surface" mt="4">
                                <Flex direction="column" gap="1">
                                    <Text size="2">
                                        <Text color="gray">Employee: </Text>
                                        <strong>{deleteRow.employeeName ?? 'Unlinked'}</strong>
                                        {deleteRow.employeeCode ? ` (${deleteRow.employeeCode})` : ''}
                                        <Text color="gray"> · PIN </Text>
                                        <Code size="1" variant="soft">{deleteRow.pin ?? '—'}</Code>
                                    </Text>
                                    <Text size="2">
                                        <Text color="gray">Device: </Text>
                                        <strong>{deleteDevice?.name ?? deleteRow.deviceName ?? '—'}</strong>
                                        {(deleteDevice?.serial_number ?? deleteRow.deviceSerial)
                                            ? <> (<Code size="1">{deleteDevice?.serial_number ?? deleteRow.deviceSerial}</Code>)</>
                                            : null}
                                        {deleteDevice?.location ? ` at ${deleteDevice.location}` : ''}
                                    </Text>
                                </Flex>
                            </Panel>

                            <Box mt="3">
                                <Text size="2" weight="medium" as="div" mb="1">What to delete on the device</Text>
                                <Select.Root
                                    size="2"
                                    value={deleteIsAllFingers ? DELETE_SCOPE.all : DELETE_SCOPE.single}
                                    onValueChange={v => { setDeleteScope(v); setDeleteAck(false); }}
                                >
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        {hasFingerSlot(deleteRow) && (
                                            <Select.Item value={DELETE_SCOPE.single}>
                                                This one finger only — #{deleteRow.fingerIndex}
                                            </Select.Item>
                                        )}
                                        <Select.Item value={DELETE_SCOPE.all}>
                                            ALL fingers enrolled for PIN {deleteRow.pin}
                                        </Select.Item>
                                    </Select.Content>
                                </Select.Root>
                                <Text size="1" color="gray" as="div" mt="2">
                                    {deleteIsAllFingers ? (
                                        <>
                                            Every fingerprint this terminal holds for PIN{' '}
                                            <Code size="1">{deleteRow.pin}</Code> is removed — we list{' '}
                                            <strong>{deleteSiblingCount}</strong> for them on this device, and the
                                            command carries no finger index, so anything else enrolled there under
                                            that PIN goes too. This is the wider of the two actions.
                                            {!hasFingerSlot(deleteRow) && ' This row has no usable finger slot, so it is the only scope available for it.'}
                                        </>
                                    ) : (
                                        <>
                                            Only finger <strong>#{deleteRow.fingerIndex}</strong> is removed. The
                                            person's other {Math.max(deleteSiblingCount - 1, 0)} enrolment
                                            {deleteSiblingCount - 1 === 1 ? '' : 's'} on this device stay.
                                        </>
                                    )}
                                </Text>
                            </Box>

                            <Callout.Root color="amber" mt="3" size="1">
                                <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                                <Callout.Text>
                                    The delete is <strong>queued, not applied</strong>. ADMS is
                                    device-initiated: the server cannot reach a terminal on demand, so this
                                    command waits in the queue until the device next polls — and never runs
                                    at all if the unit stays offline. Watch Device Commands on the Devices
                                    tab for the ack.
                                </Callout.Text>
                            </Callout.Root>

                            <Flex align="start" gap="2" mt="3">
                                <Checkbox checked={deleteAck} onCheckedChange={v => setDeleteAck(Boolean(v))} />
                                <Text size="2">
                                    Delete{' '}
                                    {deleteIsAllFingers
                                        ? <strong>every fingerprint for PIN {deleteRow.pin}</strong>
                                        : <strong>fingerprint #{deleteRow.fingerIndex} for PIN {deleteRow.pin}</strong>}
                                    {' '}({deleteRow.employeeName ?? 'unlinked PIN'}) from{' '}
                                    <strong>{deleteDevice?.name ?? deleteRow.deviceName}</strong>
                                    {(deleteDevice?.serial_number ?? deleteRow.deviceSerial)
                                        ? <> (<Code size="1">{deleteDevice?.serial_number ?? deleteRow.deviceSerial}</Code>)</>
                                        : null}
                                    . This changes the <strong>device</strong> only; the template stored in this
                                    system is kept.
                                </Text>
                            </Flex>
                        </>
                    )}

                    {deleteResult && (
                        <Callout.Root color="green" mt="4" size="1">
                            <Callout.Icon><CheckCircledIcon /></Callout.Icon>
                            <Callout.Text>
                                {deleteResult.message
                                    ?? (deleteResult.allFingers
                                        ? 'Deletion of all fingerprints for this PIN is queued.'
                                        : 'Deletion of this finger is queued.')}
                                {deleteResult.commandId ? <> Command <Code size="1">#{deleteResult.commandId}</Code>.</> : null}
                                {' '}Nothing has been removed from the terminal yet, and nothing at all has been
                                removed from this system — the row is still listed above.
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Close</Button></Dialog.Close>
                        <Button
                            color="red"
                            onClick={submitDelete}
                            disabled={!canDelete || deleting || !deleteRow || !deleteDevice || !deleteAck}
                        >
                            {/* No spinner: a spinner reads as "working on the device",
                              * and nothing is happening on the device. This only ever
                              * puts a row in a queue. */}
                            {deleting
                                ? 'Queueing…'
                                : (deleteIsAllFingers ? 'Queue delete of all fingers' : 'Queue delete of this finger')}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/**
 * Capabilities + device-internal settings for one ADMS terminal.
 *
 * Why its own sub-tab rather than an extension of Device Health: Device Health
 * answers "can I reach this box right now" — heartbeat age, latency, uptime,
 * online/offline — across the whole fleet in one table, and it repolls every 30s.
 * Capability is a different axis entirely: what the unit *is* and what it can
 * *hold*, per device, refreshed only when a probe the device collects on its own
 * schedule comes back. Folding capacity meters, device identity and a ~40-field
 * settings form into that fleet table would push it past a dozen columns and mix
 * a live metric with a snapshot that may be days old.
 */
function CapabilitiesTab({ devices = [], isMobile }) {
    const admsDevices = useMemo(() => devices.filter(d => d.protocol === 'adms'), [devices]);

    const [deviceId, setDeviceId]               = useState('');
    const [snapshot, setSnapshot]               = useState(null);
    const [snapshotLoaded, setSnapshotLoaded]   = useState(false);
    const [loadingSnapshot, setLoadingSnapshot] = useState(false);
    const [catalogue, setCatalogue]             = useState([]);
    const [loadingCatalogue, setLoadingCatalogue] = useState(false);
    const [probing, setProbing]                 = useState(false);
    const [probeQueuedAt, setProbeQueuedAt]     = useState(null);

    const [values, setValues]         = useState({});
    const [baseline, setBaseline]     = useState({});
    const [queuedKeys, setQueuedKeys] = useState({});
    const [dangerArmed, setDangerArmed] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmText, setConfirmText] = useState('');
    const [saving, setSaving]           = useState(false);

    const endpointsReady = hasRoute(CAP_ROUTES.snapshot) && hasRoute(CAP_ROUTES.catalogue);
    const canProbe = hasRoute(CAP_ROUTES.probe);
    const canSave  = hasRoute(CAP_ROUTES.save);

    const selectedDevice = useMemo(
        () => admsDevices.find(d => String(d.id) === String(deviceId)) ?? null,
        [admsDevices, deviceId],
    );

    // Default to the first ADMS device once the list arrives. The parent repolls
    // `devices` every 5s, so this must not fight the user's own selection.
    useEffect(() => {
        if (!deviceId && admsDevices.length > 0) setDeviceId(String(admsDevices[0].id));
    }, [admsDevices, deviceId]);

    const loadCatalogue = useCallback(async () => {
        if (!hasRoute(CAP_ROUTES.catalogue)) return;
        setLoadingCatalogue(true);
        try {
            const { data } = await axios.get(route(CAP_ROUTES.catalogue));
            setCatalogue(normaliseCatalogue(data.catalogue ?? data.settings ?? data.data ?? data));
        } catch {
            showToast.error('Failed to load the device settings catalogue.');
        } finally {
            setLoadingCatalogue(false);
        }
    }, []);

    useEffect(() => { loadCatalogue(); }, [loadCatalogue]);

    const loadSnapshot = useCallback(async (id) => {
        if (!id || !hasRoute(CAP_ROUTES.snapshot)) return;
        setLoadingSnapshot(true);
        try {
            const { data } = await axios.get(route(CAP_ROUTES.snapshot, id));
            setSnapshot(data.capabilities ?? data.snapshot ?? data.data ?? data);
        } catch {
            setSnapshot(null);
            showToast.error('Failed to load the capability snapshot.');
        } finally {
            setSnapshotLoaded(true);
            setLoadingSnapshot(false);
        }
    }, []);

    // Switching device resets everything derived from the old one — a half-typed
    // setting must never follow the admin onto a different terminal.
    useEffect(() => {
        setSnapshot(null);
        setSnapshotLoaded(false);
        setQueuedKeys({});
        setDangerArmed(false);
        setProbeQueuedAt(null);
        if (deviceId) loadSnapshot(deviceId);
    }, [deviceId, loadSnapshot]);

    /** Every raw device answer, indexed case- and tilde-insensitively. */
    const optionIndex = useMemo(() => buildOptionIndex(snapshot), [snapshot]);

    /**
     * Keys the device will not answer for — an explicit −1004, or a key it
     * silently dropped from a reply it called successful. Stored normalised so
     * `MThreshold` and `~MThreshold` cannot disagree about the same fact.
     */
    const unsupportedKeys = useMemo(() => {
        const set = new Set();
        const push = (v) => { if (typeof v === 'string' && v) set.add(normaliseOptionKey(v)); };
        const lists = [snapshot?.unsupported_keys, snapshot?.unsupported, snapshot?.unsupportedKeys];
        lists.forEach(list => { if (Array.isArray(list)) list.forEach(push); });
        // A key→bool map is the other plausible shape. Only an explicit `false`
        // means unsupported; a missing key just means "never answered".
        [snapshot?.supported, snapshot?.support].forEach(map => {
            if (map && typeof map === 'object' && !Array.isArray(map)) {
                Object.entries(map).forEach(([k, v]) => { if (v === false) push(k); });
            }
        });
        return set;
    }, [snapshot]);

    const isUnsupportedKey = useCallback(
        (k) => unsupportedKeys.has(normaliseOptionKey(k)),
        [unsupportedKeys],
    );

    /**
     * Device state for one key. The raw `options` entry is authoritative because
     * it is the only place that separates a −1004 from a silent omission; the
     * flat `unsupported_keys` list is the fallback for a snapshot shape that
     * carries no options map, and can only ever say "unsupported".
     */
    const keyStateFor = useCallback((key) => {
        const state = readKeyState(optionIndex, key);
        if (state === 'unprobed' && isUnsupportedKey(key)) return 'unsupported';
        return state;
    }, [optionIndex, isUnsupportedKey]);

    const probedAt = useMemo(() => {
        const raw = snapshot?.probed_at ?? snapshot?.probedAt ?? null;
        if (!raw) return null;
        const d = new Date(raw);
        return Number.isNaN(d.getTime()) ? null : d;
    }, [snapshot]);

    const neverProbed = snapshotLoaded && !probedAt;
    const isStale = probedAt ? (Date.now() - probedAt.getTime()) > SNAPSHOT_STALE_AFTER_MS : false;

    // Seed the form from the snapshot whenever a fresh one arrives.
    useEffect(() => {
        if (catalogue.length === 0) return;
        const next = {};
        catalogue.forEach(entry => { next[entry.key] = asFormValue(entry, pickSnapshotValue(snapshot, [entry.key])); });
        setBaseline(next);
        setValues(next);
    }, [catalogue, snapshot]);

    const setValue = useCallback((key, val) => setValues(p => ({ ...p, [key]: val })), []);

    const dirtyKeys = useMemo(
        () => catalogue
            .filter(e => !isUnsupportedKey(e.key))
            .map(e => e.key)
            .filter(k => String(values[k] ?? '') !== String(baseline[k] ?? '')),
        [catalogue, values, baseline, isUnsupportedKey],
    );

    const dangerEntries     = useMemo(() => catalogue.filter(e => e.danger), [catalogue]);
    const dangerKeySet      = useMemo(() => new Set(dangerEntries.map(e => e.key)), [dangerEntries]);
    const dirtyDangerKeys   = useMemo(() => dirtyKeys.filter(k => dangerKeySet.has(k)), [dirtyKeys, dangerKeySet]);

    const prominentEntries = useMemo(
        () => catalogue.filter(e => PROMINENT_KEYS[e.key] && !e.danger),
        [catalogue],
    );

    /** Safe (non-danger) settings, grouped in the catalogue's own group order. */
    const safeGroups = useMemo(() => {
        const order = [];
        const byGroup = new Map();
        catalogue.forEach(entry => {
            if (entry.danger || PROMINENT_KEYS[entry.key]) return;
            const g = entry.group || 'Other';
            if (!byGroup.has(g)) { byGroup.set(g, []); order.push(g); }
            byGroup.get(g).push(entry);
        });
        return order.map(g => ({ group: g, entries: byGroup.get(g) }));
    }, [catalogue]);

    const probe = async () => {
        if (!selectedDevice || !canProbe) return;
        setProbing(true);
        try {
            const { data } = await axios.post(route(CAP_ROUTES.probe, selectedDevice.id));
            const ids = data.command_ids ?? data.commands ?? [];
            const count = Array.isArray(ids) ? ids.length : 0;
            setProbeQueuedAt(new Date());
            // Deliberately not "probing…" with a spinner: nothing is in flight.
            // The commands sit in the queue until the terminal polls us.
            showToast.success(
                data.message
                ?? `${count || 2} probe command(s) queued. The device collects them on its next poll — refresh the snapshot in a minute.`,
            );
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to queue the probe commands.');
        } finally {
            setProbing(false);
        }
    };

    const submitSettings = async () => {
        if (!selectedDevice || !canSave || dirtyKeys.length === 0) return;
        setSaving(true);
        try {
            const payload = {};
            dirtyKeys.forEach(k => { payload[k] = values[k]; });
            const { data } = await axios.post(route(CAP_ROUTES.save, selectedDevice.id), {
                settings: payload,
                // The backend enforces this too; this flag is the UI half of the
                // same gate, never the only one.
                confirm_dangerous: dirtyDangerKeys.length > 0,
            });
            setQueuedKeys(p => ({ ...p, ...payload }));
            setBaseline(p => ({ ...p, ...payload }));
            setDangerArmed(false);
            setConfirmOpen(false);
            setConfirmText('');
            showToast.success(
                data.message
                ?? `${dirtyKeys.length} setting change(s) queued as SET OPTION commands. Nothing has changed on the device yet — it applies them when it next polls.`,
            );
        } catch (e) {
            showToast.error(e.response?.data?.message ?? 'Failed to queue the setting changes.');
        } finally {
            setSaving(false);
        }
    };

    const onSave = () => {
        if (dirtyDangerKeys.length > 0) { setConfirmText(''); setConfirmOpen(true); return; }
        submitSettings();
    };

    if (!endpointsReady) {
        return (
            <Callout.Root color="amber">
                <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                <Callout.Text>
                    Device capability endpoints are not registered on this server yet.
                    This view needs <Code size="1">{CAP_ROUTES.snapshot}</Code> and{' '}
                    <Code size="1">{CAP_ROUTES.catalogue}</Code>; until they ship, capacity,
                    identity and device settings cannot be read. Nothing is broken in the
                    other tabs.
                </Callout.Text>
            </Callout.Root>
        );
    }

    if (admsDevices.length === 0) {
        return (
            <Flex direction="column" align="center" justify="center" py="9" gap="2">
                <MixerHorizontalIcon style={{ width: 36, height: 36, color: 'var(--gray-9)' }} />
                <Text size="3" weight="medium">No ADMS devices registered</Text>
                <Text size="2" color="gray">Capability probes and device settings use ADMS commands, which only ADMS-protocol terminals collect.</Text>
            </Flex>
        );
    }

    return (
        <Box>
            {/* Device picker + probe */}
            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" align={{ initial: 'stretch', sm: 'center' }} justify="between" mb="4">
                <Select.Root size="2" value={deviceId} onValueChange={setDeviceId}>
                    <Select.Trigger style={{ minWidth: 240 }} placeholder="Select a device" />
                    <Select.Content>
                        {admsDevices.map(d => (
                            <Select.Item key={d.id} value={String(d.id)}>{d.name} — {d.serial_number}</Select.Item>
                        ))}
                    </Select.Content>
                </Select.Root>
                <Flex gap="2" align="center">
                    <Button size="2" variant="soft" color="gray" onClick={() => loadSnapshot(deviceId)} disabled={loadingSnapshot}>
                        {loadingSnapshot ? <Spinner size="1" /> : <ReloadIcon />} Refresh snapshot
                    </Button>
                    <Tooltip content={canProbe ? 'Queues INFO + GET OPTION. The device collects them on its next poll.' : 'Probe endpoint is not registered on this server yet.'}>
                        <Button size="2" onClick={probe} disabled={!canProbe || probing || !selectedDevice}>
                            {probing ? <Spinner size="1" /> : <MixerHorizontalIcon />} Probe device
                        </Button>
                    </Tooltip>
                </Flex>
            </Flex>

            {/* Asynchrony is the defining property of this screen, so it is stated once, up front. */}
            <Callout.Root color="gray" mb="4" size="1">
                <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                <Callout.Text>
                    ADMS is device-initiated: the server cannot read or write a terminal on demand.
                    Probes and setting changes are <strong>queued</strong> and collected by the device
                    on its next poll — seconds to minutes later. Everything below is the last thing
                    the device volunteered, not a live reading.
                </Callout.Text>
            </Callout.Root>

            {/* Staleness */}
            {loadingSnapshot ? (
                <Flex justify="center" py="6"><Spinner size="3" /></Flex>
            ) : neverProbed ? (
                <Callout.Root color="amber" mb="4">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>
                        <strong>This device has never been probed.</strong> Nothing below is known —
                        blank counts mean "not asked", <em>not</em> "zero users". Run <strong>Probe device</strong>,
                        then refresh once the terminal has polled.
                    </Callout.Text>
                </Callout.Root>
            ) : probedAt ? (
                // Guarded on probedAt itself, NOT on neverProbed. neverProbed is
                // `snapshotLoaded && !probedAt`, so before the first snapshot lands
                // (or after a failed fetch) it is false while probedAt is still null
                // — which previously fell through to probedAt.toLocaleString() and
                // crashed the whole tab. Render nothing until we actually have a date.
                <Callout.Root color={isStale ? 'amber' : 'green'} mb="4" size="1">
                    <Callout.Icon>{isStale ? <ExclamationTriangleIcon /> : <CheckCircledIcon />}</Callout.Icon>
                    <Callout.Text>
                        Snapshot taken {probedAt.toLocaleString()}
                        {isStale && ' — over a day old. Counts and capacity may have drifted; re-probe before acting on them.'}
                    </Callout.Text>
                </Callout.Root>
            ) : null}

            {probeQueuedAt && (
                <Callout.Root color="cyan" mb="4" size="1">
                    <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                    <Callout.Text>
                        Probe queued at {probeQueuedAt.toLocaleTimeString()}. Waiting for the device to poll —
                        this page will not update by itself; use <strong>Refresh snapshot</strong>.
                    </Callout.Text>
                </Callout.Root>
            )}

            {/* Capacity */}
            <Text size="2" weight="medium" as="div" mb="2">Capacity</Text>
            <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="3" mb="4">
                {CAPACITY_METRICS.map(metric => {
                    /*
                     * The service computes each meter itself, and it is the only
                     * side that can: it knows which spelling of a maximum is a
                     * literal count and which is in some undeclared unit, and it
                     * withholds `max` entirely rather than hand down a
                     * denominator it cannot vouch for. Prefer that block. The
                     * key-scavenging below is the fallback for a snapshot that
                     * predates it, and it stays conservative for the same reason.
                     */
                    const server = snapshot?.capacity?.[metric.id];
                    const fromServer = server && typeof server === 'object' && !Array.isArray(server);

                    const usedState = readKeyStateAny(optionIndex, metric.usedKeys);
                    const rejected = usedState === 'unsupported' || usedState === 'omitted'
                        || metric.usedKeys.some(isUnsupportedKey);

                    const used = fromServer
                        ? toCount(server.used)
                        : (rejected ? null : toCount(pickSnapshotValue(snapshot, metric.usedKeys)));
                    const max = fromServer
                        ? toCount(server.max)
                        : (rejected ? null : toCount(pickSnapshotValue(snapshot, metric.maxKeys)));

                    // What the device literally said the maximum was, whether or
                    // not it survived as a usable denominator. Only used to
                    // explain a withheld ratio, never to compute one.
                    const reportedMax = toCount(pickSnapshotValue(snapshot, metric.maxKeys));

                    const supported = fromServer ? server.supported !== false : !rejected;
                    const known = fromServer
                        ? server.known !== false
                        : (used !== null || max !== null);

                    return (
                        <CapacityMeter
                            key={metric.id}
                            label={metric.label}
                            used={used}
                            max={max}
                            reportedMax={reportedMax}
                            supported={supported}
                            known={known}
                            unsupportedNote={usedState === 'omitted'
                                ? 'The device was asked for this counter and left it out of a reply it called successful — so it never said how many, and never said it could not. Re-probe; unlike a −1004 this can change.'
                                : undefined}
                        />
                    );
                })}
            </Grid>

            {/*
              * Engine presence. `FvFunOn = 0` / `PvFunOn = 0` — exactly what a
              * real MB460 answers — mean the unit has no finger-vein and no
              * palm-vein engine at all. That is a permanent fact about the model,
              * not "nobody is enrolled yet", and the two must never read the same.
              */}
            <Text size="2" weight="medium" as="div" mb="2">Biometric engines</Text>
            <Panel variant="surface" mb="4">
                <Text size="1" color="gray" as="div" mb="3">
                    Whether the terminal has each engine at all. <strong>Absent</strong> is a property of the
                    model — no amount of enrolling will change it. It is a different statement from a
                    capacity meter reading zero, which means the engine is there and nobody has enrolled.
                </Text>
                <Grid columns={{ initial: '2', sm: '3', md: '5' }} gap="3">
                    {ENGINE_FLAGS.map(engine => {
                        const present = readEngineFlag(snapshot, engine);
                        const state = readKeyStateAny(optionIndex, engine.keys);
                        const meta = present === true
                            ? { color: 'green', label: 'Present' }
                            : present === false
                                ? { color: 'gray', label: 'Absent on this model' }
                                : { color: KEY_STATE_META[state]?.color ?? 'gray', label: KEY_STATE_META[state]?.label ?? 'Unknown' };
                        const note = present === true
                            ? 'The device reported this engine as on.'
                            : present === false
                                ? 'The device reported this engine as off — the hardware has no such sensor. Nothing enrolled here, ever.'
                                : (KEY_STATE_NOTE[state] ?? 'The device has not said either way.');
                        return (
                            <Box key={engine.id}>
                                <Text size="1" color="gray" as="div">{engine.label}</Text>
                                <Tooltip content={note}>
                                    <Badge color={meta.color} variant="soft" size="1">{meta.label}</Badge>
                                </Tooltip>
                            </Box>
                        );
                    })}
                </Grid>
            </Panel>

            {/* Identity — device-reported, explicitly distinct from the admin-entered record */}
            <Panel variant="surface" mb="4">
                <Flex align="center" gap="2" mb="1">
                    <Text size="2" weight="medium">Reported by the device</Text>
                    <Badge color="gray" variant="soft" size="1">From INFO / GET OPTION</Badge>
                </Flex>
                <Text size="1" color="gray" as="div" mb="3">
                    These come from the terminal itself. The name, location and model on the Devices
                    tab are admin-entered and may disagree — where they do, the device is right.
                </Text>
                <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="3">
                    {IDENTITY_FIELDS.map(field => {
                        const val = pickSnapshotValue(snapshot, field.keys);
                        return (
                            <Box key={field.id}>
                                <Text size="1" color="gray" as="div">{field.label}</Text>
                                {val ? (
                                    <Code size="1" variant="soft">{String(val)}</Code>
                                ) : (
                                    <Text size="1" color="gray">Not reported</Text>
                                )}
                            </Box>
                        );
                    })}
                </Grid>
                {selectedDevice && (
                    <Box mt="3">
                        <Text size="1" color="gray" as="div">Admin-entered (Devices tab)</Text>
                        <Text size="1">
                            {selectedDevice.name}
                            {selectedDevice.model ? ` · ${selectedDevice.model}` : ''}
                            {selectedDevice.location ? ` · ${selectedDevice.location}` : ''}
                        </Text>
                    </Box>
                )}
            </Panel>

            <Separator size="4" my="4" />

            {/* ── Device settings ── */}
            <Flex align="center" justify="between" gap="3" mb="2" wrap="wrap">
                <Flex align="center" gap="2">
                    <GearIcon />
                    <Text size="2" weight="medium">Device Settings</Text>
                    {dirtyKeys.length > 0 && (
                        <Badge color="indigo" variant="soft" size="1">{dirtyKeys.length} changed</Badge>
                    )}
                </Flex>
                <Button size="2" onClick={onSave} disabled={!canSave || saving || dirtyKeys.length === 0}>
                    {saving
                        ? <><Spinner size="1" /> Queueing…</>
                        : dirtyKeys.length === 0
                            ? 'No changes to queue'
                            : `Queue ${dirtyKeys.length} change${dirtyKeys.length === 1 ? '' : 's'}`}
                </Button>
            </Flex>
            <Text size="1" color="gray" as="div" mb="3">
                Only the fields you actually change are sent — one <Code size="1">SET OPTION</Code> command
                per key, queued for the device to collect. Saving an untouched form sends nothing.
                Settings apply to <strong>this device only</strong>; there is deliberately no bulk apply.
            </Text>

            {!canSave && (
                <Callout.Root color="amber" mb="3" size="1">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Callout.Text>
                        <Code size="1">{CAP_ROUTES.save}</Code> is not registered on this server yet —
                        settings are readable but cannot be queued.
                    </Callout.Text>
                </Callout.Root>
            )}

            {loadingCatalogue ? (
                <Flex justify="center" py="6"><Spinner size="3" /></Flex>
            ) : catalogue.length === 0 ? (
                <Flex direction="column" align="center" justify="center" py="7" gap="2">
                    <GearIcon style={{ width: 32, height: 32, color: 'var(--gray-9)' }} />
                    <Text size="2" color="gray">The settings catalogue is empty.</Text>
                </Flex>
            ) : (
                <Flex direction="column" gap="4">

                    {/* Pinned: the two settings that get changed most and cost most to get wrong */}
                    {prominentEntries.length > 0 && (
                        <Panel variant="surface" style={{ borderColor: 'var(--indigo-a7)' }}>
                            <Text size="2" weight="medium" as="div" mb="1">Most-tuned settings</Text>
                            <Text size="1" color="gray" as="div" mb="3">
                                These two answer the two complaints that actually reach a helpdesk:
                                "it won't read my finger" and "it logged me four times".
                            </Text>
                            <Flex direction="column" gap="4">
                                {prominentEntries.map(entry => (
                                    <SettingField
                                        key={entry.key}
                                        entry={entry}
                                        value={values[entry.key] ?? ''}
                                        dirty={dirtyKeys.includes(entry.key)}
                                        state={keyStateFor(entry.key)}
                                        locked={false}
                                        queuedValue={queuedKeys[entry.key]}
                                        onChange={setValue}
                                    />
                                ))}
                            </Flex>
                        </Panel>
                    )}

                    {/* Ordinary groups, in the catalogue's own order */}
                    {safeGroups.map(({ group, entries }) => (
                        <Panel key={group} variant="surface">
                            <Text size="2" weight="medium" as="div" mb="3">{prettyGroup(group)}</Text>
                            <Grid columns={{ initial: '1', md: '2' }} gap="4">
                                {entries.map(entry => (
                                    <SettingField
                                        key={entry.key}
                                        entry={entry}
                                        value={values[entry.key] ?? ''}
                                        dirty={dirtyKeys.includes(entry.key)}
                                        state={keyStateFor(entry.key)}
                                        locked={false}
                                        queuedValue={queuedKeys[entry.key]}
                                        onChange={setValue}
                                    />
                                ))}
                            </Grid>
                        </Panel>
                    ))}

                    {/*
                      * Danger zone. Per docs §4b these keys — NetworkOn, TCPPort, UDPPort,
                      * DeviceID and the auto-power-off schedule — can strand the unit: a bad
                      * value takes it off the network and the only recovery is somebody
                      * physically standing in front of it, on a customer site. They are
                      * pulled out of their normal groups entirely so they can never be
                      * flipped in the same sweep as VoiceOn, they stay read-only until
                      * explicitly armed, and submitting them needs a typed confirmation.
                      */}
                    {dangerEntries.length > 0 && (
                        <Panel variant="surface" style={{ borderColor: 'var(--red-a7)', background: 'var(--red-a2)' }}>
                            <Flex align="center" gap="2" mb="1">
                                <ExclamationTriangleIcon style={{ color: 'var(--red-11)' }} />
                                <Text size="2" weight="medium" color="red">Danger zone — can strand this device</Text>
                            </Flex>
                            <Text size="1" color="red" as="div" mb="3">
                                A wrong value here takes the terminal off the network. It will stop
                                polling, so no further command — including one undoing the change —
                                can ever reach it. Recovery means physical access to the unit at its
                                site. These are never offered in a bulk action.
                            </Text>

                            <Flex align="center" gap="2" mb="3">
                                <Checkbox
                                    checked={dangerArmed}
                                    onCheckedChange={v => setDangerArmed(Boolean(v))}
                                />
                                <Text size="2">
                                    I understand these settings can take {selectedDevice?.name ?? 'this device'} off
                                    the network permanently, and that recovery requires physical access. Unlock them for editing.
                                </Text>
                            </Flex>

                            <Grid columns={{ initial: '1', md: '2' }} gap="4">
                                {dangerEntries.map(entry => (
                                    <SettingField
                                        key={entry.key}
                                        entry={entry}
                                        value={values[entry.key] ?? ''}
                                        dirty={dirtyKeys.includes(entry.key)}
                                        state={keyStateFor(entry.key)}
                                        locked={!dangerArmed}
                                        queuedValue={queuedKeys[entry.key]}
                                        onChange={setValue}
                                    />
                                ))}
                            </Grid>
                        </Panel>
                    )}
                </Flex>
            )}

            {/* Dangerous-change confirmation. Names the exact keys, the exact
              * old → new values, and the exact consequence; requires the serial
              * number typed out so it cannot be dismissed by reflex. */}
            <Dialog.Root open={confirmOpen} onOpenChange={setConfirmOpen}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Confirm network / power changes</Dialog.Title>
                    <Dialog.Description size="2" color="gray">
                        You are about to queue {dirtyDangerKeys.length} change(s) that can take{' '}
                        <strong>{selectedDevice?.name}</strong> off the network.
                    </Dialog.Description>

                    <Callout.Root color="red" mt="3">
                        <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                        <Callout.Text>
                            If the device stops reaching this server it will no longer collect commands,
                            so this change cannot be undone remotely. Recovery requires physically
                            visiting {selectedDevice?.location || 'the device location'}.
                        </Callout.Text>
                    </Callout.Root>

                    <Box mt="3">
                        {dirtyDangerKeys.map(k => (
                            <Flex key={k} align="center" gap="2" mb="1" wrap="wrap">
                                <Code size="1">{k}</Code>
                                <Text size="1" color="gray">{baseline[k] === '' ? '(not set)' : baseline[k]}</Text>
                                <ArrowRightIcon />
                                <Text size="1" weight="bold">{values[k] === '' ? '(cleared)' : values[k]}</Text>
                            </Flex>
                        ))}
                    </Box>

                    <Box mt="4">
                        <Text size="2" weight="medium" as="div" mb="1">
                            Type the device serial number <Code size="1">{selectedDevice?.serial_number}</Code> to confirm
                        </Text>
                        <TextField.Root
                            size="2"
                            value={confirmText}
                            placeholder={selectedDevice?.serial_number}
                            onChange={e => setConfirmText(e.target.value)}
                        />
                    </Box>

                    <Flex gap="3" mt="5" justify="end">
                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                        <Button
                            color="red"
                            onClick={submitSettings}
                            disabled={saving || confirmText.trim() !== (selectedDevice?.serial_number ?? '')}
                        >
                            {saving ? <><Spinner size="1" /> Queueing…</> : 'Queue these changes'}
                        </Button>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>
        </Box>
    );
}

/* ── Reconciliation sub-tab ── */

/**
 * Ships with the reconciliation endpoint, so it goes through hasRoute() like
 * every other cross-change route in this file — a missing route explains itself
 * instead of taking the sub-tab down with a Ziggy throw.
 */
const RECON_ROUTES = {
    report: 'biometric-devices.reconciliation',
};

/**
 * Category `nature` → colour, driven by the server's own category metadata
 * rather than a hardcoded list of category keys.
 *
 * The distinction this encodes is the whole point of the screen: `data_loss` is
 * punches we cannot account for, `needs_review` is the system doing exactly what
 * it was configured to do. Both mean "did not become attendance" and they are
 * not the same event, so they must never share a colour.
 */
const NATURE_META = {
    data_loss:    { color: 'red',   label: 'Data loss' },
    needs_review: { color: 'amber', label: 'Configuration' },
    unfinished:   { color: 'blue',  label: 'Unfinished' },
    unknown:      { color: 'gray',  label: 'Unexplained' },
};

const natureMeta = (nature) => NATURE_META[nature] ?? NATURE_META.unknown;

/** Days the range picker opens on, matching the server's own default window. */
const RECON_DEFAULT_DAYS = 30;

/**
 * `YYYY-MM-DD` from LOCAL calendar parts.
 *
 * Deliberately not `toISOString().slice(0,10)`, which converts to UTC first and
 * therefore reports the previous day for anyone east of Greenwich for part of
 * every day. This whole feature exists because a timestamp landed on the wrong
 * calendar day; the screen that reports it must not do the same thing.
 */
const toDayString = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const defaultReconRange = () => {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - (RECON_DEFAULT_DAYS - 1));

    return { start: toDayString(start), end: toDayString(end) };
};

/**
 * "Is this employee really absent, or did we lose his punches?"
 *
 * Answering that once took a full raw pull off the terminal plus a dozen ad-hoc
 * SQL queries. It gets asked again every month about somebody else, so it is a
 * screen.
 *
 * ── Two numbers, never one ──────────────────────────────────────────────────
 *
 * Ingestion and conversion are reported as two separate statements and there is
 * deliberately NO combined "sync health %" anywhere on this screen. On the
 * production MB460 ingestion is perfect (1,054 of 1,054 device records already
 * in the ERP) while conversion is 94 % (33 of 540 user-days never became
 * attendance). A blended figure would read ~97 %, describe nothing that exists,
 * and hide which of the two is actually broken — which is the only thing the
 * number would be for. They have different causes and different fixes.
 *
 * ── What is a finding, and what is emphatically not ─────────────────────────
 *
 * Only "the device has a punch on this day, the ERP has no attendance" is a
 * finding. ERP days routinely and correctly EXCEED device days, because
 * employees on WiFi/GPS attendance types punch through channels this terminal
 * never sees — one employee has 21 device days against 298 ERP days. That
 * column is therefore rendered as context with a tooltip saying so, and is never
 * coloured, never differenced, and never counted as a discrepancy.
 */
function ReconciliationTab({ devices = [], isMobile }) {
    /* Device and date range live in the URL under an `rc_` prefix so they
       survive leaving the panel and coming back; the default window is the
       last RECON_DEFAULT_DAYS days. */
    const f = useQueryFilters({
        mode: 'client',
        debounceKeys: [],
        defaults: { rc_dev: '', rc_from: '', rc_to: '' },
    });
    const deviceId = f.values.rc_dev;
    const setDeviceId = (v) => f.set('rc_dev', v);
    const range = useMemo(() => {
        const fallback = defaultReconRange();
        return { start: f.values.rc_from || fallback.start, end: f.values.rc_to || fallback.end };
    }, [f.values.rc_from, f.values.rc_to]);
    const setRange = (next) => f.setMany({ rc_from: next?.start ?? '', rc_to: next?.end ?? '' });
    const [report, setReport]     = useState(null);
    const [loading, setLoading]   = useState(false);
    const [error, setError]       = useState(null);
    const [expanded, setExpanded] = useState({});

    const canReconcile = hasRoute(RECON_ROUTES.report);

    // First device as soon as one is known, so the tab is useful without a
    // click. Re-runs only while nothing is selected, so an admin's choice is
    // never overwritten by the 5 s device poll.
    useEffect(() => {
        if (deviceId || devices.length === 0) return;
        setDeviceId(String(devices[0].id));
    }, [devices, deviceId]);

    const fetchReport = useCallback(async () => {
        if (!canReconcile || !deviceId) return;

        setLoading(true);
        setError(null);
        try {
            const { data } = await axios.get(route(RECON_ROUTES.report, { id: deviceId }), {
                params: { from: range.start || undefined, until: range.end || undefined },
            });
            setReport(data);
            setExpanded({});
        } catch (e) {
            // The endpoint refuses an inverted range and one longer than it will
            // read, and names the bound in the message. Surfaced verbatim — a
            // generic failure would leave an admin guessing at a cap.
            setReport(null);
            setError(e.response?.data?.message ?? 'Failed to build the reconciliation report.');
        } finally {
            setLoading(false);
        }
    }, [canReconcile, deviceId, range.start, range.end]);

    useEffect(() => { fetchReport(); }, [fetchReport]);

    const toggleRow = (pin) => setExpanded(prev => ({ ...prev, [pin]: !prev[pin] }));

    const categories  = report?.categories ?? {};
    const conversion  = report?.conversion ?? null;
    const ingestion   = report?.ingestion ?? null;
    const employees   = report?.employees ?? [];

    // Only employees with something to look at, unless the admin asks for all.
    const [showAll, setShowAll] = useState(false);
    const visible = useMemo(
        () => (showAll ? employees : employees.filter(e => e.missing_days > 0)),
        [employees, showAll],
    );

    if (!canReconcile) {
        return (
            <Callout.Root color="amber" size="1">
                <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                <Callout.Text>
                    The <Code size="1">{RECON_ROUTES.report}</Code> endpoint is not registered on this
                    server yet, so punches cannot be reconciled against attendance from here.
                </Callout.Text>
            </Callout.Root>
        );
    }

    return (
        <Box>
            {/* Controls */}
            <Flex gap="3" mb="4" wrap="wrap" align="end">
                <Box>
                    <Text size="1" color="gray" as="div" mb="1">Device</Text>
                    <Select.Root size="2" value={deviceId} onValueChange={setDeviceId}>
                        <Select.Trigger style={{ width: 220 }} placeholder="Select a device" />
                        <Select.Content>
                            {devices.map(d => (
                                <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Box>
                <Box>
                    <Text size="1" color="gray" as="div" mb="1">Date range</Text>
                    <DateTimePicker mode="dateRange" value={range} onChange={setRange} />
                </Box>
                <Button size="2" variant="soft" color="gray" onClick={fetchReport} disabled={loading || !deviceId}>
                    {loading ? <Spinner size="1" /> : <ReloadIcon />} Reconcile
                </Button>
                {report?.device && (
                    <Text size="1" color="gray" ml="auto">
                        {report.device.name} · <Code size="1">{report.device.serial_number}</Code>
                    </Text>
                )}
            </Flex>

            {error && (
                <Callout.Root color="red" size="1" mb="4">
                    <Callout.Icon><CrossCircledIcon /></Callout.Icon>
                    <Callout.Text>{error}</Callout.Text>
                </Callout.Root>
            )}

            {devices.length === 0 && !loading && (
                <Callout.Root color="gray" size="1" mb="4">
                    <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                    <Callout.Text>No biometric devices are registered, so there is nothing to reconcile.</Callout.Text>
                </Callout.Root>
            )}

            {report && (
                <>
                    {/*
                      * The headline. TWO statements in TWO boxes, about two
                      * different systems — see the component docblock for why
                      * there is no third number averaging them.
                      */}
                    <Grid columns={{ initial: '1', md: '2' }} gap="3" mb="4">
                        <Box style={{ border: '1px solid var(--gray-a5)', borderRadius: 'var(--radius-3)', padding: 16 }}>
                            <Flex align="center" gap="2" mb="2">
                                <DownloadIcon />
                                <Text size="2" weight="bold">Ingestion — device → ERP</Text>
                            </Flex>
                            {ingestion?.statement ? (
                                <Text size="2" as="div">{ingestion.statement}</Text>
                            ) : (
                                <Text size="2" color="gray" as="div">
                                    Not measured. No completed full log download is on record for this device, so
                                    nothing here can say whether the terminal still holds punches we have never seen.
                                </Text>
                            )}
                            <Text size="1" color="gray" as="div" mt="2">
                                {ingestion?.note}
                            </Text>
                            {ingestion?.last_full_pull?.completed_at && (
                                <Text size="1" color="gray" as="div" mt="1">
                                    Last full pull: {new Date(ingestion.last_full_pull.completed_at).toLocaleString()}
                                </Text>
                            )}
                        </Box>

                        <Box style={{ border: '1px solid var(--gray-a5)', borderRadius: 'var(--radius-3)', padding: 16 }}>
                            <Flex align="center" gap="2" mb="2">
                                <ActivityLogIcon />
                                <Text size="2" weight="bold">Conversion — punches → attendance</Text>
                            </Flex>
                            <Text size="2" as="div">{report.headline?.conversion}</Text>
                            <Flex gap="2" wrap="wrap" mt="3">
                                <Badge size="1" variant="soft" color="gray" radius="full">
                                    <Text weight="bold">{conversion?.device_user_days ?? 0}</Text>
                                    <Text style={{ opacity: 0.7 }}>&nbsp;device user-days</Text>
                                </Badge>
                                <Badge size="1" variant="soft" color="green" radius="full">
                                    <Text weight="bold">{conversion?.converted_user_days ?? 0}</Text>
                                    <Text style={{ opacity: 0.7 }}>&nbsp;became attendance</Text>
                                </Badge>
                                <Badge
                                    size="1"
                                    variant="soft"
                                    radius="full"
                                    color={(conversion?.missing_user_days ?? 0) > 0 ? 'red' : 'green'}
                                >
                                    <Text weight="bold">{conversion?.missing_user_days ?? 0}</Text>
                                    <Text style={{ opacity: 0.7 }}>&nbsp;did not</Text>
                                </Badge>
                            </Flex>
                        </Box>
                    </Grid>

                    {/* Why they did not convert. Split by nature, never summed. */}
                    {(conversion?.missing_user_days ?? 0) > 0 && (
                        <Flex gap="2" wrap="wrap" mb="4">
                            {Object.entries(conversion.by_category)
                                .filter(([, count]) => count > 0)
                                .map(([key, count]) => {
                                    const meta = categories[key] ?? {};
                                    const nature = natureMeta(meta.nature);

                                    return (
                                        <Tooltip key={key} content={meta.summary ?? key}>
                                            <Badge size="2" variant="soft" color={nature.color} radius="full">
                                                <Text weight="bold">{count}</Text>
                                                <Text style={{ opacity: 0.8 }}>&nbsp;{meta.label ?? key}</Text>
                                            </Badge>
                                        </Tooltip>
                                    );
                                })}
                        </Flex>
                    )}

                    {report.clock?.is_applied && (
                        <Callout.Root color="blue" size="1" mb="4">
                            <Callout.Icon><InfoCircledIcon /></Callout.Icon>
                            <Callout.Text>
                                {report.clock.reason_label} Every punch time below is the CORRECTED moment — the
                                one attendance was filed under — and each missing day also shows the raw time the
                                terminal reported.
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    {(report.unparsable_punches ?? 0) > 0 && (
                        <Callout.Root color="amber" size="1" mb="4">
                            <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                            <Callout.Text>
                                {report.unparsable_punches} punch(es) carried a timestamp that could not be read and
                                were left out of every figure above.
                            </Callout.Text>
                        </Callout.Root>
                    )}

                    <Flex align="center" gap="3" mb="2" wrap="wrap">
                        <Text size="2" weight="medium">
                            {visible.length} of {employees.length} employee(s)
                        </Text>
                        <Text size="1" color="gray">
                            {report.range?.from} → {report.range?.until} ({report.range?.days} days)
                        </Text>
                        <Flex align="center" gap="2" ml="auto">
                            <Switch size="1" checked={showAll} onCheckedChange={setShowAll} />
                            <Text size="1" color="gray">Show employees with no gaps</Text>
                        </Flex>
                    </Flex>

                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface" size="1">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>PIN</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">Device days</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">ERP days</Table.ColumnHeaderCell>
                                    {!isMobile && <Table.ColumnHeaderCell align="right">From this device</Table.ColumnHeaderCell>}
                                    <Table.ColumnHeaderCell align="right">Missing</Table.ColumnHeaderCell>
                                    {!isMobile && <Table.ColumnHeaderCell>Why</Table.ColumnHeaderCell>}
                                    <Table.ColumnHeaderCell />
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {visible.map(row => {
                                    const isOpen = Boolean(expanded[row.pin]);
                                    const hasGaps = row.missing_days > 0;

                                    return (
                                        <React.Fragment key={row.pin}>
                                            <Table.Row style={hasGaps ? { background: 'var(--red-a2)' } : undefined}>
                                                <Table.Cell><Code size="1" variant="soft">{row.pin}</Code></Table.Cell>
                                                <Table.Cell>
                                                    <Flex direction="column">
                                                        <Text size="1" weight="medium">{row.name ?? '—'}</Text>
                                                        {row.is_placeholder && (
                                                            <Badge size="1" color="orange" variant="soft" radius="full">
                                                                PIN belongs to nobody
                                                            </Badge>
                                                        )}
                                                    </Flex>
                                                </Table.Cell>
                                                <Table.Cell align="right"><Text size="1">{row.device_days}</Text></Table.Cell>
                                                <Table.Cell align="right">
                                                    {/* Never coloured and never differenced against device
                                                      * days — see the docblock. Exceeding it is normal. */}
                                                    <Tooltip content="Every attendance day in range, whatever recorded it. Employees who also use WiFi or GPS attendance legitimately have far more ERP days than device days; that is not a discrepancy.">
                                                        <Text size="1" color="gray">{row.erp_days}</Text>
                                                    </Tooltip>
                                                </Table.Cell>
                                                {!isMobile && (
                                                    <Table.Cell align="right">
                                                        <Tooltip content="Attendance days whose punch time matches a processed punch from this device. Best-effort attribution by exact timestamp; nothing above is computed from it.">
                                                            <Text size="1" color="gray">{row.device_derived_days}</Text>
                                                        </Tooltip>
                                                    </Table.Cell>
                                                )}
                                                <Table.Cell align="right">
                                                    <Text size="1" weight={hasGaps ? 'bold' : 'regular'} color={hasGaps ? 'red' : 'gray'}>
                                                        {row.missing_days}
                                                    </Text>
                                                </Table.Cell>
                                                {!isMobile && (
                                                    <Table.Cell>
                                                        <Flex gap="1" wrap="wrap">
                                                            {Object.entries(row.by_category)
                                                                .filter(([, count]) => count > 0)
                                                                .map(([key, count]) => {
                                                                    const meta = categories[key] ?? {};
                                                                    const nature = natureMeta(meta.nature);

                                                                    return (
                                                                        <Tooltip key={key} content={meta.summary ?? key}>
                                                                            <Badge size="1" variant="soft" color={nature.color} radius="full">
                                                                                {count} {meta.label ?? key}
                                                                            </Badge>
                                                                        </Tooltip>
                                                                    );
                                                                })}
                                                            {!hasGaps && <Text size="1" color="gray">—</Text>}
                                                        </Flex>
                                                    </Table.Cell>
                                                )}
                                                <Table.Cell>
                                                    {hasGaps && (
                                                        <Button size="1" variant="ghost" color="gray" onClick={() => toggleRow(row.pin)}>
                                                            {isOpen ? <ChevronDownIcon /> : <ChevronRightIcon />}
                                                            {isOpen ? 'Hide days' : 'Show days'}
                                                        </Button>
                                                    )}
                                                </Table.Cell>
                                            </Table.Row>

                                            {/* Drill-down: the days themselves, with the reason the
                                              * pipeline actually recorded, verbatim. */}
                                            {isOpen && (
                                                <Table.Row>
                                                    <Table.Cell colSpan={isMobile ? 6 : 8} style={{ padding: 0 }}>
                                                        <Box p="3" style={{ background: 'var(--gray-a2)' }}>
                                                            <Table.Root variant="ghost" size="1">
                                                                <Table.Header>
                                                                    <Table.Row>
                                                                        <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>Punches</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>First → last</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>Reason recorded</Table.ColumnHeaderCell>
                                                                    </Table.Row>
                                                                </Table.Header>
                                                                <Table.Body>
                                                                    {row.missing.map(day => {
                                                                        const meta = categories[day.category] ?? {};
                                                                        const nature = natureMeta(meta.nature);

                                                                        return (
                                                                            <Table.Row key={day.date}>
                                                                                <Table.Cell><Text size="1" weight="medium">{day.date}</Text></Table.Cell>
                                                                                <Table.Cell>
                                                                                    <Flex gap="1" wrap="wrap">
                                                                                        <Badge size="1" variant="soft" color="gray" radius="full">{day.punches}</Badge>
                                                                                        {day.check_types.map(t => (
                                                                                            <Badge key={t} size="1" variant="soft" radius="full"
                                                                                                color={t === 'out' ? 'red' : 'green'}>
                                                                                                {t.toUpperCase()}
                                                                                            </Badge>
                                                                                        ))}
                                                                                    </Flex>
                                                                                </Table.Cell>
                                                                                <Table.Cell>
                                                                                    <Flex direction="column">
                                                                                        <Text size="1">
                                                                                            {day.first_punch.slice(11)} → {day.last_punch.slice(11)}
                                                                                        </Text>
                                                                                        {day.clock_corrected && (
                                                                                            <Tooltip content="The device's own timestamps, before its clock error was corrected.">
                                                                                                <Text size="1" color="gray">
                                                                                                    device said {day.first_punch_raw.slice(11)} → {day.last_punch_raw.slice(11)}
                                                                                                </Text>
                                                                                            </Tooltip>
                                                                                        )}
                                                                                    </Flex>
                                                                                </Table.Cell>
                                                                                <Table.Cell>
                                                                                    <Tooltip content={meta.summary ?? ''}>
                                                                                        <Badge size="1" variant="soft" color={nature.color} radius="full">
                                                                                            {meta.label ?? day.category}
                                                                                        </Badge>
                                                                                    </Tooltip>
                                                                                </Table.Cell>
                                                                                <Table.Cell>
                                                                                    {day.reasons.length > 0 ? (
                                                                                        <Flex direction="column" gap="1">
                                                                                            {day.reasons.map(reason => (
                                                                                                <Text key={reason} size="1" color="gray">{reason}</Text>
                                                                                            ))}
                                                                                        </Flex>
                                                                                    ) : (
                                                                                        <Text size="1" color="gray">
                                                                                            No reason was recorded ({day.statuses.join(', ') || 'no status'}).
                                                                                        </Text>
                                                                                    )}
                                                                                </Table.Cell>
                                                                            </Table.Row>
                                                                        );
                                                                    })}
                                                                </Table.Body>
                                                            </Table.Root>
                                                        </Box>
                                                    </Table.Cell>
                                                </Table.Row>
                                            )}
                                        </React.Fragment>
                                    );
                                })}

                                {!loading && visible.length === 0 && (
                                    <Table.Row>
                                        <Table.Cell colSpan={isMobile ? 6 : 8}>
                                            <Text size="2" color="gray" style={{ display: 'block', textAlign: 'center', padding: '24px 0' }}>
                                                {employees.length === 0
                                                    ? 'This device recorded no punches in this range.'
                                                    : 'Every device punch in this range became an attendance record. Switch on "Show employees with no gaps" to see them all.'}
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </>
            )}
        </Box>
    );
}

/* ── Main BiometricPanel ── */
export default function BiometricPanel({
    initialDevices = [], employees = [],
    isMobile, tick, onCountChange, onSetHeaderActions, isActive,
}) {
    const [devices, setDevices] = useState(initialDevices);
    /* The section being viewed lives in the URL under a `b_` prefix (this
       panel is mounted inside pages whose other tabs also stay mounted). */
    const fb = useQueryFilters({ mode: 'client', debounceKeys: [], defaults: { b_sub: 'devices' } });
    const subTab = fb.values.b_sub;
    const setSubTab = (v) => fb.set('b_sub', v);
    const [fetchedEmployees, setFetchedEmployees] = useState([]);

    /**
     * The `employees` prop is real and populated — but only from one of the two
     * places that mount this panel. Settings/BiometricDevices passes the roster
     * the controller loads; Attendance → Biometric passes a hardcoded `[]`, so
     * the unknown-user picker there would have nobody to offer.
     *
     * `biometric-devices.index` returns that same roster alongside the devices
     * when asked for JSON — and it is the exact request this panel already makes
     * on its device poll. So the fallback is free, needs no change to the panel's
     * props, and needs no change to either page.
     */
    const employeePool = employees.length > 0 ? employees : fetchedEmployees;

    const refreshDevices = useCallback(async () => {
        try {
            const { data } = await axios.get(route('biometric-devices.index'));
            setDevices(data.devices ?? []);
            if (Array.isArray(data.employees) && data.employees.length > 0) {
                setFetchedEmployees(data.employees);
            }
        } catch { /* silently fail */ }
    }, []);

    // One immediate fetch when the panel was handed no roster. The 5s device
    // poll below would eventually supply one, but not before an admin can open
    // an ATTLOG row. Deliberately separate from that poll, which is unchanged.
    useEffect(() => {
        if (!isActive || employees.length > 0 || fetchedEmployees.length > 0) return;
        refreshDevices();
    }, [isActive, employees.length, fetchedEmployees.length, refreshDevices]);

    // Poll devices and logs when active
    useEffect(() => {
        if (!isActive) return;

        const interval = setInterval(() => {
            refreshDevices();
        }, 5000);

        return () => clearInterval(interval);
    }, [isActive, refreshDevices]);

    useEffect(() => { onCountChange?.(devices.length); }, [devices.length, onCountChange]);

    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(null);
    }, [isActive, onSetHeaderActions]);

    const [syncing, setSyncing] = useState(false);
    const syncPool = async () => {
        setSyncing(true);
        try {
            await axios.post(route('biometric-devices.sync-pool'));
            showToast.success('All devices synced to Biometric AT.');
        } catch {
            showToast.error('Sync failed.');
        } finally { setSyncing(false); }
    };

    return (
        <Box>
            {/* Quick stats */}
            <Flex wrap="wrap" gap="2" mb="4" align="center">
                <Badge size="2" variant="soft" color="green"  radius="full">
                    <Text weight="bold">{devices.length}</Text> <Text style={{ opacity: 0.7 }}>Devices</Text>
                </Badge>
                <Badge size="2" variant="soft" color="blue"   radius="full">
                    <Text weight="bold">{devices.filter(d => d.is_online).length}</Text> <Text style={{ opacity: 0.7 }}>Online</Text>
                </Badge>
                <Badge size="2" variant="soft" color="violet" radius="full">All linked to Biometric AT</Badge>
                <Button size="1" variant="soft" color="gray" onClick={syncPool} disabled={syncing} ml="auto">
                    {syncing ? <Spinner size="1" /> : <ReloadIcon />} Sync Pool
                </Button>
            </Flex>

            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <Tabs.List mb="4">
                    <Tabs.Trigger value="devices">
                        <Flex align="center" gap="2"><DesktopIcon /> Devices</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="health">
                        <Flex align="center" gap="2"><HeartIcon /> Device Health</Flex>
                    </Tabs.Trigger>
                    {/* Capability is a different axis from Device Health: what the unit *is*
                      * and can *hold*, from a snapshot the device volunteers on its own
                      * schedule — not whether it answered a heartbeat in the last 30s. */}
                    {/* Named for both halves: the stored-template table under this
                      * tab is the only recovery path if a terminal dies, and a
                      * label that does not mention it makes that path unfindable. */}
                    <Tabs.Trigger value="capabilities">
                        <Flex align="center" gap="2"><GearIcon /> Capabilities &amp; Templates</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="logs">
                        <Flex align="center" gap="2"><ActivityLogIcon /> ADMS Logs</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="operlog">
                        <Flex align="center" gap="2"><LockClosedIcon /> OPERLOG</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="attlog">
                        <Flex align="center" gap="2"><EnvelopeClosedIcon /> ATTLOG</Flex>
                    </Tabs.Trigger>
                    {/* ATTLOG says what happened to each punch; Reconciliation
                      * says what did NOT happen — the days a device punch exists
                      * for and no attendance record does. Next to ATTLOG because
                      * that is where someone lands when chasing a punch, and the
                      * per-row reasons here are the same punch_status_reason
                      * values that tab shows one row at a time. */}
                    <Tabs.Trigger value="reconciliation">
                        <Flex align="center" gap="2"><MagnifyingGlassIcon /> Reconciliation</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="webhook">
                        <Flex align="center" gap="2"><Link2Icon /> Webhook Config</Flex>
                    </Tabs.Trigger>
                    <Tabs.Trigger value="downloads">
                        <Flex align="center" gap="2"><DownloadIcon /> Downloads</Flex>
                    </Tabs.Trigger>
                </Tabs.List>

                <Tabs.Content value="devices">
                    <DevicesTab devices={devices} setDevices={setDevices} employees={employees} isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="health">
                    <HealthTab isMobile={isMobile} />
                </Tabs.Content>
                {/*
                  * Templates share the Capabilities tab rather than taking a
                  * ninth. See the TemplatesSection docblock for the argument: it
                  * is the same subject as capacity seen from our side, and the
                  * restore needs a target device, which is what this tab is
                  * already about. Mounted as a sibling of CapabilitiesTab, not
                  * inside it, because CapabilitiesTab returns early when the
                  * capability endpoints are missing or no ADMS device exists —
                  * and stored templates are readable and worth reading in both
                  * of those cases.
                  */}
                <Tabs.Content value="capabilities">
                    <CapabilitiesTab isMobile={isMobile} devices={devices} />
                    <Separator size="4" my="5" />
                    <TemplatesSection devices={devices} />
                </Tabs.Content>
                <Tabs.Content value="logs">
                    <LogsTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="operlog">
                    <OperLogTab isMobile={isMobile} />
                </Tabs.Content>
                <Tabs.Content value="attlog">
                    <AttLogTab isMobile={isMobile} devices={devices} employees={employeePool} />
                </Tabs.Content>
                <Tabs.Content value="reconciliation">
                    <ReconciliationTab isMobile={isMobile} devices={devices} />
                </Tabs.Content>
                <Tabs.Content value="webhook">
                    <WebhookTab />
                </Tabs.Content>
                <Tabs.Content value="downloads">
                    <DownloadsTab isMobile={isMobile} devices={devices} />
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
