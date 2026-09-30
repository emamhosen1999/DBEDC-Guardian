import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import {
    Box,
    Flex,
    Text,
    Heading,
    Badge,
    Button,
    TextField,
    Dialog,
    Table,
    Card,
    Separator,
} from '@radix-ui/themes';
import {
    MagnifyingGlassIcon,
    ReloadIcon,
    FileTextIcon,
} from '@radix-ui/react-icons';
import axios from 'axios';

const PayrollPage = ({
    title = 'Monthly Payroll & Compensation',
    payrolls = { data: [] },
    stats = {},
    selectedMonth = '',
}) => {
    const { auth } = usePage().props;
    const canGenerate = auth?.permissions?.includes('hr.payroll.process') || auth?.roles?.includes('Super Administrator');

    const [month, setMonth] = useState(selectedMonth || new Date().toISOString().slice(0, 7));
    const [isGenerating, setIsGenerating] = useState(false);
    const [genResult, setGenResult] = useState('');
    const [searchTerm, setSearchTerm] = useState('');

    // Payslip detail modal
    const [isPayslipOpen, setIsPayslipOpen] = useState(false);
    const [payslipData, setPayslipData] = useState(null);
    const [isLoadingPayslip, setIsLoadingPayslip] = useState(false);

    const handleMonthChange = (newMonth) => {
        setMonth(newMonth);
        router.get('/hr/payroll', { month: newMonth }, { preserveState: true, only: ['payrolls', 'stats', 'selectedMonth'] });
    };

    const handleGenerate = async () => {
        if (!confirm(`Generate payroll for ${month}? This will compute salaries for all active employees.`)) return;
        setIsGenerating(true);
        setGenResult('');
        try {
            const res = await axios.post('/hr/payroll/generate', { month });
            setGenResult(res.data?.message || 'Payroll generated successfully.');
            router.reload({ only: ['payrolls', 'stats'] });
        } catch (e) {
            setGenResult(e.response?.data?.message || 'Failed to generate payroll.');
        } finally {
            setIsGenerating(false);
        }
    };

    const viewPayslip = async (payroll) => {
        // Build payslip from payroll data inline (payslip endpoint needs payslip id)
        setPayslipData({
            employee: payroll.employee,
            month: month,
            basic_salary: payroll.basic_salary,
            gross_salary: payroll.gross_salary,
            working_days: payroll.working_days,
            present_days: payroll.present_days,
            absent_days: payroll.absent_days,
            leave_days: payroll.leave_days,
            overtime_hours: payroll.overtime_hours,
            overtime_amount: payroll.overtime_amount,
            total_deductions: payroll.total_deductions,
            net_salary: payroll.net_salary,
            status: payroll.status,
            remarks: payroll.remarks,
        });
        setIsPayslipOpen(true);
    };

    const filteredPayrolls = (payrolls.data || []).filter((item) => {
        if (!searchTerm) return true;
        const s = searchTerm.toLowerCase();
        return (
            item.employee?.name?.toLowerCase().includes(s) ||
            item.user_id?.toLowerCase().includes(s) ||
            item.employee?.employee_id?.toLowerCase().includes(s)
        );
    });

    const monthLabel = month ? new Date(month + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) : 'N/A';

    return (
        <>
            <Head title={title} />

            <Flex justify="center" p={{ initial: '2', sm: '4' }}>
                <Box style={{ width: '100%', maxWidth: 1600 }}>
                    {/* Header */}
                    <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="3" mb="4">
                        <Box>
                            <Heading size="6" weight="bold">Monthly Payroll & Compensation</Heading>
                            <Text size="2" color="gray">
                                Process monthly salaries based on biometric attendance, overtime, and loan deductions. BLA s.108 compliant.
                            </Text>
                        </Box>
                        <Flex gap="3" align="center">
                            <TextField.Root
                                type="month"
                                value={month}
                                onChange={(e) => handleMonthChange(e.target.value)}
                                style={{ width: 180 }}
                            />
                            {canGenerate && (
                                <Button
                                    size="3"
                                    variant="solid"
                                    color="indigo"
                                    disabled={isGenerating}
                                    onClick={handleGenerate}
                                    style={{ cursor: 'pointer' }}
                                >
                                    <ReloadIcon /> {isGenerating ? 'Generating...' : 'Run Payroll'}
                                </Button>
                            )}
                        </Flex>
                    </Flex>

                    {genResult && (
                        <Box mb="3" p="3" style={{ background: genResult.includes('Failed') ? 'var(--red-3)' : 'var(--green-3)', borderRadius: 8, border: `1px solid ${genResult.includes('Failed') ? 'var(--red-6)' : 'var(--green-6)'}` }}>
                            <Text size="2">{genResult}</Text>
                        </Box>
                    )}

                    {/* KPI Stats */}
                    <Flex gap="3" wrap="wrap" mb="4">
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="gray" weight="medium">Employees Processed</Text>
                            <Heading size="6" mt="1">{stats.total_employees || 0}</Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="indigo" weight="medium">Total Gross (৳)</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--indigo-11)' }}>
                                {Number(stats.total_gross || 0).toLocaleString()}
                            </Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="red" weight="medium">Total Deductions (৳)</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--red-11)' }}>
                                {Number(stats.total_deductions || 0).toLocaleString()}
                            </Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="green" weight="medium">Total Net Pay (৳)</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--green-11)' }}>
                                {Number(stats.total_net || 0).toLocaleString()}
                            </Heading>
                        </Card>
                        <Card style={{ flex: '1 1 180px', minWidth: 160 }}>
                            <Text size="1" color="amber" weight="medium">Total OT (৳)</Text>
                            <Heading size="6" mt="1" style={{ color: 'var(--amber-11)' }}>
                                {Number(stats.total_overtime || 0).toLocaleString()}
                            </Heading>
                        </Card>
                    </Flex>

                    {/* Table */}
                    <Panel variant="surface" p="4">
                        <Flex gap="3" mb="4" align="center">
                            <Box style={{ flex: '1 1 300px' }}>
                                <TextField.Root
                                    placeholder="Search by employee name or ID..."
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                >
                                    <TextField.Slot>
                                        <MagnifyingGlassIcon height="16" width="16" />
                                    </TextField.Slot>
                                </TextField.Root>
                            </Box>
                            <Badge variant="soft" color="indigo" size="2">{monthLabel}</Badge>
                        </Flex>

                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Basic (৳)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Gross (৳)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Present</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Absent</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>OT Hours</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Deductions (৳)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Net Pay (৳)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">Actions</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>

                            <Table.Body>
                                {filteredPayrolls.length === 0 ? (
                                    <Table.Row>
                                        <Table.Cell colSpan={10} align="center">
                                            <Text color="gray" size="2">No payroll records for {monthLabel}. Click "Run Payroll" to process.</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ) : (
                                    filteredPayrolls.map((item) => (
                                        <Table.Row key={item.id}>
                                            <Table.Cell>
                                                <Box>
                                                    <Text size="2" weight="bold" style={{ display: 'block' }}>
                                                        {item.employee?.name || item.user_id}
                                                    </Text>
                                                    <Text size="1" color="gray">
                                                        {item.user_id} · {item.employee?.designation?.title || ''} · {item.employee?.department?.name || ''}
                                                    </Text>
                                                </Box>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{Number(item.basic_salary || 0).toLocaleString()}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" weight="bold">{Number(item.gross_salary || 0).toLocaleString()}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color="green" variant="soft" size="1">{item.present_days || 0}</Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color={item.absent_days > 0 ? 'red' : 'gray'} variant="soft" size="1">{item.absent_days || 0}</Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{item.overtime_hours || 0}h ({Number(item.overtime_amount || 0).toLocaleString()})</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" color="red">{Number(item.total_deductions || 0).toLocaleString()}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" weight="bold" color="green">{Number(item.net_salary || 0).toLocaleString()}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge
                                                    color={item.status === 'approved' ? 'green' : item.status === 'paid' ? 'cyan' : 'amber'}
                                                    variant="solid"
                                                    size="1"
                                                >
                                                    {item.status?.toUpperCase()}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell align="right">
                                                <Button
                                                    size="1"
                                                    variant="soft"
                                                    onClick={() => viewPayslip(item)}
                                                    style={{ cursor: 'pointer' }}
                                                >
                                                    <FileTextIcon /> Payslip
                                                </Button>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Panel>
                </Box>
            </Flex>

            {/* PAYSLIP DETAIL MODAL */}
            <Dialog.Root open={isPayslipOpen} onOpenChange={setIsPayslipOpen}>
                <Dialog.Content style={{ maxWidth: 600 }}>
                    <Dialog.Title>Monthly Payslip — {payslipData?.employee?.name || 'Employee'}</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Pay period: {monthLabel}
                    </Dialog.Description>

                    {payslipData && (
                        <>
                            <Box mb="4" p="3" style={{ background: 'var(--gray-2)', borderRadius: 8 }}>
                                <Flex justify="between" wrap="wrap" gap="2">
                                    <Box>
                                        <Text size="1" color="gray">Employee</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                            {payslipData.employee?.name}
                                        </Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Department</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                            {payslipData.employee?.department?.name || 'N/A'}
                                        </Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Designation</Text>
                                        <Text size="2" weight="bold" style={{ display: 'block' }}>
                                            {payslipData.employee?.designation?.title || 'N/A'}
                                        </Text>
                                    </Box>
                                </Flex>
                            </Box>

                            <Table.Root variant="surface" mb="3">
                                <Table.Body>
                                    <Table.Row>
                                        <Table.Cell><Text weight="bold">Basic Salary</Text></Table.Cell>
                                        <Table.Cell align="right">৳{Number(payslipData.basic_salary).toLocaleString()}</Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell><Text weight="bold">Gross Salary</Text></Table.Cell>
                                        <Table.Cell align="right">৳{Number(payslipData.gross_salary).toLocaleString()}</Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Present Days / Working Days</Table.Cell>
                                        <Table.Cell align="right">{payslipData.present_days} / {payslipData.working_days}</Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Absent Days</Table.Cell>
                                        <Table.Cell align="right">{payslipData.absent_days}</Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Leave Days</Table.Cell>
                                        <Table.Cell align="right">{payslipData.leave_days}</Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell>Overtime ({payslipData.overtime_hours}h × 2x basic rate)</Table.Cell>
                                        <Table.Cell align="right" style={{ color: 'var(--green-11)' }}>+৳{Number(payslipData.overtime_amount).toLocaleString()}</Table.Cell>
                                    </Table.Row>
                                    <Table.Row style={{ background: 'var(--red-2)' }}>
                                        <Table.Cell><Text weight="bold" color="red">Total Deductions</Text></Table.Cell>
                                        <Table.Cell align="right"><Text weight="bold" color="red">-৳{Number(payslipData.total_deductions).toLocaleString()}</Text></Table.Cell>
                                    </Table.Row>
                                </Table.Body>
                            </Table.Root>

                            <Box p="3" mb="3" style={{ background: 'var(--green-3)', borderRadius: 8, border: '1px solid var(--green-6)' }}>
                                <Flex justify="between" align="center">
                                    <Heading size="4">Net Payable</Heading>
                                    <Heading size="5" color="green">৳{Number(payslipData.net_salary).toLocaleString()}</Heading>
                                </Flex>
                            </Box>

                            <Flex justify="end" gap="3" mt="4">
                                <Button
                                    variant="surface"
                                    onClick={() => {
                                        const printWin = window.open('', '', 'width=800,height=600');
                                        printWin.document.write(`
                                            <html><head><title>Payslip - ${payslipData.employee?.name}</title>
                                            <style>
                                                body { font-family: Arial, sans-serif; padding: 40px; }
                                                h1 { text-align: center; font-size: 16px; }
                                                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                                                td { padding: 8px; border-bottom: 1px solid #eee; }
                                                .total { background: #f0fdf4; font-weight: bold; }
                                            </style></head><body>
                                            <h1>DBEDC - Monthly Payslip</h1>
                                            <p><b>Employee:</b> ${payslipData.employee?.name} | <b>Period:</b> ${monthLabel}</p>
                                            <table>
                                            <tr><td>Basic Salary</td><td align="right">৳${Number(payslipData.basic_salary).toLocaleString()}</td></tr>
                                            <tr><td>Gross Salary</td><td align="right">৳${Number(payslipData.gross_salary).toLocaleString()}</td></tr>
                                            <tr><td>Present / Working Days</td><td align="right">${payslipData.present_days} / ${payslipData.working_days}</td></tr>
                                            <tr><td>Overtime (${payslipData.overtime_hours}h)</td><td align="right">+৳${Number(payslipData.overtime_amount).toLocaleString()}</td></tr>
                                            <tr><td>Deductions</td><td align="right">-৳${Number(payslipData.total_deductions).toLocaleString()}</td></tr>
                                            <tr class="total"><td><b>Net Payable</b></td><td align="right"><b>৳${Number(payslipData.net_salary).toLocaleString()}</b></td></tr>
                                            </table>
                                            </body></html>
                                        `);
                                        printWin.document.close();
                                        printWin.print();
                                    }}
                                    style={{ cursor: 'pointer' }}
                                >
                                    Print Payslip
                                </Button>
                                <Button variant="soft" color="gray" onClick={() => setIsPayslipOpen(false)}>Close</Button>
                            </Flex>
                        </>
                    )}
                </Dialog.Content>
            </Dialog.Root>
        </>
    );
};

PayrollPage.layout = (page) => <App>{page}</App>;

export default PayrollPage;
