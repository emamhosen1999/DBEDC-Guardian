import React from 'react';
import { Box, Heading, Separator } from '@radix-ui/themes';

const ProfileSection = ({ title, children, ...rest }) => (
  <Box mb="4">
    {title && (
      <>
        <Heading size="3" mb="2">{title}</Heading>
        <Separator size="4" mb="3" />
      </>
    )}
    {children}
  </Box>
);

export default ProfileSection;
