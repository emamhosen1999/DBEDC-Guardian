import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, Separator, TextArea, Grid } from '@radix-ui/themes';
import { AdjustmentsHorizontalIcon, PlusIcon, PencilSquareIcon, TrashIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { showOperationMutationErrors } from './mutationFeedback';
import { useQueryFilters } from '@/Hooks/useQueryFilters';

export default function OmLookupsManager({ auth, lookups, filters, lookupTypes }) {
    const canManage = auth?.permissions?.includes('om.dashboard.view') || auth?.roles?.includes('Super Administrator');
    const [openModal, setOpenModal] = useState(false);
    const [editingLookup, setEditingLookup] = useState(null);

    // Form states
    const [type, setType] = useState('defect_category');
    const [key, setKey] = useState('');
    const [label, setLabel] = useState('');
    const [slaHours, setSlaHours] = useState('');
    const [badgeColor, setBadgeColor] = useState('blue');
    const [sortOrder, setSortOrder] = useState('0');
    const [description, setDescription] = useState('');
    /* Type and search are server state, so they live in the URL. */
    const f = useQueryFilters({
        defaults: { type: 'all', search: '', page: 1 },
    });
    const searchTerm = f.draft.search;

    const lookupList = lookups?.data || [];

    const handleOpenCreate = () => {
        setEditingLookup(null);
        setType(f.values.type !== 'all' ? f.values.type : 'defect_category');
        setKey('');
        setLabel('');
        setSlaHours('');
        setBadgeColor('blue');
        setSortOrder('0');
        setDescription('');
        setOpenModal(true);
    };

    const handleOpenEdit = (item) => {
        setEditingLookup(item);
        setType(item.type);
        setKey(item.key);
        setLabel(item.label);
        setSlaHours(item.sla_hours ? String(item.sla_hours) : '');
        setBadgeColor(item.badge_color || 'blue');
        setSortOrder(String(item.sort_order ?? 0));
        setDescription(item.description || '');
        setOpenModal(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        const payload = {
            type,
            key: key || label.toLowerCase().replace(/[^a-z0-9]+/g, '_'),
            label,
            sla_hours: slaHours ? parseInt(slaHours, 10) : null,
            badge_color: badgeColor,
            sort_order: parseInt(sortOrder, 10) || 0,
            description,
        };

        if (editingLookup) {
            router.put(`/om/lookups/${editingLookup.id}`, payload, {
                onSuccess: () => setOpenModal(false),
                onError: showOperationMutationErrors,
            });
        } else {
            router.post('/om/lookups', payload, {
                onSuccess: () => setOpenModal(false),
                onError: showOperationMutationErrors,
            });
        }
    };

    const handleDelete = (id) => {
        if (!confirm('Are you sure you want to remove this selectable dropdown option?')) return;
        router.delete(`/om/lookups/${id}`, {
            onError: showOperationMutationErrors,
        });
    };

    const handleFilterChange = (newType) => f.set('type', newType);

    // Search commits on its own after a pause; submitting just skips the wait.
    const handleSearchSubmit = (e) => {
        e.preventDefault();
        f.setMany({ search: f.draft.search });
    };

    const statItems = [
        { key: 'total', title: 'Total Lookups Configured', value: lookups?.total || lookupList.length, color: 'blue' },
        { key: 'categories', title: 'Defect Distress Types', value: lookupList.filter(l => l.type === 'defect_category').length, color: 'orange' },
        { key: 'locations', title: 'Carriageway Locations', value: lookupList.filter(l => l.type === 'carriageway_location').length, color: 'green' },
        { key: 'severities', title: 'Severity Levels & SLAs', value: lookupList.filter(l => l.type === 'severity').length, color: 'purple' },
    ];

    return (
        <App auth={auth}>
            <Head title="O&M Dynamic Categories & Dropdown Settings" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--amber-a3)', borderRadius: 12, border: '1px solid var(--amber-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <AdjustmentsHorizontalIcon style={{ width: 22, height: 22, color: 'var(--amber-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            O&M Dynamic Categories & Dropdown Lookups
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Admin Master Settings: Manage defect categories, severities, carriageway locations, and default SLA hours without code changes
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && (
                                    <Button color="amber" onClick={handleOpenCreate} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                        <PlusIcon width={16} height={16} /> Add Dropdown Option
                                    </Button>
                                )}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '4' }} mb="4" />

                        {/* Filter Bar */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} gap="3" justify="between" align={{ initial: 'stretch', sm: 'center' }}>
                                <Flex gap="2" align="center" wrap="wrap">
                                    <Text size="2" weight="bold">Filter Category:</Text>
                                    <Select.Root value={f.values.type} onValueChange={handleFilterChange}>
                                        <Select.Trigger style={{ minWidth: 220 }} />
                                        <Select.Content>
                                            {lookupTypes.map(t => (
                                                <Select.Item key={t.value} value={t.value}>{t.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Flex>

                                <form onSubmit={handleSearchSubmit}>
                                    <Flex gap="2">
                                        <TextField.Root placeholder="Search label or key..." value={searchTerm} onChange={e => f.setDraft('search', e.target.value)} style={{ width: 240 }} />
                                        <Button type="submit" variant="soft" color="gray">Search</Button>
                                    </Flex>
                                </form>
                            </Flex>
                        </Box>

                        {/* Lookups Table */}
                        <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Table.Root size="2" style={{ minWidth: 900, width: '100%' }}>
                                <Table.Header style={{
                                    position: 'sticky',
                                    top: 0,
                                    zIndex: 2,
                                    background: 'var(--aero-surface, var(--color-background))',
                                    backdropFilter: 'blur(8px)',
                                    boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                }}>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ minWidth: 160 }}>Type Group</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 220 }}>Display Label</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 160 }}>Key Identifier</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 100 }}>SLA Hours</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 90 }}>Sort</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 90 }}>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 120 }}>Actions</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {lookupList.length === 0 ? (
                                        <Table.Row>
                                            <Table.Cell colSpan={7} style={{ textAlign: 'center', padding: '32px 0' }}>
                                                <Text size="2" color="gray">No configuration values found for this group filter.</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ) : (
                                        lookupList.map((item) => (
                                            <Table.Row key={item.id} align="center">
                                                <Table.Cell>
                                                    <Badge color={item.badge_color || 'gray'} variant="soft">
                                                        {item.type.replace(/_/g, ' ').toUpperCase()}
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text weight="bold">{item.label}</Text>
                                                    {item.description && (
                                                        <Text size="1" color="gray" style={{ display: 'block' }}>{item.description}</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell style={{ fontFamily: 'monospace', fontSize: 12 }}>
                                                    {item.key}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {item.sla_hours ? (
                                                        <Badge color="orange" variant="surface">{item.sla_hours}h</Badge>
                                                    ) : (
                                                        <Text size="2" color="gray">—</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text size="2" color="gray">{item.sort_order}</Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {item.is_active ? (
                                                        <Badge color="green" variant="soft">Active</Badge>
                                                    ) : (
                                                        <Badge color="gray" variant="soft">Disabled</Badge>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell style={{ textAlign: 'right' }}>
                                                    {canManage && (
                                                        <Flex gap="2" justify="end">
                                                            <Button size="1" variant="ghost" color="blue" onClick={() => handleOpenEdit(item)}>
                                                                <PencilSquareIcon width={14} height={14} /> Edit
                                                            </Button>
                                                            <Button size="1" variant="ghost" color="red" onClick={() => handleDelete(item.id)}>
                                                                <TrashIcon width={14} height={14} />
                                                            </Button>
                                                        </Flex>
                                                    )}
                                                </Table.Cell>
                                            </Table.Row>
                                        ))
                                    )}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            {/* Create/Edit Modal */}
            <Dialog.Root open={openModal} onOpenChange={setOpenModal}>
                <Dialog.Content style={{ maxWidth: 500 }}>
                    <Dialog.Title>{editingLookup ? 'Edit Dropdown Lookup' : 'Add New Dropdown Option'}</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Configure selectable options for roadway distresses, severity ratings, carriageway sectors, and contractors.
                    </Dialog.Description>
                    <form onSubmit={handleSubmit}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Option Type / Category</Text>
                                <Select.Root value={type} onValueChange={setType}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="defect_category">Defect Distress Category</Select.Item>
                                        <Select.Item value="severity">Severity Level & SLA</Select.Item>
                                        <Select.Item value="carriageway_location">Carriageway & Location</Select.Item>
                                        <Select.Item value="responsible_party">Responsible Party / Contractor</Select.Item>
                                        <Select.Item value="work_order_category">Work Order Activity Category</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Display Label (Human Readable)</Text>
                                <TextField.Root placeholder="e.g. Anti-Glare Panel Board Damage" value={label} onChange={e => setLabel(e.target.value)} required />
                            </label>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Key Identifier</Text>
                                    <TextField.Root placeholder="e.g. anti_glare_panel" value={key} onChange={e => setKey(e.target.value)} />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Default SLA (Hours)</Text>
                                    <TextField.Root placeholder="e.g. 24" type="number" value={slaHours} onChange={e => setSlaHours(e.target.value)} />
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Badge Color</Text>
                                    <Select.Root value={badgeColor} onValueChange={setBadgeColor}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="blue">Blue</Select.Item>
                                            <Select.Item value="amber">Amber / Orange</Select.Item>
                                            <Select.Item value="red">Red (Critical)</Select.Item>
                                            <Select.Item value="green">Green</Select.Item>
                                            <Select.Item value="purple">Purple</Select.Item>
                                            <Select.Item value="cyan">Cyan</Select.Item>
                                            <Select.Item value="gray">Gray</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Sort Order Rank</Text>
                                    <TextField.Root placeholder="0" type="number" value={sortOrder} onChange={e => setSortOrder(e.target.value)} />
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Description / Technical Notes</Text>
                                <TextArea placeholder="Additional criteria or scope..." value={description} onChange={e => setDescription(e.target.value)} />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenModal(false)}>Cancel</Button>
                                <Button type="submit" color="amber">{editingLookup ? 'Save Changes' : 'Create Option'}</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
