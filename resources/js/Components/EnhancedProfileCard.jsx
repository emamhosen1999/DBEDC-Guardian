import React from 'react';
import { Card, Flex, Text, Box } from '@radix-ui/themes';
import ProfileAvatar from './ProfileAvatar';

const EnhancedProfileCard = ({ user, actions, ...rest }) => (
  <Card>
    <Flex align="center" gap="3">
      <ProfileAvatar src={user?.profile_image_url || user?.profile_image} name={user?.name} size="lg" />
      <Box style={{ minWidth: 0 }}>
        <Text size="3" weight="bold" style={{ display: 'block' }}>{user?.name}</Text>
        <Text size="2" color="gray" style={{ display: 'block' }}>{user?.designation?.title || user?.email}</Text>
      </Box>
      {actions && <Box style={{ marginLeft: 'auto' }}>{actions}</Box>}
    </Flex>
  </Card>
);

export default EnhancedProfileCard;
