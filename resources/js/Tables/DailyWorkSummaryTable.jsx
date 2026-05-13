import React from 'react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Table as RadixTable,
    Badge,
    Card,
    Separator,
    ScrollArea,
    Progress,
    Button,
    Flex,
    Box,
    Text,
} from '@radix-ui/themes';
import {
    CalendarIcon,
    ActivityLogIcon,
    FileTextIcon,
    CheckCircledIcon,
    CountdownTimerIcon,
    ReloadIcon,
    HomeIcon,
    FileIcon,
    TargetIcon,
    ExclamationTriangleIcon,
} from '@radix-ui/react-icons';


const DailyWorkSummaryTable = ({ filteredData, onRefresh, loading = false }) => {
    const isMobile = useMediaQuery('(max-width: 1024px)');

    const columns = [
        { name: 'Date',             uid: 'date',                   icon: CalendarIcon },
        { name: 'Total Works',      uid: 'totalDailyWorks',        icon: FileTextIcon },
        { name: 'Resubmissions',    uid: 'resubmissions',          icon: ReloadIcon },
        { name: 'Embankment',       uid: 'embankment',             icon: HomeIcon },
        { name: 'Structure',        uid: 'structure',              icon: FileIcon },
        { name: 'Pavement',         uid: 'pavement',               icon: TargetIcon },
        { name: 'Completed',        uid: 'completed',              icon: CheckCircledIcon },
        { name: 'Pending',          uid: 'pending',                icon: CountdownTimerIcon },
        { name: 'Completion %',     uid: 'completionPercentage',   icon: ActivityLogIcon },
        { name: 'RFI Submissions',  uid: 'rfiSubmissions',         icon: FileIcon },
        { name: 'RFI Submission %', uid: 'rfiSubmissionPercentage', icon: ActivityLogIcon },
    ];

    const DesktopLoadingSkeleton = () => (
        <Box style={{ maxHeight: '84vh', overflowY: 'auto' }}>
            <Flex align="center" justify="between" mb="4" px="2">
                <Box style={{ width: 128, height: 24, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)' }} />
                <Box style={{ width: 80,  height: 32, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)' }} />
            </Flex>
            <ScrollArea style={{ maxHeight: '70vh' }}>
                <Box style={{ border: '1px solid var(--gray-4)', borderRadius: 'var(--radius-2)', overflow: 'hidden' }}>
                    <Box style={{ background: 'var(--gray-2)', borderBottom: '1px solid var(--gray-4)' }}>
                        <Flex>
                            {columns.map((col) => (
                                <Box key={col.uid} style={{ flex: 1, padding: 12 }}>
                                    <Box style={{ width: 80, height: 16, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)' }} />
                                </Box>
                            ))}
                        </Flex>
                    </Box>
                    {Array.from({ length: 8 }).map((_, i) => (
                        <Flex key={i} style={{ borderBottom: '1px solid var(--gray-3)' }}>
                            {columns.map((_, j) => (
                                <Box key={j} style={{ flex: 1, padding: 12 }}>
                                    <Box style={{ width: '100%', height: 16, borderRadius: 'var(--radius-1)', background: 'var(--gray-a3)' }} />
                                </Box>
                            ))}
                        </Flex>
                    ))}
                </Box>
            </ScrollArea>
        </Box>
    );

    const formatDate = (dateString) => {
        return new Date(dateString).toLocaleDateString("en-US", {
            day: "numeric",
            month: "short",
            year: "numeric"
        });
    };

    const getPercentageColor = (pct) => {
        if (pct >= 100) return 'green';
        if (pct >= 75)  return 'amber';
        if (pct >= 50)  return 'blue';
        return 'red';
    };

    const getPercentageIcon = (pct) => {
        if (pct >= 100) return <CheckCircledIcon style={{ width: 11, height: 11 }} />;
        if (pct >= 50)  return <CountdownTimerIcon style={{ width: 11, height: 11 }} />;
        return <ExclamationTriangleIcon style={{ width: 11, height: 11 }} />;
    };

    const getWorkTypeIcon = (type, count) => {
        const dim = { width: 12, height: 12 };
        const faded = { ...dim, color: 'var(--gray-8)' };
        switch (type?.toLowerCase()) {
            case 'embankment': return <HomeIcon    style={count > 0 ? { ...dim, color: 'var(--amber-9)' } : faded} />;
            case 'structure':  return <FileIcon    style={count > 0 ? { ...dim, color: 'var(--blue-9)'  } : faded} />;
            case 'pavement':   return <TargetIcon  style={count > 0 ? { ...dim, color: 'var(--gray-9)'  } : faded} />;
            default:           return <FileTextIcon style={count > 0 ? { ...dim, color: 'var(--accent-9)'} : faded} />;
        }
    };

    const MobileSummaryCard = ({ summary }) => {
        const completionPct = summary.totalDailyWorks > 0
            ? (summary.completed / summary.totalDailyWorks * 100).toFixed(1) : 0;
        const rfiPct = summary.rfiSubmissions > 0 && summary.completed > 0
            ? (summary.rfiSubmissions / summary.completed * 100).toFixed(1) : 0;
        const pending = summary.totalDailyWorks - summary.completed;

        return (
            <Card mb="2">
                <Box p="3">
                    <Flex align="start" justify="between" mb="3">
                        <Box>
                            <Text size="2" weight="bold" as="p" style={{ color: 'var(--accent-9)' }}>{formatDate(summary.date)}</Text>
                            <Text size="1" color="gray" as="p">{summary.totalDailyWorks} total works</Text>
                        </Box>
                        <Badge color={getPercentageColor(parseFloat(completionPct))} variant="soft" size="1">
                            <Flex align="center" gap="1">
                                {getPercentageIcon(parseFloat(completionPct))}
                                {completionPct}%
                            </Flex>
                        </Badge>
                    </Flex>

                    <Separator size="4" my="2" />

                    <Flex direction="column" gap="2" mb="3">
                        <Box>
                            <Flex justify="between" mb="1">
                                <Text size="1">Completion Progress</Text>
                                <Text size="1" weight="medium">{summary.completed}/{summary.totalDailyWorks}</Text>
                            </Flex>
                            <Progress value={parseFloat(completionPct)} color={getPercentageColor(parseFloat(completionPct))} size="1" />
                        </Box>
                        {summary.rfiSubmissions > 0 && (
                            <Box>
                                <Flex justify="between" mb="1">
                                    <Text size="1" color="gray">RFI Submission</Text>
                                    <Text size="1" weight="medium">{summary.rfiSubmissions}/{summary.completed}</Text>
                                </Flex>
                                <Progress value={parseFloat(rfiPct)} color={getPercentageColor(parseFloat(rfiPct))} size="1" />
                            </Box>
                        )}
                    </Flex>

                    <Flex gap="2">
                        {[['embankment', 'Embankment'], ['structure', 'Structure'], ['pavement', 'Pavement']].map(([key, label]) => (
                            <Flex key={key} direction="column" align="center" p="2" style={{ flex: 1, background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)' }}>
                                {getWorkTypeIcon(key, summary[key])}
                                <Text size="1" color="gray" as="p" mt="1">{label}</Text>
                                <Text size="1" weight="medium" as="p">{summary[key]}</Text>
                            </Flex>
                        ))}
                    </Flex>

                    <Separator size="4" my="2" />
                    <Flex justify="between">
                        <Flex align="center" gap="1">
                            <ReloadIcon style={{ width: 12, height: 12, color: 'var(--amber-9)' }} />
                            <Text size="1">Resubmissions: {summary.resubmissions}</Text>
                        </Flex>
                        <Flex align="center" gap="1">
                            <CountdownTimerIcon style={{ width: 12, height: 12, color: 'var(--red-9)' }} />
                            <Text size="1">Pending: {pending}</Text>
                        </Flex>
                    </Flex>
                </Box>
            </Card>
        );
    };

    const renderCell = React.useCallback((summary, columnKey) => {
        const completionPct = summary.totalDailyWorks > 0
            ? (summary.completed / summary.totalDailyWorks * 100).toFixed(1) : 0;
        const rfiPct = summary.rfiSubmissions > 0 && summary.completed > 0
            ? (summary.rfiSubmissions / summary.completed * 100).toFixed(1) : 0;
        const pending = summary.totalDailyWorks - summary.completed;

        switch (columnKey) {
            case 'date':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            <CalendarIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                            <Text size="1" weight="medium">{formatDate(summary.date)}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'totalDailyWorks':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            <FileTextIcon style={{ width: 12, height: 12, color: 'var(--accent-9)' }} />
                            <Text size="1" weight="bold">{summary.totalDailyWorks}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'resubmissions':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            <ReloadIcon style={{ width: 12, height: 12, color: 'var(--amber-9)' }} />
                            <Text size="1">{summary.resubmissions}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'embankment':
            case 'structure':
            case 'pavement':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            {getWorkTypeIcon(columnKey, summary[columnKey])}
                            <Text size="1">{summary[columnKey]}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'completed':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            <CheckCircledIcon style={{ width: 12, height: 12, color: 'var(--green-9)' }} />
                            <Text size="1" weight="medium" style={{ color: 'var(--green-9)' }}>{summary.completed}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'pending':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            <CountdownTimerIcon style={{ width: 12, height: 12, color: 'var(--red-9)' }} />
                            <Text size="1" weight="medium" style={{ color: 'var(--red-9)' }}>{pending}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'completionPercentage':
                return (
                    <RadixTable.Cell style={{ minWidth: 180 }}>
                        <Flex align="center" gap="2">
                            <Box style={{ flex: 1, minWidth: 80 }}>
                                <Progress value={parseFloat(completionPct)} color={getPercentageColor(parseFloat(completionPct))} size="1" />
                            </Box>
                            <Badge size="1" variant="soft" color={getPercentageColor(parseFloat(completionPct))}>
                                <Flex align="center" gap="1">
                                    {getPercentageIcon(parseFloat(completionPct))}
                                    {completionPct}%
                                </Flex>
                            </Badge>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'rfiSubmissions':
                return (
                    <RadixTable.Cell>
                        <Flex align="center" gap="1">
                            <FileIcon style={{ width: 12, height: 12, color: 'var(--blue-9)' }} />
                            <Text size="1">{summary.rfiSubmissions}</Text>
                        </Flex>
                    </RadixTable.Cell>
                );
            case 'rfiSubmissionPercentage':
                return (
                    <RadixTable.Cell style={{ minWidth: 180 }}>
                        {summary.rfiSubmissions > 0 ? (
                            <Flex align="center" gap="2">
                                <Box style={{ flex: 1, minWidth: 80 }}>
                                    <Progress value={parseFloat(rfiPct)} color={getPercentageColor(parseFloat(rfiPct))} size="1" />
                                </Box>
                                <Badge size="1" variant="soft" color={getPercentageColor(parseFloat(rfiPct))}>
                                    <Flex align="center" gap="1">
                                        {getPercentageIcon(parseFloat(rfiPct))}
                                        {rfiPct}%
                                    </Flex>
                                </Badge>
                            </Flex>
                        ) : (
                            <Text size="1" color="gray">-</Text>
                        )}
                    </RadixTable.Cell>
                );
            default:
                return <RadixTable.Cell><Text size="1">{summary[columnKey]}</Text></RadixTable.Cell>;
        }
    }, []);

    if (isMobile) {
        return (
            <Box>
                {onRefresh && (
                    <Flex justify="end" mb="4">
                        <Button size="1" variant="outline" onClick={onRefresh} aria-label="Refresh daily work summary table">
                            <ReloadIcon style={{ width: 14, height: 14 }} /> Refresh Summary
                        </Button>
                    </Flex>
                )}
                <ScrollArea style={{ maxHeight: '70vh' }}>
                    {filteredData?.length > 0 ? (
                        filteredData.map((summary, index) => (
                            <MobileSummaryCard key={index} summary={summary} />
                        ))
                    ) : (
                        <Card>
                            <Flex direction="column" align="center" p="8" gap="2">
                                <ActivityLogIcon style={{ width: 48, height: 48, color: 'var(--gray-8)', opacity: 0.5 }} />
                                <Text size="4" weight="medium" color="gray">No summary data found</Text>
                                <Text size="2" color="gray">No work summary available for the selected period</Text>
                            </Flex>
                        </Card>
                    )}
                </ScrollArea>
            </Box>
        );
    }

    if (loading) return <DesktopLoadingSkeleton />;

    return (
        <Box style={{ maxHeight: '84vh', overflowY: 'auto' }}>
            <ScrollArea style={{ maxHeight: '70vh' }}>
                <RadixTable.Root variant="surface" size="1">
                    <RadixTable.Header>
                        <RadixTable.Row>
                            {columns.map((column) => (
                                <RadixTable.ColumnHeaderCell
                                    key={column.uid}
                                    style={{
                                        minWidth: column.uid === 'date' ? 128
                                            : column.uid === 'completionPercentage' || column.uid === 'rfiSubmissionPercentage' ? 180
                                            : 'auto',
                                    }}
                                >
                                    <Flex align="center" gap="1" justify={column.uid === 'date' ? 'start' : 'center'}>
                                        {column.icon && <column.icon style={{ width: 12, height: 12 }} />}
                                        <Text size="1" weight="bold">{column.name}</Text>
                                    </Flex>
                                </RadixTable.ColumnHeaderCell>
                            ))}
                        </RadixTable.Row>
                    </RadixTable.Header>
                    <RadixTable.Body>
                        {filteredData?.length > 0 ? (
                            filteredData.map((summary) => (
                                <RadixTable.Row key={summary.date}>
                                    {columns.map((col) => (
                                        <React.Fragment key={col.uid}>
                                            {renderCell(summary, col.uid)}
                                        </React.Fragment>
                                    ))}
                                </RadixTable.Row>
                            ))
                        ) : (
                            <RadixTable.Row>
                                <RadixTable.Cell colSpan={columns.length}>
                                    <Flex direction="column" align="center" justify="center" py="8" gap="2">
                                        <ActivityLogIcon style={{ width: 48, height: 48, color: 'var(--gray-8)', opacity: 0.5 }} />
                                        <Text size="3" weight="medium" color="gray">No summary data found</Text>
                                        <Text size="2" color="gray">No work summary available for the selected period</Text>
                                    </Flex>
                                </RadixTable.Cell>
                            </RadixTable.Row>
                        )}
                    </RadixTable.Body>
                </RadixTable.Root>
            </ScrollArea>
        </Box>
    );
};

export default DailyWorkSummaryTable;
