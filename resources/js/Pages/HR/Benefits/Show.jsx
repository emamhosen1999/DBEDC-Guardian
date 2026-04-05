import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function BenefitsShow({ benefit, employees = [] }) {
    return (
        <App>
            <Head title="Benefit Details" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">{benefit.name}</h1>
                        <p className="text-sm text-gray-600">Type: {benefit.type}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('hr.benefits.edit', benefit.id)} className="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                            Edit
                        </Link>
                        <Link href={route('hr.benefits.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Back
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3 sm:p-6">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Company Contribution</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{benefit.company_contribution_rate}%</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Employee Contribution</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{benefit.employee_contribution_rate}%</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Effective Date</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{benefit.effective_date}</p>
                    </div>
                </div>

                {benefit.description && (
                    <div className="space-y-2 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <p className="text-xs uppercase tracking-wide text-gray-500">Description</p>
                        <p className="whitespace-pre-wrap text-sm text-gray-800">{benefit.description}</p>
                    </div>
                )}

                {benefit.eligibility_criteria && (
                    <div className="space-y-2 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <p className="text-xs uppercase tracking-wide text-gray-500">Eligibility Criteria</p>
                        <p className="whitespace-pre-wrap text-sm text-gray-800">{benefit.eligibility_criteria}</p>
                    </div>
                )}

                {benefit.coverage_details && (
                    <div className="space-y-2 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <p className="text-xs uppercase tracking-wide text-gray-500">Coverage Details</p>
                        <p className="whitespace-pre-wrap text-sm text-gray-800">{benefit.coverage_details}</p>
                    </div>
                )}
            </div>
        </App>
    );
}
