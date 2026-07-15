import { Panel } from '@/Components/ui/Panel';
import React, { useState } from 'react';
import { Box, Button, Flex, Grid, Text, TextField, IconButton } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

const EducationInformationForm = ({ user, setUser }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [educationList, setEducationList] = useState(user.educations || [{ institution: "", degree: "", subject: "", starting_date: "", complete_date: "", grade: "" }]);
    const [processing, setProcessing] = useState(false);

    const handleUpdate = (index, field, value) => {
        const updated = [...educationList];
        updated[index] = { ...updated[index], [field]: value };
        setEducationList(updated);
    };

    const handleAdd = () => setEducationList([...educationList, { institution: "", degree: "", subject: "", starting_date: "", complete_date: "", grade: "" }]);

    const handleRemove = (index) => {
        setEducationList(educationList.filter((_, i) => i !== index));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const response = await fetch('/education/update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ educations: educationList.map(e => ({ ...e, user_id: user.id })) }),
            });
            const data = await response.json();
            if (response.ok) {
                setUser(prev => ({ ...prev, educations: data.educations }));
                showToast.success('Education updated');
                setIsEditing(false);
            }
        } catch { showToast.error('Failed to save'); } finally { setProcessing(false); }
    };

    return (
        <Panel variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Education History</Text>
                {!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}><Pencil1Icon /> Edit</Button>
                ) : (
                    <Button variant="ghost" size="1" color="red" onClick={() => setIsEditing(false)}><Cross2Icon /> Cancel</Button>
                )}
            </Flex>

            {!isEditing ? (
                <Box>
                    {educationList.map((ed, i) => (
                        <Box key={i} mb="3" pb="3" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                            <Text weight="bold" size="2">{ed.institution}</Text>
                            <Text color="gray" size="2" display="block">{ed.degree} in {ed.subject}</Text>
                        </Box>
                    ))}
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    <Flex direction="column" gap="4" mb="4">
                        {educationList.map((ed, i) => (
                            <Panel key={i} variant="classic">
                                <Flex justify="between" mb="2">
                                    <Text size="1" weight="bold">Item {i + 1}</Text>
                                    <IconButton size="1" variant="ghost" color="red" onClick={() => handleRemove(i)}><Cross2Icon /></IconButton>
                                </Flex>
                                <Grid columns="2" gap="3">
                                    <TextField.Root placeholder="Institution" value={ed.institution} onChange={e => handleUpdate(i, 'institution', e.target.value)} />
                                    <TextField.Root placeholder="Degree" value={ed.degree} onChange={e => handleUpdate(i, 'degree', e.target.value)} />
                                </Grid>
                            </Panel>
                        ))}
                        <Button type="button" variant="soft" onClick={handleAdd}><PlusIcon /> Add Item</Button>
                    </Flex>
                    <Flex justify="end"><Button type="submit" disabled={processing}>Save All</Button></Flex>
                </form>
            )}
        </Panel>
    );
};
export default EducationInformationForm;