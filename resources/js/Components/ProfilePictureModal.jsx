import React, { useRef, useState } from 'react';
import { Dialog, Button, Flex, Text, Spinner } from '@radix-ui/themes';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';

const ProfilePictureModal = ({ isOpen = false, onClose, employee, onImageUpdate }) => {
  const inputRef = useRef();
  const [uploading, setUploading] = useState(false);

  const handleFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file || !employee) return;

    setUploading(true);
    const formData = new FormData();
    formData.append('user_id', employee.id);
    formData.append('profile_image', file);

    try {
      const res = await axios.post('/profile/image/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });
      if (res.data?.success) {
        showToast.success('Profile picture updated successfully');
        onImageUpdate?.(employee.id, res.data.profile_image_url);
        onClose?.();
      } else {
        showToast.error(res.data?.message || 'Failed to upload profile picture');
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || err.message || 'Failed to upload profile picture';
      showToast.error(errMsg);
    } finally {
      setUploading(false);
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    }
  };

  return (
    <Dialog.Root open={isOpen} onOpenChange={v => { if (!v && !uploading) onClose?.(); }}>
      <Dialog.Content style={{ maxWidth: 400 }}>
        <Dialog.Title>Update Profile Picture</Dialog.Title>
        <Text size="2" color="gray" as="div" mb="3">
          Choose a new profile picture to upload for <strong>{employee?.name}</strong>.
        </Text>
        <input 
          ref={inputRef} 
          type="file" 
          accept="image/jpeg,image/png,image/jpg,image/webp" 
          style={{ display: 'none' }} 
          onChange={handleFile} 
          disabled={uploading}
        />
        <Flex justify="end" gap="2" mt="4">
          <Button variant="outline" color="gray" onClick={onClose} disabled={uploading}>Cancel</Button>
          <Button onClick={() => inputRef.current?.click()} loading={uploading}>
            {uploading ? 'Uploading...' : 'Choose File'}
          </Button>
        </Flex>
      </Dialog.Content>
    </Dialog.Root>
  );
};

export default ProfilePictureModal;
