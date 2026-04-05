import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function RequirementsShow({ requirement }) {
    return (
        <App>
            <Head title="Requirement Details" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">{requirement.name}</h1>
                        <p className="text-sm text-gray-600">Compliance requirement details</p>
                    </div>
                    <div className="flex gap-2">
                        <Link
                            href={route('compliance.requirements.edit', requirement.id)}
                            className="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600"
                        >
                            Edit
                        </Link>
                        <Link
                            href={route('compliance.requirements.index')}
                            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Back
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2 sm:p-6">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Status</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.status.replaceAll('_', ' ')}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Applicable</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.applicable ? 'Yes' : 'No'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Source</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.source}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Reference Number</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.reference_number || '-'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Responsible Person</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.responsible_person?.name || 'Unassigned'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Last Evaluation</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.last_evaluation_date || '-'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Next Evaluation</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{requirement.next_evaluation_date || '-'}</p>
                    </div>
                </div>

                <div className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Description</p>
                        <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{requirement.description}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Compliance Evidence</p>
                        <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{requirement.compliance_evidence || '-'}</p>
                    </div>
                </div>
            </div>
        </App>
    );
}
