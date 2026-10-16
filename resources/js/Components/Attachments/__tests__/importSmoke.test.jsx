// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { auth: { user: { id: 1 }, permissions: [] } } }), Link: () => null, Head: () => null, useForm: () => ({ data: {}, setData: vi.fn(), errors: {}, setError: vi.fn(), clearErrors: vi.fn(), reset: vi.fn() }), router: {} }));

describe('every changed web module still compiles and exports its component', () => {
    it.each([
        ['@/Forms/OvertimeRequestForm', 'default'],
        ['@/Forms/ReplacementLeaveForm', 'default'],
        ['@/Pages/Attendance/Components/ApprovalsInbox', 'default'],
        ['@/Pages/Attendance/Components/OvertimeDecisionDialog', 'default'],
        ['@/Components/Attachments/LeaveAttachmentActions', 'default'],
        ['@/Components/Attachments/UnclaimedOvertime', 'default'],
        ['@/Components/Attachments/ScanDialog', 'default'],
        ['@/Components/Cyber/CornerEditor', 'default'],
        ['@/Components/Attachments/AttachmentViewer', 'default'],
        ['@/Components/Attachments/ReuploadDialog', 'default'],
        ['@/Forms/LeaveForm', 'default'],
        ['@/Components/LeaveUnified/LeaveSettingsPanel', 'default'],
        ['@/Tables/LeaveEmployeeTable', 'default'],
        ['@/Pages/AttendanceEmployee', 'default'],
        ['@/Pages/EmployeeDashboard', 'default'],
    ])('%s', async (path, name) => {
        const mod = await import(/* @vite-ignore */ path);
        expect(['function', 'object']).toContain(typeof mod[name]); // memo() components are objects
        expect(mod[name]).toBeTruthy();
    });
});
