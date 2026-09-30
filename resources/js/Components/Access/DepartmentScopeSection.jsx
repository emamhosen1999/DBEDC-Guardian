import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Badge, Box, Button, Flex, Grid, IconButton, Select, Spinner, Text, TextField, Tooltip } from '@radix-ui/themes';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import DateTimePicker from '@/Components/DateTimePicker';
import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import { showToast, extractErrorMessage } from '@/utils/toastUtils';

/*
 * Department scope grants for one employee: a standing "admin" grant or a
 * time-boxed "acting" charge (e.g. while the head is on leave). The server
 * decides whether a grant is in force at request time, so an expired acting
 * charge stops applying on its own. Render only for department.scopes.manage.
 */

const STATUS_COLOR = { active: 'green', scheduled: 'amber', expired: 'gray' };
const EMPTY_FORM = { department_id: '', scope_type: 'admin', starts_at: '', expires_at: '', reason: '' };

const formatWhen = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : null);

const windowLabel = (scope) => {
    const from = formatWhen(scope.starts_at);
    const to = formatWhen(scope.expires_at);
    if (from && to) return `${from} → ${to}`;
    if (to) return `Until ${to}`;
    if (from) return `From ${from}`;
    return 'No end date';
};

export default function DepartmentScopeSection({ userId, departments = [] }) {
    const [scopes, setScopes] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState(EMPTY_FORM);
    const [errors, setErrors] = useState({});
    const [toRevoke, setToRevoke] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('users.department-scopes.index', { id: userId }));
            setScopes(data.scopes ?? []);
        } catch (error) {
            showToast.error(extractErrorMessage(error, 'Failed to load department scopes.'));
        } finally {
            setLoading(false);
        }
    }, [userId]);

    useEffect(() => {
        load();
    }, [load]);

    const setField = (key, value) => {
        setForm((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => ({ ...prev, [key]: undefined }));
    };

    const submit = async () => {
        setSaving(true);
        setErrors({});
        try {
            const payload = {
                ...form,
                department_id: form.department_id ? Number(form.department_id) : null,
                starts_at: form.starts_at || null,
                expires_at: form.expires_at || null,
                reason: form.reason || null,
            };
            const { data } = await axios.post(route('users.department-scopes.store', { id: userId }), payload);
            showToast.success(data.message || 'Department scope granted.');
            setForm(EMPTY_FORM);
            setAdding(false);
            load();
        } catch (error) {
            if (error.response?.status === 422) {
                const fieldErrors = error.response.data?.errors ?? {};
                setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
            }
            showToast.error(extractErrorMessage(error, 'Failed to grant department scope.'));
        } finally {
            setSaving(false);
        }
    };

    const revoke = async (scope) => {
        try {
            const { data } = await axios.delete(route('users.department-scopes.destroy', { id: userId, scopeId: scope.id }));
            showToast.success(data.message || 'Department scope revoked.');
            setScopes((prev) => prev.filter((s) => s.id !== scope.id));
        } catch (error) {
            showToast.error(extractErrorMessage(error, 'Failed to revoke department scope.'));
        }
    };

    const fieldError = (key) => errors[key] && <Text color="red" size="1" mt="1" as="div">{errors[key]}</Text>;

    return (
        <Box>
            <Flex align="center" justify="between" mb="2" gap="2">
                <Box>
                    <Text as="div" size="2" weight="medium">Department scope</Text>
                    <Text as="div" size="1" color="gray">
                        Departments this person administers beyond the one they head. Acting charges end automatically.
                    </Text>
                </Box>
                {!adding && (
                    <Button type="button" size="1" variant="soft" onClick={() => setAdding(true)}>
                        <PlusIcon /> Add
                    </Button>
                )}
            </Flex>

            {loading ? (
                <Flex align="center" gap="2" py="2"><Spinner size="1" /><Text size="1" color="gray">Loading…</Text></Flex>
            ) : scopes.length === 0 ? (
                <Text as="p" size="1" color="gray" py="2">No department scope granted.</Text>
            ) : (
                <Flex direction="column" gap="2" role="list" aria-label="Department scope grants">
                    {scopes.map((scope) => (
                        <Flex
                            key={scope.id}
                            role="listitem"
                            align="center"
                            justify="between"
                            gap="3"
                            p="2"
                            style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', opacity: scope.status === 'expired' ? 0.65 : 1 }}
                        >
                            <Box style={{ minWidth: 0 }}>
                                <Flex align="center" gap="2" wrap="wrap">
                                    <Text size="2" weight="medium">{scope.department_name ?? `Department #${scope.department_id}`}</Text>
                                    <Badge size="1" variant="soft" color={scope.scope_type === 'acting' ? 'orange' : 'indigo'}>
                                        {scope.scope_type === 'acting' ? 'Acting' : 'Admin'}
                                    </Badge>
                                    <Badge size="1" variant="outline" color={STATUS_COLOR[scope.status] ?? 'gray'}>{scope.status}</Badge>
                                </Flex>
                                <Text as="div" size="1" color="gray">
                                    {windowLabel(scope)}
                                    {scope.granted_by ? ` · granted by ${scope.granted_by.name}` : ''}
                                </Text>
                                {scope.reason && <Text as="div" size="1" color="gray" style={{ overflowWrap: 'anywhere' }}>{scope.reason}</Text>}
                            </Box>
                            <Tooltip content="Revoke">
                                <IconButton
                                    type="button"
                                    size="1"
                                    variant="ghost"
                                    color="red"
                                    aria-label={`Revoke ${scope.department_name ?? 'department'} scope`}
                                    onClick={() => setToRevoke(scope)}
                                >
                                    <TrashIcon />
                                </IconButton>
                            </Tooltip>
                        </Flex>
                    ))}
                </Flex>
            )}

            {adding && (
                <Box mt="3" p="3" style={{ backgroundColor: 'var(--gray-2)', borderRadius: 'var(--radius-3)' }}>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="3">
                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Department</Text>
                            <Select.Root value={form.department_id ? String(form.department_id) : undefined} onValueChange={(v) => setField('department_id', v)}>
                                <Select.Trigger placeholder="Select department" style={{ width: '100%' }} aria-label="Department" />
                                <Select.Content position="popper">
                                    {departments.map((d) => (
                                        <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {fieldError('department_id')}
                        </Box>
                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Type</Text>
                            <Select.Root value={form.scope_type} onValueChange={(v) => setField('scope_type', v)}>
                                <Select.Trigger style={{ width: '100%' }} aria-label="Scope type" />
                                <Select.Content position="popper">
                                    <Select.Item value="admin">Admin (standing)</Select.Item>
                                    <Select.Item value="acting">Acting charge (temporary)</Select.Item>
                                </Select.Content>
                            </Select.Root>
                            {fieldError('scope_type')}
                        </Box>
                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Starts (optional)</Text>
                            <DateTimePicker mode="datetime" value={form.starts_at} onChange={(v) => setField('starts_at', v)} placeholder="Immediately" error={errors.starts_at} />
                        </Box>
                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">
                                Ends {form.scope_type === 'acting' ? <Text color="red">*</Text> : '(optional)'}
                            </Text>
                            <DateTimePicker mode="datetime" value={form.expires_at} onChange={(v) => setField('expires_at', v)} placeholder="No end date" error={errors.expires_at} />
                        </Box>
                        <Box gridColumn={{ initial: '1', sm: '1 / -1' }}>
                            <Text as="label" size="2" weight="medium" mb="1" display="block">Reason (optional)</Text>
                            <TextField.Root
                                placeholder="e.g. Acting head while the manager is on leave"
                                value={form.reason}
                                maxLength={1000}
                                onChange={(e) => setField('reason', e.target.value)}
                            />
                            {fieldError('reason')}
                        </Box>
                    </Grid>
                    <Flex gap="2" justify="end" mt="3">
                        <Button type="button" size="1" variant="soft" color="gray" disabled={saving} onClick={() => { setAdding(false); setForm(EMPTY_FORM); setErrors({}); }}>
                            Cancel
                        </Button>
                        <Button type="button" size="1" disabled={saving || !form.department_id} onClick={submit}>
                            {saving && <Spinner size="1" />} Grant scope
                        </Button>
                    </Flex>
                </Box>
            )}

            <ConfirmDialog
                open={!!toRevoke}
                onClose={() => setToRevoke(null)}
                onConfirm={() => toRevoke && revoke(toRevoke)}
                title="Revoke department scope?"
                description={toRevoke ? `${toRevoke.department_name ?? 'This department'} will no longer be in this person's scope. Their devices re-sync on next launch.` : ''}
                confirmText="Revoke"
                confirmColor="red"
            />
        </Box>
    );
}
