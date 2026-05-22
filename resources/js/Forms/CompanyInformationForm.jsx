import React, { useEffect, useState } from 'react';
import {
    Box,
    Button,
    Card,
    Flex,
    Grid,
    Heading,
    Select,
    Separator,
    Text,
    TextField,
} from '@radix-ui/themes';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import { getCountries } from '@/Props/countries.jsx';

const fieldKeys = [
    'companyName', 'contactPerson', 'address', 'country', 'city', 'state',
    'postalCode', 'email', 'phoneNumber', 'mobileNumber', 'fax', 'websiteUrl',
];

const CompanyInformationForm = ({ settings, setSettings }) => {
    const countries = getCountries();
    const [selectedCountry, setSelectedCountry] = useState(settings?.country ?? '');
    const [states, setStates] = useState([]);
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    const [formData, setFormData] = useState(() =>
        fieldKeys.reduce((acc, key) => {
            acc[key] = settings?.[key] ?? '';
            return acc;
        }, {})
    );

    const handleChange = (key, value) => {
        setFormData((prev) => ({ ...prev, [key]: value }));
        if (key === 'country') setSelectedCountry(value);
    };

    useEffect(() => {
        const country = countries.find((c) => c.name === selectedCountry);
        setStates(country?.states ?? []);
    }, [selectedCountry, countries]);

    const err = (key) => errors[key]?.[0] ?? errors[key];

    const handleSubmit = async (event) => {
        event.preventDefault();
        setLoading(true);
        setErrors({});
        try {
            const response = await axios.put(route('update-company-settings'), formData);
            if (response.status === 200) {
                setSettings(response.data.companySettings);
                showToast.success(
                    response.data.message
                    || 'Company settings updated successfully'
                );
            }
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors || {});
                showToast.error(error.response.data.error || 'Validation failed.');
            } else if (error.request) {
                showToast.error('No response from server. Check your connection.');
            } else {
                showToast.error('An error occurred while saving.');
            }
        } finally {
            setLoading(false);
        }
    };

    const textField = (label, key, type = 'text') => (
        <Box>
            <Text as="label" size="2" weight="medium" mb="1" style={{ display: 'block' }}>
                {label}
            </Text>
            <TextField.Root
                type={type}
                value={formData[key]}
                onChange={(e) => handleChange(key, e.target.value)}
                color={err(key) ? 'red' : undefined}
            />
            {err(key) && (
                <Text size="1" color="red" mt="1">{err(key)}</Text>
            )}
        </Box>
    );

    return (
        <Card size="3" style={{ width: '100%', maxWidth: 960 }}>
            <form onSubmit={handleSubmit}>
                <Box p="4">
                    <Heading size="4" mb="4">Company information</Heading>

                    <Heading size="3" mb="3" color="gray">Company details</Heading>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="4" mb="4">
                        {textField('Company name', 'companyName')}
                        {textField('Contact person', 'contactPerson')}
                        <Box style={{ gridColumn: '1 / -1' }}>
                            {textField('Address', 'address')}
                        </Box>
                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" style={{ display: 'block' }}>
                                Country
                            </Text>
                            <Select.Root
                                value={formData.country || undefined}
                                onValueChange={(v) => handleChange('country', v)}
                            >
                                <Select.Trigger placeholder="Select country" style={{ width: '100%' }} />
                                <Select.Content>
                                    {countries.map((option) => (
                                        <Select.Item key={option.name} value={option.name}>
                                            {option.name}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {err('country') && <Text size="1" color="red" mt="1">{err('country')}</Text>}
                        </Box>
                        <Box>
                            <Text as="label" size="2" weight="medium" mb="1" style={{ display: 'block' }}>
                                State / province
                            </Text>
                            <Select.Root
                                value={formData.state || undefined}
                                onValueChange={(v) => handleChange('state', v)}
                                disabled={states.length === 0}
                            >
                                <Select.Trigger placeholder="Select state" style={{ width: '100%' }} />
                                <Select.Content>
                                    {states.map((state) => (
                                        <Select.Item key={state.name} value={state.name}>
                                            {state.name}
                                        </Select.Item>
                                    ))}
                                </Select.Content>
                            </Select.Root>
                            {err('state') && <Text size="1" color="red" mt="1">{err('state')}</Text>}
                        </Box>
                        {textField('City', 'city')}
                        {textField('Postal code', 'postalCode')}
                    </Grid>

                    <Separator size="4" mb="4" />

                    <Heading size="3" mb="3" color="gray">Contact details</Heading>
                    <Grid columns={{ initial: '1', sm: '2' }} gap="4">
                        {textField('Email', 'email', 'email')}
                        {textField('Phone', 'phoneNumber', 'tel')}
                        {textField('Mobile', 'mobileNumber', 'tel')}
                        {textField('Fax', 'fax', 'tel')}
                        <Box style={{ gridColumn: '1 / -1' }}>
                            {textField('Website URL', 'websiteUrl', 'url')}
                        </Box>
                    </Grid>
                </Box>

                <Flex justify="center" p="4" pt="0">
                    <Button type="submit" size="3" loading={loading}>
                        Save
                    </Button>
                </Flex>
            </form>
        </Card>
    );
};

export default CompanyInformationForm;
