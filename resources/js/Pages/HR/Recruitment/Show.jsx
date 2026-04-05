import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function RecruitmentShow({ position, applications = [] }) {
    return (
        <App>
            <Head title="Position Details" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">{position.title}</h1>
                        <p className="text-sm text-gray-600">Department: {position.department}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('hr.recruitment.edit', position.id)} className="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                            Edit
                        </Link>
                        <Link href={route('hr.recruitment.applications', position.id)} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            View Applications
                        </Link>
                        <Link href={route('hr.recruitment.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Back
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2 sm:p-6">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Position</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{position.title}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Department</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{position.department}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Status</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{position.status}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Posted Date</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{position.posted_date}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Number of Openings</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{position.number_of_openings}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Total Applicants</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{applications.length || 0}</p>
                    </div>
                </div>

                {position.description && (
                    <div className="space-y-2 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <p className="text-xs uppercase tracking-wide text-gray-500">Job Description</p>
                        <p className="whitespace-pre-wrap text-sm text-gray-800">{position.description}</p>
                    </div>
                )}

                {position.requirements && (
                    <div className="space-y-2 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <p className="text-xs uppercase tracking-wide text-gray-500">Requirements</p>
                        <p className="whitespace-pre-wrap text-sm text-gray-800">{position.requirements}</p>
                    </div>
                )}
            </div>
        </App>
    );
}
