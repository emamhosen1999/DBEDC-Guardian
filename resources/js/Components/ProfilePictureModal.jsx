import React, { useRef } from 'react';
import { Dialog, Button, Flex, Text } from '@radix-ui/themes';

const ProfilePictureModal = ({ isOpen = false, onClose, onUpload, ...rest }) => {
  const inputRef = useRef();
  const handleFile = (e) => {
    const file = e.target.files?.[0];
    if (file) { onUpload?.(file); onClose?.(); }
  };
  return (
    <Dialog.Root open={isOpen} onOpenChange={v => { if (!v) onClose?.(); }}>
      <Dialog.Content style={{ maxWidth: 400 }}>
        <Dialog.Title>Update Profile Picture</Dialog.Title>
        <Text size="2" color="gray">Choose a new profile picture to upload.</Text>
        <input ref={inputRef} type="file" accept="image/*" style={{ display: 'none' }} onChange={handleFile} />
        <Flex justify="end" gap="2" mt="4">
          <Button variant="outline" color="gray" onClick={onClose}>Cancel</Button>
          <Button onClick={() => inputRef.current?.click()}>Choose File</Button>
        </Flex>
      </Dialog.Content>
    </Dialog.Root>
  );
};

export default ProfilePictureModal;
