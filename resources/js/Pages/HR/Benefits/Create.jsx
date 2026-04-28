import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function BenefitsCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        type: '',
        description: '',
        company_contribution_rate: '',
        employee_contribution_rate: '',
        eligibility_criteria: '',
        coverage_details: '',
        effective_date: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('hr.benefits.store'));
    };

    return (
        <App>
            <Head title="Create Benefit" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Add New Benefit</h1>
                    <p className="text-sm text-gray-600">Create a new benefit plan.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Benefit Name</label>
                            <input type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Type</label>
                            <select value={data.type} onChange={(e) => setData('type', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select type</option>
                                <option value="health">Health</option>
                                <option value="insurance">Insurance</option>
                                <option value="retirement">Retirement</option>
                                <option value="wellness">Wellness</option>
                                <option value="other">Other</option>
                            </select>
                            {errors.type && <p className="mt-1 text-xs text-red-600">{errors.type}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Company Contribution Rate (%)</label>
                            <input type="number" step="0.01" value={data.company_contribution_rate} onChange={(e) => setData('company_contribution_rate', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Member Contribution Rate (%)</label>
                            <input type="number" step="0.01" value={data.employee_contribution_rate} onChange={(e) => setData('employee_contribution_rate', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Effective Date</label>
                            <input type="date" value={data.effective_date} onChange={(e) => setData('effective_date', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                            <textarea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Eligibility Criteria</label>
                            <textarea rows={3} value={data.eligibility_criteria} onChange={(e) => setData('eligibility_criteria', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Coverage Details</label>
                            <textarea rows={3} value={data.coverage_details} onChange={(e) => setData('coverage_details', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Create Benefit
                        </button>
                        <Link href={route('hr.benefits.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
