import React, { useState } from 'react';
import { Box, Button, Card, Flex, Grid, Text, Select, TextField } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, BackpackIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import InfoRow from "@/Components/InfoRow.jsx";

const EmploymentInformationForm = ({ user, setUser, departments, designations, allUsers }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [formData, setFormData] = useState({
        id: user.id,
        department: user.department_id || '',
        designation: user.designation_id || '',
        report_to: user.report_to_id || ''
    });

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
        <Card variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Employment Details</Text>
                {!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}><Pencil1Icon /> Edit</Button>
                ) : (
                    <Button variant="ghost" size="1" color="red" onClick={() => setIsEditing(false)}><Cross2Icon /> Cancel</Button>
                )}
            </Flex>

            {!isEditing ? (
                <Box>
                    <InfoRow label="Department" value={user.department?.name || '—'} icon={<BackpackIcon />} />
                    <InfoRow label="Designation" value={user.designation?.title || '—'} />
                    <InfoRow label="Reports To" value={user.report_to?.name || '—'} />
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    <Grid gap="4" mb="4">
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Department</Text>
                            <Select.Root value={String(formData.department)} onValueChange={v => setFormData({...formData, department: v})}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    {departments.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Designation</Text>
                            <Select.Root value={String(formData.designation)} onValueChange={v => setFormData({...formData, designation: v})}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    {designations.map(d => <Select.Item key={d.id} value={String(d.id)}>{d.title}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Reports To</Text>
                            <Select.Root value={String(formData.report_to)} onValueChange={v => setFormData({...formData, report_to: v})}>
                                <Select.Trigger style={{ width: '100%' }} />
                                <Select.Content>
                                    {allUsers.map(u => <Select.Item key={u.id} value={String(u.id)}>{u.name}</Select.Item>)}
                                </Select.Content>
                            </Select.Root>
                        </Box>
                    </Grid>
                    <Flex justify="end">
                        <Button type="submit" disabled={processing}>Save Changes</Button>
                    </Flex>
                </form>
            )}
        </Card>
    );
};

export default EmploymentInformationForm;