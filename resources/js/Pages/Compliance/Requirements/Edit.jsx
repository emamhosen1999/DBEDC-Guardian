import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function RequirementsEdit({ requirement, statuses, users }) {
    const { data, setData, put, processing, errors } = useForm({
        name: requirement.name || '',
        description: requirement.description || '',
        source: requirement.source || '',
        reference_number: requirement.reference_number || '',
        applicable: Boolean(requirement.applicable),
        status: requirement.status || 'not_evaluated',
        compliance_evidence: requirement.compliance_evidence || '',
        responsible_person_id: requirement.responsible_person_id || '',
        last_evaluation_date: requirement.last_evaluation_date ? requirement.last_evaluation_date.slice(0, 10) : '',
        next_evaluation_date: requirement.next_evaluation_date ? requirement.next_evaluation_date.slice(0, 10) : '',
    });

    const submit = (event) => {
        event.preventDefault();
        put(route('compliance.requirements.update', requirement.id));
    };

    return (
        <App>
            <Head title="Edit Requirement" />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Edit Compliance Requirement</h1>
                    <p className="text-sm text-gray-600">Update requirement details and evaluation data.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Name</label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                            <textarea
                                rows={4}
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.description && <p className="mt-1 text-xs text-red-600">{errors.description}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Source</label>
                            <input
                                type="text"
                                value={data.source}
                                onChange={(event) => setData('source', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.source && <p className="mt-1 text-xs text-red-600">{errors.source}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Reference Number</label>
                            <input
                                type="text"
                                value={data.reference_number}
                                onChange={(event) => setData('reference_number', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.reference_number && <p className="mt-1 text-xs text-red-600">{errors.reference_number}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Status</label>
                            <select
                                value={data.status}
                                onChange={(event) => setData('status', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            >
                                {statuses.map((status) => (
                                    <option key={status.id} value={status.id}>{status.name}</option>
                                ))}
                            </select>
                            {errors.status && <p className="mt-1 text-xs text-red-600">{errors.status}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Responsible Person</label>
                            <select
                                value={data.responsible_person_id}
                                onChange={(event) => setData('responsible_person_id', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            >
                                <option value="">Unassigned</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>{user.name}</option>
                                ))}
                            </select>
                            {errors.responsible_person_id && <p className="mt-1 text-xs text-red-600">{errors.responsible_person_id}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Last Evaluation Date</label>
                            <input
                                type="date"
                                value={data.last_evaluation_date}
                                onChange={(event) => setData('last_evaluation_date', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.last_evaluation_date && <p className="mt-1 text-xs text-red-600">{errors.last_evaluation_date}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Next Evaluation Date</label>
                            <input
                                type="date"
                                value={data.next_evaluation_date}
                                onChange={(event) => setData('next_evaluation_date', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.next_evaluation_date && <p className="mt-1 text-xs text-red-600">{errors.next_evaluation_date}</p>}
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Compliance Evidence</label>
                            <textarea
                                rows={4}
                                value={data.compliance_evidence}
                                onChange={(event) => setData('compliance_evidence', event.target.value)}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                            />
                            {errors.compliance_evidence && <p className="mt-1 text-xs text-red-600">{errors.compliance_evidence}</p>}
                        </div>

                        <label className="sm:col-span-2 flex items-center gap-2 rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.applicable}
                                onChange={(event) => setData('applicable', event.target.checked)}
                            />
                            Requirement is applicable
                        </label>
                    </div>

                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            Update Requirement
                        </button>
                        <Link
                            href={route('compliance.requirements.show', requirement.id)}
                            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
