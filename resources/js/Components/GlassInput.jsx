import React from 'react';
import { TextField } from '@radix-ui/themes';

const GlassInput = ({ placeholder, value, onChange, onValueChange, type, ...rest }) => (
  <TextField.Root
    placeholder={placeholder}
    value={value}
    type={type}
    onChange={e => { onChange?.(e); onValueChange?.(e.target.value); }}
    {...rest}
  />
);

export default GlassInput;
