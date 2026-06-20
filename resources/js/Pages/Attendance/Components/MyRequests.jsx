import React from 'react';
import { Flex, Box, Text, Badge, Separator, Skeleton } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { requestJson } from '@/api/client';

const STATUS_COLOR = {
    pending:   'orange',
    approved:  'green',
    rejected:  'red',
    cancelled: 'gray',
};

function statusColor(status) {
    return STATUS_COLOR[status] ?? 'gray';
}

function RegularizationList({ requests }) {
    if (!requests?.length) {
        return <Text size="2" color="gray">No regularization requests yet.</Text>;
    }
    return (
        <Flex direction="column" gap="2">
            {requests.map(r => (
                <Flex key={r.id} justify="between" align="center" gap="2" wrap="wrap">
                    <Box>
                        <Text size="2" weight="medium">{dayjs(r.date).format('DD MMM YYYY')}</Text>
                        <Text size="1" color="gray" as="div">{r.type?.replace(/_/g, ' ')}</Text>
                    </Box>
                    <Badge color={statusColor(r.status)} variant="soft" size="1">
                        {r.status}
                    </Badge>
                </Flex>
            ))}
        </Flex>
    );
}

function OvertimeList({ requests }) {
    if (!requests?.length) {
        return <Text size="2" color="gray">No overtime requests yet.</Text>;
    }
    return (
        <Flex direction="column" gap="2">
            {requests.map(r => (
                <Flex key={r.id} justify="between" align="center" gap="2" wrap="wrap">
                    <Box>
                        <Text size="2" weight="medium">{dayjs(r.date).format('DD MMM YYYY')}</Text>
                        <Text size="1" color="gray" as="div">{r.requested_minutes} min</Text>
                    </Box>
                    <Badge color={statusColor(r.status)} variant="soft" size="1">
                        {r.status}
                    </Badge>
                </Flex>
            ))}
        </Flex>
    );
}

export default function MyRequests() {
    const regQ = useQuery({
        queryKey: ['my-regularizations'],
        queryFn: () => requestJson('get', '/attendance/regularizations/mine'),
    });

    const otQ = useQuery({
        queryKey: ['my-overtime'],
        queryFn: () => requestJson('get', '/attendance/overtime/mine'),
    });

    const coQ = useQuery({
        queryKey: ['my-comp-off'],
        queryFn: () => requestJson('get', '/attendance/comp-off/mine'),
    });

    const balanceMinutes = coQ.data?.balance_minutes ?? 0;
    const balanceHours   = Math.floor(balanceMinutes / 60);
    const balanceMins    = balanceMinutes % 60;

    return (
        <Flex direction="column" gap="4">

            {/* Comp-off balance */}
            <Flex align="center" gap="3">
                <Text size="2" weight="bold">Comp-Off Balance:</Text>
                {coQ.isLoading ? (
                    <Skeleton width="80px" height="20px" />
                ) : (
                    <Badge color="teal" variant="soft" size="2">
                        {balanceHours}h {balanceMins}m ({balanceMinutes} min)
                    </Badge>
                )}
            </Flex>

            <Separator size="4" />

            {/* Regularization requests */}
            <Box>
                <Text size="3" weight="bold" as="div" mb="2">Regularization Requests</Text>
                {regQ.isLoading ? (
                    <Flex direction="column" gap="2">
                        <Skeleton width="100%" height="28px" />
                        <Skeleton width="100%" height="28px" />
                    </Flex>
                ) : (
                    <RegularizationList requests={regQ.data?.requests} />
                )}
            </Box>

            <Separator size="4" />

            {/* Overtime requests */}
            <Box>
                <Text size="3" weight="bold" as="div" mb="2">Overtime Requests</Text>
                {otQ.isLoading ? (
                    <Flex direction="column" gap="2">
                        <Skeleton width="100%" height="28px" />
                        <Skeleton width="100%" height="28px" />
                    </Flex>
                ) : (
                    <OvertimeList requests={otQ.data?.requests} />
                )}
            </Box>
        </Flex>
    );
}
