import React, { useEffect, useState } from 'react';
import {
    Button,
    Flex,
    Grid,
    Text,
    TextField,
    Select,
    IconButton,
    Spinner,
    Box,
    ScrollArea,
} from '@radix-ui/themes';
import {
    CameraIcon,
    EyeOpenIcon,
    EyeClosedIcon,
} from '@radix-ui/react-icons';
import GlassDialog from '@/Components/GlassDialog.jsx';
import ProfileAvatar from '@/Components/Profile/ProfileAvatar';
import { showToast } from '@/utils/toastUtils';
import axios from 'axios';
import DateTimePicker from '@/Components/DateTimePicker';

const toastStyle = {
    backdropFilter: 'blur(16px) saturate(200%)',
    background: 'rgba(255, 255, 255, 0.1)',
    border: '1px solid rgba(255, 255, 255, 0.2)',
};

const fieldError = (errors, key) => {
    const e = errors[key];
    if (!e) return null;
    return Array.isArray(e) ? e[0] : e;
};

const AddUserForm = ({ user, allUsers, departments, designations, setUser, open, closeModal }) => {
    const [showPassword, setShowPassword] = useState(false);
    const [initialUserData, setInitialUserData] = useState({
        id: user?.id,
        name: user?.name || '',
        user_name: user?.user_name || '',
        gender: user?.gender || '',
        birthday: user?.birthday || '',
        date_of_joining: user?.date_of_joining || '',
        address: user?.address || '',
        employee_id: user?.employee_id || '',
        phone: user?.phone || '',
        email: user?.email || '',
        department: user?.department || '',
        designation: user?.designation || '',
        report_to: user?.report_to || '',
        password: '',
        confirmPassword: ''
    });

    const [changedUserData, setChangedUserData] = useState({
        id: user?.id,
    });

    const [dataChanged, setDataChanged] = useState(false);
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [hover, setHover] = useState(false);
    const [selectedImage, setSelectedImage] = useState(null);
    const [selectedImageFile, setSelectedImageFile] = useState(null);
    const [allDesignations, setAllDesignations] = useState(designations);
    const [allReportTo, setAllReportTo] = useState(allUsers);

    const val = (key) => changedUserData[key] ?? initialUserData[key] ?? '';

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);

        const formData = new FormData();

        Object.keys(initialUserData).forEach(key => {
            formData.append(key, initialUserData[key]);
        });

        if (selectedImageFile) {
            const fileType = selectedImageFile.type;
            if (['image/jpeg', 'image/jpg', 'image/png'].includes(fileType)) {
                formData.append('profile_image', selectedImageFile);
            } else {
                showToast.error('Invalid file type. Only JPEG and PNG are allowed.', {
                    icon: '🔴',
                    style: toastStyle,
                });
                setProcessing(false);
                return;
            }
        }

        try {
            const response = await axios.post(route('addUser'), formData);

            if (response.status === 200) {
                setUser(response.data.user);
                showToast.success(
                    response.data.messages?.length > 0
                        ? response.data.messages.join(' ')
                        : 'Profile information updated successfully',
                    { icon: '🟢', style: toastStyle }
                );
                closeModal();
            }
        } catch (error) {
            setProcessing(false);
            handleErrorResponse(error);
        } finally {
            setProcessing(false);
        }
    };

    const handleErrorResponse = (error) => {
        if (error.response) {
            if (error.response.status === 422) {
                setErrors(error.response.data.errors || {});
                showToast.error(error.response.data.error || 'Failed to update profile information.', {
                    icon: '🔴',
                    style: toastStyle,
                });
            } else {
                showToast.error('An unexpected error occurred. Please try again later.', {
                    icon: '🔴',
                    style: toastStyle,
                });
            }
        } else if (error.request) {
            showToast.error('No response received from the server. Please check your internet connection.', {
                icon: '🔴',
                style: toastStyle,
            });
        } else {
            showToast.error('An error occurred while setting up the request.', {
                icon: '🔴',
                style: toastStyle,
            });
        }
    };

    const handleImageChange = (event) => {
        const file = event.target.files[0];
        if (file) {
            const objectURL = URL.createObjectURL(file);
            setSelectedImage(objectURL);
            setSelectedImageFile(file);
        }
    };

    const handleChange = (key, value) => {
        setInitialUserData((prevUser) => {
            const updatedData = { ...prevUser, [key]: value };
            if (value === '') {
                delete updatedData[key];
            }
            return updatedData;
        });

        setChangedUserData((prevUser) => {
            const updatedData = { ...prevUser, [key]: value };
            if (value === '') {
                delete updatedData[key];
            }
            return updatedData;
        });
    };

    useEffect(() => {
        if (user?.department !== initialUserData.department) {
            initialUserData.designation = null;
            initialUserData.report_to = null;
        }

        setAllDesignations(
            designations.filter((designation) =>
                designation.department_id === (changedUserData.department || initialUserData.department)
            )
        );

        setAllReportTo(
            allUsers.filter((u) =>
                u.department === (changedUserData.department || initialUserData.department)
            )
        );

        const updatedChangedUserData = { ...changedUserData };
        for (const key in updatedChangedUserData) {
            if (user && key !== 'id' && updatedChangedUserData[key] === user[key]) {
                delete updatedChangedUserData[key];
            }
        }

        const hasChanges = Object.keys(updatedChangedUserData).length > 1;
        setDataChanged(hasChanges);
    }, [initialUserData, changedUserData]);

    return (
        <GlassDialog isOpen={open} onClose={closeModal} title="Profile Information">
            <form onSubmit={handleSubmit}>
                <ScrollArea type="auto" style={{ maxHeight: 'calc(90vh - 180px)' }}>
                    <Box p="4" pt="2">
                        <Grid columns={{ initial: '1', md: '2' }} gap="3">
                            <Box style={{ gridColumn: '1 / -1' }}>
                                <Flex justify="center" align="center">
                                    <Box
                                        position="relative"
                                        onMouseEnter={() => setHover(true)}
                                        onMouseLeave={() => setHover(false)}
                                    >
                                        <ProfileAvatar
                                            name={val('name')}
                                            src={selectedImage || user?.profile_image_url || user?.profile_image}
                                            size="lg"
                                            className="w-[100px] h-[100px] text-xl"
                                            showBorder
                                        />
                                        {hover && (
                                            <Flex
                                                align="center"
                                                justify="center"
                                                position="absolute"
                                                top="0"
                                                left="0"
                                                width="100%"
                                                height="100%"
                                                style={{
                                                    backgroundColor: 'rgba(0, 0, 0, 0.5)',
                                                    borderRadius: '50%',
                                                }}
                                            >
                                                <CameraIcon width="24" height="24" color="white" />
                                            </Flex>
                                        )}
                                        <input
                                            accept="image/*"
                                            style={{ display: 'none' }}
                                            id="upload-button"
                                            type="file"
                                            onChange={handleImageChange}
                                        />
                                        <label
                                            htmlFor="upload-button"
                                            style={{
                                                position: 'absolute',
                                                width: '100%',
                                                height: '100%',
                                                top: 0,
                                                left: 0,
                                                borderRadius: '50%',
                                                cursor: 'pointer',
                                            }}
                                        />
                                    </Box>
                                </Flex>
                            </Box>

                            <Field label="Name" error={fieldError(errors, 'name')}>
                                <TextField.Root
                                    value={val('name')}
                                    onChange={(e) => handleChange('name', e.target.value)}
                                />
                            </Field>

                            <Field label="Username" error={fieldError(errors, 'user_name')}>
                                <TextField.Root
                                    value={val('user_name')}
                                    onChange={(e) => handleChange('user_name', e.target.value)}
                                />
                            </Field>

                            <Field label="Email Address" error={fieldError(errors, 'email')}>
                                <TextField.Root
                                    type="email"
                                    value={val('email')}
                                    onChange={(e) => handleChange('email', e.target.value)}
                                />
                            </Field>

                            <Field label="Phone Number" error={fieldError(errors, 'phone')}>
                                <TextField.Root
                                    type="tel"
                                    value={val('phone')}
                                    onChange={(e) => handleChange('phone', e.target.value)}
                                />
                            </Field>

                            <Field label="Password" error={fieldError(errors, 'password')}>
                                <TextField.Root
                                    type={showPassword ? 'text' : 'password'}
                                    value={val('password')}
                                    onChange={(e) => handleChange('password', e.target.value)}
                                >
                                    <TextField.Slot side="right">
                                        <IconButton
                                            type="button"
                                            size="1"
                                            variant="ghost"
                                            onClick={() => setShowPassword(!showPassword)}
                                        >
                                            {showPassword ? <EyeClosedIcon /> : <EyeOpenIcon />}
                                        </IconButton>
                                    </TextField.Slot>
                                </TextField.Root>
                            </Field>

                            <Field label="Confirm Password" error={fieldError(errors, 'confirmPassword')}>
                                <TextField.Root
                                    type="password"
                                    value={val('confirmPassword')}
                                    onChange={(e) => handleChange('confirmPassword', e.target.value)}
                                />
                            </Field>

                            <Field label="Gender" error={fieldError(errors, 'gender')}>
                                <Select.Root
                                    value={val('gender') || undefined}
                                    onValueChange={(v) => handleChange('gender', v)}
                                >
                                    <Select.Trigger placeholder="Select Gender" style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="Male">Male</Select.Item>
                                        <Select.Item value="Female">Female</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Birth Date" error={fieldError(errors, 'birthday')}>
                                <DateTimePicker
                                    mode="date"
                                    value={val('birthday')}
                                    onChange={(v) => handleChange('birthday', v)}
                                />
                            </Field>

                            <Field label="Joining Date" error={fieldError(errors, 'date_of_joining')}>
                                <DateTimePicker
                                    mode="date"
                                    value={val('date_of_joining')}
                                    onChange={(v) => handleChange('date_of_joining', v)}
                                />
                            </Field>

                            <Box style={{ gridColumn: '1 / -1' }}>
                                <Field label="Address" error={fieldError(errors, 'address')}>
                                    <TextField.Root
                                        value={val('address')}
                                        onChange={(e) => handleChange('address', e.target.value)}
                                    />
                                </Field>
                            </Box>

                            <Field label="Employee ID" error={fieldError(errors, 'employee_id')}>
                                <TextField.Root
                                    value={val('employee_id')}
                                    onChange={(e) => handleChange('employee_id', e.target.value)}
                                />
                            </Field>

                            <Field label="Department" error={fieldError(errors, 'department')}>
                                <Select.Root
                                    value={val('department') ? String(val('department')) : undefined}
                                    onValueChange={(v) => handleChange('department', v)}
                                >
                                    <Select.Trigger placeholder="Select Department" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {departments.map((dept) => (
                                            <Select.Item key={dept.id} value={String(dept.id)}>
                                                {dept.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Designation" error={fieldError(errors, 'designation')}>
                                <Select.Root
                                    value={val('designation') ? String(val('designation')) : undefined}
                                    onValueChange={(v) => handleChange('designation', v)}
                                    disabled={!user?.department && !val('department')}
                                >
                                    <Select.Trigger placeholder="Select Designation" style={{ width: '100%' }} />
                                    <Select.Content>
                                        {allDesignations.map((desig) => (
                                            <Select.Item key={desig.id} value={String(desig.id)}>
                                                {desig.title}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Reports To" error={fieldError(errors, 'report_to')}>
                                <Select.Root
                                    value={val('report_to') ? String(val('report_to')) : undefined}
                                    onValueChange={(v) => handleChange('report_to', v)}
                                    disabled={user?.report_to === 'na'}
                                >
                                    <Select.Trigger placeholder="Select Reports To" style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="na">--</Select.Item>
                                        {allReportTo.map((pers) => (
                                            <Select.Item key={pers.id} value={String(pers.id)}>
                                                {pers.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>
                        </Grid>
                    </Box>
                </ScrollArea>

                <Flex justify="center" p="4" gap="3" style={{ borderTop: '1px solid var(--gray-6)' }}>
                    <Button
                        type="submit"
                        disabled={!dataChanged || processing}
                        variant="soft"
                    >
                        {processing && <Spinner size="1" />}
                        Submit
                    </Button>
                </Flex>
            </form>
        </GlassDialog>
    );
};

const Field = ({ label, error, children }) => (
    <Box>
        <Text as="label" size="2" weight="medium" mb="1" display="block">{label}</Text>
        {children}
        {error && <Text size="1" color="red" mt="1">{error}</Text>}
    </Box>
);

export default AddUserForm;
