import React, { useState } from 'react';
import { Box, Button, Flex, Heading, Select, Text } from '@radix-ui/themes';
import axios from 'axios';
import { Panel } from '@/Components/ui/Panel';
import { showToast } from '@/utils/toastUtils';

const NONE = '__none__';

/**
 * approvals.escalation_approver_id: who decides requests from people with no manager when no HR Manager
 * exists. Visible to everyone with company settings, editable by a Super Administrator only (server-enforced).
 */
const EscalationApproverPanel = ({ escalation }) => {
    const [value, setValue] = useState(escalation?.approver_id ?? NONE);
    const [saving, setSaving] = useState(false);
    if (!escalation) return null;

    const save = async () => {
        setSaving(true);
        try {
            await axios.put(route('update-escalation-approver'), { escalation_approver_id: value === NONE ? null : value });
            showToast.success('Escalation approver updated');
        } catch (e) {
            showToast.error(e.response?.data?.message || 'Failed to save the escalation approver');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Panel size="3" style={{ width: '100%', maxWidth: 960 }}>
            <Box p="4">
                <Heading size="4" mb="2">Approval escalation</Heading>
                <Text size="2" color="gray" as="p" mb="3">
                    Requests from people without a reporting manager go to the HR Manager, then to this approver, and only
                    then to a Super Administrator.
                </Text>
                {!escalation.has_hr_manager && (
                    <Text size="2" color="amber" as="p" mb="3">No HR Manager or escalation approver is set: such requests fall back to Super Administrators.</Text>
                )}
                <Flex gap="3" align="end" wrap="wrap">
                    <Box style={{ minWidth: 260 }}>
                        <Text as="label" size="2" weight="medium" mb="1" style={{ display: 'block' }}>Escalation approver</Text>
                        <Select.Root value={value} onValueChange={setValue} disabled={!escalation.can_edit}>
                            <Select.Trigger style={{ width: '100%' }} placeholder="Select an employee" />
                            <Select.Content>
                                <Select.Item value={NONE}>None</Select.Item>
                                {(escalation.candidates ?? []).map((c) => <Select.Item key={c.id} value={c.id}>{c.name} ({c.id})</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    </Box>
                    {escalation.can_edit && <Button onClick={save} disabled={saving}>Save</Button>}
                </Flex>
                {!escalation.can_edit && <Text size="1" color="gray" as="p" mt="2">Only a Super Administrator can change this.</Text>}
            </Box>
        </Panel>
    );
};

export default EscalationApproverPanel;
