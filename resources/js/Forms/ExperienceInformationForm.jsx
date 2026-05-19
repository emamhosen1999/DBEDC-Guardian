import React, { useState } from 'react';
import { Box, Button, Card, Flex, Grid, Text, TextField, TextArea } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

const ExperienceInformationForm = ({ user, setUser }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [experienceList, setExperienceList] = useState(user.experiences || [{ company_name: "", job_position: "", description: "" }]);
    const [processing, setProcessing] = useState(false);

    const handleUpdate = (index, field, value) => {
        const updated = [...experienceList];
        updated[index] = { ...updated[index], [field]: value };
        setExperienceList(updated);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const response = await fetch('/experience/update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ experiences: experienceList.map(ex => ({ ...ex, user_id: user.id })) }),
            });
            const data = await response.json();
            if (response.ok) {
                setUser(prev => ({ ...prev, experiences: data.experiences }));
                showToast.success('Experience updated');
                setIsEditing(false);
            }
        } catch { showToast.error('Save failed'); } finally { setProcessing(false); }
    };

    return (
        <Card variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Work Experience</Text>
                {!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}><Pencil1Icon /> Edit</Button>
                ) : (
                    <Button variant="ghost" size="1" color="red" onClick={() => setIsEditing(false)}><Cross2Icon /> Cancel</Button>
                )}
            </Flex>

            {!isEditing ? (
                <Box>
                    {experienceList.map((ex, i) => (
                        <Box key={i} mb="3" pb="3" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                            <Text weight="bold" size="2">{ex.job_position} @ {ex.company_name}</Text>
                        </Box>
                    ))}
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    {experienceList.map((ex, i) => (
                        <Card key={i} mb="3">
                            <TextField.Root mb="2" placeholder="Company" value={ex.company_name} onChange={e => handleUpdate(i, 'company_name', e.target.value)} />
                            <TextField.Root placeholder="Position" value={ex.job_position} onChange={e => handleUpdate(i, 'job_position', e.target.value)} />
                        </Card>
                    ))}
                    <Button type="submit" disabled={processing}>Save All</Button>
                </form>
            )}
        </Card>
    );
};
export default ExperienceInformationForm;