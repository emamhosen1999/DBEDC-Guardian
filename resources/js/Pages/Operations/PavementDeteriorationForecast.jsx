import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Callout } from '@radix-ui/themes';
import { InformationCircleIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';

/**
 * Pavement deterioration forecast. The forecast is fitted to the corridor's own condition history; until surveys and
 * IRI readings exist the page says so and shows what has been recorded (owner rule: no illustrative figures).
 */
export default function PavementDeteriorationForecast({ auth, forecast }) {
    const surveys = forecast?.condition_surveys ?? 0;
    const iri = forecast?.iri_readings ?? 0;

    return (
        <App auth={auth}>
            <Head title="Pavement Deterioration Forecast - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                <Box mb="5">
                    <Heading size="6" weight="bold">Pavement Deterioration Forecast</Heading>
                    <Text size="2" color="gray">Condition transitions and the best intervention window, fitted to the corridor's own survey history.</Text>
                </Box>

                <Panel>
                    <Callout.Root color="gray" mb="4">
                        <Callout.Icon><InformationCircleIcon style={{ width: 18, height: 18 }} /></Callout.Icon>
                        <Callout.Text>{forecast?.reason ?? 'No forecast is available yet.'}</Callout.Text>
                    </Callout.Root>

                    <Flex gap="6" wrap="wrap" mb="4">
                        <Box>
                            <Text as="p" size="1" color="gray">Condition surveys recorded</Text>
                            <Text as="p" size="6" weight="bold">{surveys}</Text>
                        </Box>
                        <Box>
                            <Text as="p" size="1" color="gray">IRI roughness readings recorded</Text>
                            <Text as="p" size="6" weight="bold">{iri}</Text>
                        </Box>
                    </Flex>

                    <Flex gap="3" wrap="wrap">
                        <Button asChild variant="soft"><Link href={route('om.iri')}>IRI roughness readings</Link></Button>
                        <Button asChild variant="soft"><Link href={route('om.assets')}>Asset register and condition</Link></Button>
                    </Flex>
                </Panel>
            </Box>
        </App>
    );
}
