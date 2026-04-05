import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function SelfServicePayslips({ payslips = [] }) {
    return (
        <App>
            <Head title="My Payslips" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">My Payslips</h1>
                        <p className="text-sm text-gray-600">View and download your payslips.</p>
                    </div>
                    <Link href={route('hr.selfservice.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Back
                    </Link>
                </div>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Period</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Gross</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Deductions</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Net</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {payslips.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No payslips available.</td>
                                    </tr>
                                ) : (
                                    payslips.map((payslip) => (
                                        <tr key={payslip.id}>
                                            <td className="px-4 py-3 font-medium text-gray-900">{payslip.period}</td>
                                            <td className="px-4 py-3 text-gray-700">${payslip.gross}</td>
                                            <td className="px-4 py-3 text-gray-700">${payslip.deductions}</td>
                                            <td className="px-4 py-3 font-medium text-gray-900">${payslip.net}</td>
                                            <td className="px-4 py-3 text-right">
                                                <a href={`#`} className="text-blue-600 hover:text-blue-800">Download</a>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </App>
    );
}
