import React from 'react';
import { Button } from '@radix-ui/themes';

const GlassButton = ({ children, onPress, onClick, ...rest }) => (
  <Button onClick={onPress || onClick} {...rest}>{children}</Button>
);

export default GlassButton;
