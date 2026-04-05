import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function SelfServiceBenefits({ enrolledBenefits = [], availableBenefits = [] }) {
    return (
        <App>
            <Head title="My Benefits" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">My Benefits</h1>
                        <p className="text-sm text-gray-600">View your current benefits and enrollment options.</p>
                    </div>
                    <Link href={route('hr.selfservice.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Back
                    </Link>
                </div>

                {enrolledBenefits.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-semibold text-gray-900">Currently Enrolled</h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {enrolledBenefits.map((benefit) => (
                                <div key={benefit.id} className="rounded-lg border border-green-200 bg-green-50 p-4">
                                    <p className="font-medium text-gray-900">{benefit.name}</p>
                                    <p className="text-xs text-gray-600">{benefit.type}</p>
                                    <p className="mt-2 text-xs text-gray-700">
                                        Coverage: {benefit.coverage}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {availableBenefits.length > 0 && (
                    <div className="space-y-3">
                        <h2 className="text-sm font-semibold text-gray-900">Available to Enroll</h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {availableBenefits.map((benefit) => (
                                <div key={benefit.id} className="rounded-lg border border-gray-200 bg-white p-4">
                                    <div className="flex justify-between items-start">
                                        <div>
                                            <p className="font-medium text-gray-900">{benefit.name}</p>
                                            <p className="text-xs text-gray-600">{benefit.type}</p>
                                            <p className="mt-2 text-xs text-gray-700">
                                                Company: {benefit.company_contribution_rate}% | You: {benefit.employee_contribution_rate}%
                                            </p>
                                        </div>
                                        <button className="rounded-md bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700">
                                            Enroll
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </App>
    );
}
