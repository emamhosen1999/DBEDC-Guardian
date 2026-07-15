import { Panel } from '@/Components/ui/Panel';
import React, { useState } from 'react';
import { Flex, Text, Box, Badge, IconButton, Spinner } from '@radix-ui/themes';
import { CameraIcon } from '@radix-ui/react-icons';
import ProfileAvatar from './ProfileAvatar';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const ProfileCard = ({ user, setUser, canEdit, completion }) => {
    const [uploading, setUploading] = useState(false);

    const handleImageUpload = async (event) => {
        const file = event.target.files[0];
        if (!file) return;

        if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) {
            return showToast.error('Invalid file type. Only JPEG and PNG are allowed.');
        }

        setUploading(true);
        const formData = new FormData();
        formData.append('id', user.id);
        formData.append('profile_image', file);
        formData.append('ruleSet', 'profile_image');

        try {
            const response = await axios.post(route('profile.update'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (response.data.user) {
                setUser(response.data.user);
                showToast.success('Profile image updated successfully');
            }
        } catch (error) {
            showToast.error(error.response?.data?.error || 'Failed to update image');
        } finally {
            setUploading(false);
            event.target.value = null; // reset input
        }
    };

    return (
        <Panel size="3" variant="surface" style={{ backgroundColor: 'var(--gray-a2)' }}>
            <Flex align="center" gap="4" wrap="wrap">
                
                {/* Avatar with Inline Upload */}
                <Box position="relative">
                    <ProfileAvatar 
                        src={user?.profile_image_url || user?.profile_image} 
                        name={user?.name} 
                        size="xl" 
                        style={{ width: 80, height: 80, opacity: uploading ? 0.5 : 1 }} 
                    />
                    
                    {canEdit && (
                        <Box position="absolute" bottom="0" right="0">
                            <label style={{ cursor: 'pointer' }}>
                                <input 
                                    type="file" 
                                    accept="image/jpeg, image/png, image/jpg" 
                                    style={{ display: 'none' }} 
                                    onChange={handleImageUpload}
                                    disabled={uploading}
                                />
                                <IconButton 
                                    as="span" 
                                    size="1" 
                                    radius="full" 
                                    color="indigo" 
                                    variant="solid" 
                                    style={{ pointerEvents: 'none' }}
                                >
                                    {uploading ? <Spinner size="1" /> : <CameraIcon />}
                                </IconButton>
                            </label>
                        </Box>
                    )}
                </Box>

                {/* User Details */}
                <Box style={{ flex: 1, minWidth: 200 }}>
                    <Flex align="center" gap="3" mb="1">
                        <Text size="5" weight="bold">{user?.name}</Text>
                    </Flex>
                    <Text size="2" color="gray" as="div" mb="1">
                        {user?.designation?.title || user?.designation || 'No Designation'} 
                        {user?.department?.name || user?.department ? ` • ${user?.department?.name || user?.department}` : ''}
                    </Text>
                    <Text size="2" color="gray" as="div">
                        {user?.email} • {user?.phone || 'No phone'}
                    </Text>
                </Box>

                {/* Completion Bar */}
                <Box style={{ width: '100%', maxWidth: 300 }}>
                    <Flex justify="between" mb="1">
                        <Text size="1" color="gray" weight="medium">Profile Completion</Text>
                        <Text size="1" color="indigo" weight="bold">{completion}%</Text>
                    </Flex>
                    <Box style={{ height: 6, backgroundColor: 'var(--gray-a4)', borderRadius: 'var(--radius-full)', overflow: 'hidden' }}>
                        <Box style={{ width: `${completion}%`, height: '100%', backgroundColor: 'var(--indigo-9)', transition: 'width 0.5s ease' }} />
                    </Box>
                </Box>

            </Flex>
        </Panel>
    );
};

export default ProfileCard;