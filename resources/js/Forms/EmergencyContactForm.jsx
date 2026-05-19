import React, { useState, useEffect } from "react";
import { Box, Button, Card, Flex, Grid, Text, TextField } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, HeartIcon } from '@radix-ui/react-icons';
import axios from "axios";
import { showToast } from "@/utils/toastUtils";
import InfoRow from "@/Components/InfoRow.jsx";

const EmergencyContactForm = ({ user, setUser }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [formData, setFormData] = useState({
        id: user.id,
        emergency_contact_primary_name: user.emergency_contact_primary_name || '',
        emergency_contact_primary_relationship: user.emergency_contact_primary_relationship || '',
        emergency_contact_primary_phone: user.emergency_contact_primary_phone || '',
        emergency_contact_secondary_name: user.emergency_contact_secondary_name || '',
        emergency_contact_secondary_relationship: user.emergency_contact_secondary_relationship || '',
        emergency_contact_secondary_phone: user.emergency_contact_secondary_phone || ''
    });

    const handleChange = (key, value) => {
        setFormData(prev => ({ ...prev, [key]: value }));
    };

    const handleCancel = () => {
        setFormData({
            id: user.id,
            emergency_contact_primary_name: user.emergency_contact_primary_name || '',
            emergency_contact_primary_relationship: user.emergency_contact_primary_relationship || '',
            emergency_contact_primary_phone: user.emergency_contact_primary_phone || '',
            emergency_contact_secondary_name: user.emergency_contact_secondary_name || '',
            emergency_contact_secondary_relationship: user.emergency_contact_secondary_relationship || '',
            emergency_contact_secondary_phone: user.emergency_contact_secondary_phone || ''
        });
        setIsEditing(false);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const { data } = await axios.post(route('profile.update'), { ruleSet: 'emergency', ...formData });
            setUser(data.user);
            showToast.success('Emergency contacts updated');
            setIsEditing(false);
        } catch (err) {
            showToast.error('Failed to save changes');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Card variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Emergency Contacts</Text>
                {!isEditing ? (
                    <Button variant="ghost" size="1" onClick={() => setIsEditing(true)}>
                        <Pencil1Icon /> Edit
                    </Button>
                ) : (
                    <Button variant="ghost" size="1" color="red" onClick={handleCancel}>
                        <Cross2Icon /> Cancel
                    </Button>
                )}
            </Flex>

            {!isEditing ? (
                <Box>
                    <Text size="2" weight="bold" color="indigo" mb="2" mt="3" as="div">Primary Contact</Text>
                    <InfoRow label="Name" value={user.emergency_contact_primary_name} />
                    <InfoRow label="Relationship" value={user.emergency_contact_primary_relationship} />
                    <InfoRow label="Phone" value={user.emergency_contact_primary_phone} />
                    
                    <Text size="2" weight="bold" color="indigo" mb="2" mt="3" as="div">Secondary Contact</Text>
                    <InfoRow label="Name" value={user.emergency_contact_secondary_name} />
                    <InfoRow label="Relationship" value={user.emergency_contact_secondary_relationship} />
                    <InfoRow label="Phone" value={user.emergency_contact_secondary_phone} />
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    <Grid gap="3" mb="4">
                        <Text size="2" weight="bold" color="indigo">Primary Contact</Text>
                        <TextField.Root placeholder="Name" value={formData.emergency_contact_primary_name} onChange={e => handleChange('emergency_contact_primary_name', e.target.value)} />
                        <TextField.Root placeholder="Relationship" value={formData.emergency_contact_primary_relationship} onChange={e => handleChange('emergency_contact_primary_relationship', e.target.value)} />
                        <TextField.Root placeholder="Phone" value={formData.emergency_contact_primary_phone} onChange={e => handleChange('emergency_contact_primary_phone', e.target.value)} />

                        <Text size="2" weight="bold" color="indigo" mt="2">Secondary Contact</Text>
                        <TextField.Root placeholder="Name" value={formData.emergency_contact_secondary_name} onChange={e => handleChange('emergency_contact_secondary_name', e.target.value)} />
                        <TextField.Root placeholder="Relationship" value={formData.emergency_contact_secondary_relationship} onChange={e => handleChange('emergency_contact_secondary_relationship', e.target.value)} />
                        <TextField.Root placeholder="Phone" value={formData.emergency_contact_secondary_phone} onChange={e => handleChange('emergency_contact_secondary_phone', e.target.value)} />
                    </Grid>
                    <Flex justify="end">
                        <Button type="submit" disabled={processing}>Save Changes</Button>
                    </Flex>
                </form>
            )}
        </Card>
    );
};

export default EmergencyContactForm;