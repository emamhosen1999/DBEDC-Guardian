import React from 'react';
import { Box, Text } from '@radix-ui/themes';

const LocationPickerMap = ({ onLocationSelect, defaultLat, defaultLng, height = 300, ...rest }) => (
  <Box style={{ height, background: 'var(--gray-a3)', borderRadius: 'var(--radius-2)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
    <Text color="gray" size="2">Map picker — integration required</Text>
  </Box>
);

export default LocationPickerMap;
