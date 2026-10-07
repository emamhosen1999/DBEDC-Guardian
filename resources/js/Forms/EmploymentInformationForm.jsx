import { Panel } from '@/Components/ui/Panel';
import React, { useCallback, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Box, Button, Flex, Grid, Text, Select, TextField } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, BackpackIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import InfoRow from "@/Components/InfoRow.jsx";
import DepartmentField from '@/Components/Access/DepartmentField';
import ReportingManagerPicker from '@/Components/Access/ReportingManagerPicker';

const EmploymentInformationForm = ({ user, setUser, departments = [], designations = [], allUsers = [], reportTo = null, canEdit = false }) => {
    const { auth, administration } = usePage().props;
    const heads = administration?.heads ?? [];
    const alsoAdministers = (administration?.also_administers ?? []).map((g) => (g.expires_at ? `${g.department} (until ${g.expires_at})` : g.department));
    const [isEditing, setIsEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [formData, setFormData] = useState({
        id: user.id,
        department: user.department_id || '',
        designation: user.designation_id || '',
        // The employee's CURRENT manager (an employee id): never submit a blank for it by accident.
        report_to: user.report_to ? String(user.report_to) : ''
    });
    const setDepartment = useCallback((value) => setFormData((prev) => (String(prev.department) === String(value) ? prev : { ...prev, department: value, designation: '' })), []);

    // Only the chosen department's designations; the reporting manager comes from the scoped picker.
    const departmentDesignations = useMemo(
        () => designations.filter((d) => String(d.department_id) === String(formData.department)),
        [designations, formData.department],
    );

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const { data } = await axios.post(route('profile.update'), { ruleSet: 'employment', ...formData });
            setUser(data.user);
            showToast.success('Employment info updated');
            setIsEditing(false);
        } catch (err) {
            showToast.error('Failed to save');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Panel variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Employment Details</Text>
                {canEdit && (!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}><Pencil1Icon /> Edit</Button>
                ) : (
                    <Button variant="ghost" size="1" color="red" onClick={() => setIsEditing(false)}><Cross2Icon /> Cancel</Button>
                ))}
            </Flex>

            {!isEditing ? (
                <Box>
                    <InfoRow label="Department" value={user.department?.name || '—'} icon={<BackpackIcon />} />
                    <InfoRow label="Designation" value={user.designation?.title || '—'} />
                    <InfoRow label="Reports To" value={reportTo?.name || '—'} />
                    {heads.length > 0 && <InfoRow label="Heads" value={heads.join(', ')} />}
                    {alsoAdministers.length > 0 && <InfoRow label="Also administers" value={alsoAdministers.join(', ')} />}
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    <Grid gap="4" mb="4">
                        <DepartmentField value={formData.department} onChange={setDepartment} departments={departments} />
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Designation</Text>
                            <Select.Root value={formData.designation ? String(formData.designation) : undefined} onValueChange={v => setFormData({...formData, designation: v})} disabled={!formData.department || departmentDesignations.length === 0}>
                                <Select.Trigger style={{ width: '100%' }} placeholder={!formData.department ? 'Select department first' : (departmentDesignations.length === 0 ? 'No designations in this department yet' : 'Select designation')} />
                                <Select.Content>
                                    {departmentDesignations.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Reports To</Text>
                            <ReportingManagerPicker value={formData.report_to} employeeId={user.id} onChange={(v) => setFormData({ ...formData, report_to: v })} />
                        </Box>
                    </Grid>
                    <Flex justify="end">
                        <Button type="submit" disabled={processing}>Save Changes</Button>
                    </Flex>
                </form>
            )}
        </Panel>
    );
};

export default EmploymentInformationForm;
