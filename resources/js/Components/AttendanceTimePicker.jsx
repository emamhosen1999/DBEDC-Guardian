import React, { useState } from 'react';
import { TextField, Button, Flex, Text } from '@radix-ui/themes';
import { CheckIcon, Cross1Icon } from '@radix-ui/react-icons';

const AttendanceTimePicker = ({ 
    value, 
    onSave, 
    onCancel, 
    label = "Time" 
}) => {
    const [timeValue, setTimeValue] = useState(value || '');

    const handleSave = () => {
        if (timeValue.trim()) {
            onSave(timeValue);
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            handleSave();
        } else if (e.key === 'Escape') {
            onCancel();
        }
    };

    return (
        <Flex gap="2" align="center">
            <TextField.Root 
                type="time" 
                value={timeValue}
                onChange={(e) => setTimeValue(e.target.value)}
                onKeyDown={handleKeyDown}
                size="1"
                style={{ width: '140px' }}
                autoFocus
            >
                <TextField.Slot side="right">
                    <Text size="1" color="gray">{label}</Text>
                </TextField.Slot>
            </TextField.Root>
            
            <Button size="1" color="green" onClick={handleSave}>
                <CheckIcon width="14" height="14" />
            </Button>
            
            <Button size="1" color="red" onClick={onCancel}>
                <Cross1Icon width="14" height="14" />
            </Button>
        </Flex>
    );
};

export default AttendanceTimePicker;
