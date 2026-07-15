import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect } from 'react';
import { Box, Button, Flex, Grid, Text, TextField } from '@radix-ui/themes';
import { Pencil1Icon, Cross2Icon, ArchiveIcon } from '@radix-ui/react-icons';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import InfoRow from "@/Components/InfoRow.jsx";

const BankInformationForm = ({ user, setUser }) => {
    const [isEditing, setIsEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [formData, setFormData] = useState({
        id: user.id,
        bank_name: user.bank_name || '',
        bank_account_no: user.bank_account_no || '',
        ifsc_code: user.ifsc_code || '',
        pan_no: user.pan_no || ''
    });

    const [dataChanged, setDataChanged] = useState(false);

    const handleChange = (key, value) => {
        setFormData(prev => ({ ...prev, [key]: value }));
    };

    const handleCancel = () => {
        setFormData({
            id: user.id,
            bank_name: user.bank_name || '',
            bank_account_no: user.bank_account_no || '',
            ifsc_code: user.ifsc_code || '',
            pan_no: user.pan_no || ''
        });
        setIsEditing(false);
    };

    useEffect(() => {
        const hasChanges = Object.keys(formData).some(k => k !== 'id' && formData[k] !== user[k]);
        setDataChanged(hasChanges);
    }, [formData, user]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        try {
            const { data } = await axios.post(route('profile.update'), { ruleSet: 'bank', ...formData });
            setUser(data.user);
            showToast.success('Bank information updated');
            setIsEditing(false);
        } catch (err) {
            showToast.error('Failed to save bank information');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Panel variant="surface" size="2">
            <Flex justify="between" align="center" mb="4">
                <Text size="3" weight="bold">Banking Information</Text>
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
                    <InfoRow label="Bank Name" value={user.bank_name} icon={<ArchiveIcon />} />
                    <InfoRow label="Account No" value={user.bank_account_no} />
                    <InfoRow label="IFSC Code" value={user.ifsc_code} />
                    <InfoRow label="PAN No" value={user.pan_no} />
                </Box>
            ) : (
                <form onSubmit={handleSubmit}>
                    <Grid gap="3" mb="4">
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Bank Name</Text>
                            <TextField.Root value={formData.bank_name} onChange={e => handleChange('bank_name', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">Account Number</Text>
                            <TextField.Root value={formData.bank_account_no} onChange={e => handleChange('bank_account_no', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">IFSC Code</Text>
                            <TextField.Root value={formData.ifsc_code} onChange={e => handleChange('ifsc_code', e.target.value)} />
                        </Box>
                        <Box>
                            <Text size="2" weight="medium" mb="1" display="block">PAN Number</Text>
                            <TextField.Root value={formData.pan_no} onChange={e => handleChange('pan_no', e.target.value)} />
                        </Box>
                    </Grid>
                    <Flex justify="end">
                        <Button type="submit" disabled={!dataChanged || processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </Flex>
                </form>
            )}
        </Panel>
    );
};

export default BankInformationForm;