import React, { useState } from "react";
import { Box, Button, Card, Flex, Grid, Text, TextField, Separator, Badge } from '@radix-ui/themes';
import { Pencil1Icon, CheckIcon, Cross2Icon } from '@radix-ui/react-icons';
import axios from "axios";
import { showToast } from "@/utils/toastUtils";
import InfoRow from "@/Components/InfoRow.jsx";

const PersonalInformationForm = ({ user, setUser }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    
    // Form state initialized with current user data
    const [formData, setFormData] = useState({
        id: user.id,
        passport_no: user.passport_no || '',
        passport_exp_date: user.passport_exp_date || '',
        nationality: user.nationality || '',
        religion: user.religion || '',
        marital_status: user.marital_status || '',
        nid: user.nid || ''
    });

    const handleChange = (key, value) => {
        setFormData(prev => ({ ...prev, [key]: value }));
    };

    const handleCancel = () => {
        // Reset form to user state
        setFormData({
            id: user.id,
            passport_no: user.passport_no || '',
            passport_exp_date: user.passport_exp_date || '',
            nationality: user.nationality || '',
            religion: user.religion || '',
            marital_status: user.marital_status || '',
            nid: user.nid || ''
        });
        setIsEditing(false);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const { data } = await axios.post(route('profile.update'), { ruleSet: 'personal', ...formData });
            setUser(data.user);
            showToast.success('Profile updated');
            setIsEditing(false);
        } catch (err) {
            showToast.error('Failed to save changes');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Card size="2" variant="surface">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Personal Information</Text>
                {!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}>
                        <Pencil1Icon /> Edit
                    </Button>
                ) : (
                    <Flex gap="2">
                        <Button variant="ghost" size="1" color="red" onClick={handleCancel}>
                            <Cross2Icon /> Cancel
                        </Button>
                    </Flex>
                )}
            </Flex>

            {!isEditing ? (
                /* VIEW MODE */
                <Box>
                    <InfoRow label="Passport No" value={user.passport_no} />
                    <InfoRow label="Expiry Date" value={user.passport_exp_date} />
                    <InfoRow label="NID No" value={user.nid} />
                    <InfoRow label="Nationality" value={user.nationality} />
                    <InfoRow label="Religion" value={user.religion} />
                    <InfoRow label="Marital Status" value={user.marital_status} />
                </Box>
            ) : (
                /* EDIT MODE */
                <form onSubmit={handleSubmit}>
                    <Grid columns="2" gap="3" mb="4">
                        <Box>
                            <Text size="1" weight="medium">Passport No</Text>
                            <TextField.Root value={formData.passport_no} onChange={e => handleChange('passport_no', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="1" weight="medium">Expiry Date</Text>
                            <TextField.Root type="date" value={formData.passport_exp_date} onChange={e => handleChange('passport_exp_date', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="1" weight="medium">NID No</Text>
                            <TextField.Root value={formData.nid} onChange={e => handleChange('nid', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="1" weight="medium">Nationality</Text>
                            <TextField.Root value={formData.nationality} onChange={e => handleChange('nationality', e.target.value)} />
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

export default PersonalInformationForm;