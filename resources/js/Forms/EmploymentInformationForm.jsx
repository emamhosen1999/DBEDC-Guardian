import { Panel } from '@/Components/ui/Panel';
import React, { useCallback, useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Box, Button, Flex, Grid, Text, Select, TextField } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, BackpackIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import InfoRow from "@/Components/InfoRow.jsx";
import DepartmentField from '@/Components/Access/DepartmentField';
import { eligibleManagers } from '@/utils/reportingLine';

const EmploymentInformationForm = ({ user, setUser, departments = [], designations = [], allUsers = [], reportTo = null, canEdit = false }) => {
    const { auth } = usePage().props;
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

    // Only the chosen department's designations; managers per the one reporting-line rule (the person
    // administering included, a department with no designations still has a list).
    const departmentDesignations = useMemo(
        () => designations.filter((d) => String(d.department_id) === String(formData.department)),
        [designations, formData.department],
    );
    const managers = useMemo(() => eligibleManagers({
        managers: allUsers.map((u) => ({
            ...u,
            designation_hierarchy_level: designations.find((d) => String(d.id) === String(u.designation_id))?.hierarchy_level ?? null,
        })),
        subject: { id: user.id, department_id: formData.department },
        subjectLevel: designations.find((d) => String(d.id) === String(formData.designation))?.hierarchy_level ?? null,
        actorId: auth?.user?.employee_id ?? null,
        currentManagerId: user.report_to ?? null,
    }), [allUsers, designations, formData.department, formData.designation, user.id, user.report_to, auth?.user?.employee_id]);

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
                            <Select.Root value={formData.report_to ? String(formData.report_to) : undefined} onValueChange={v => setFormData({...formData, report_to: v})} disabled={managers.length === 0}>
                                <Select.Trigger style={{ width: '100%' }} placeholder={managers.length === 0 ? 'No supervisor available' : 'Select a supervisor'} />
                                <Select.Content>
                                    {managers.map(u => <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
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
