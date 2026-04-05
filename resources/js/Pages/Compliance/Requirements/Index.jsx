import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function RequirementsIndex({ requirements, filters, statuses, users }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [status, setStatus] = useState(filters?.status || '');
    const [applicable, setApplicable] = useState(filters?.applicable || '');
    const [responsiblePersonId, setResponsiblePersonId] = useState(filters?.responsible_person_id || '');
    const [evaluationDue, setEvaluationDue] = useState(Boolean(filters?.evaluation_due));

    const applyFilters = (event) => {
        event.preventDefault();

        router.get(route('compliance.requirements.index'), {
            search: search || undefined,
            status: status || undefined,
            applicable: applicable || undefined,
            responsible_person_id: responsiblePersonId || undefined,
            evaluation_due: evaluationDue ? 1 : undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setApplicable('');
        setResponsiblePersonId('');
        setEvaluationDue(false);
        router.get(route('compliance.requirements.index'));
    };

    const deleteRequirement = (requirementId) => {
        if (!window.confirm('Delete this compliance requirement?')) {
            return;
        }

        router.delete(route('compliance.requirements.destroy', requirementId));
    };

    return (
        <App>
            <Head title="Compliance Requirements" />

            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Compliance Requirements</h1>
                        <p className="text-sm text-gray-600">Track and evaluate compliance obligations.</p>
                    </div>
                    <Link
                        href={route('compliance.requirements.create')}
                        className="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        Add Requirement
                    </Link>
                </div>

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6">
                    <input
                        type="text"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search"
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm lg:col-span-2"
                    />

                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">All Statuses</option>
                        {statuses.map((item) => (
                            <option key={item.id} value={item.id}>{item.name}</option>
                        ))}
                    </select>

                    <select
                        value={applicable}
                        onChange={(event) => setApplicable(event.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">Applicable: Any</option>
                        <option value="1">Applicable</option>
                        <option value="0">Not Applicable</option>
                    </select>

                    <select
                        value={responsiblePersonId}
                        onChange={(event) => setResponsiblePersonId(event.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">Responsible: Any</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>{user.name}</option>
                        ))}
                    </select>

                    <label className="flex items-center gap-2 rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <input
                            type="checkbox"
                            checked={evaluationDue}
                            onChange={(event) => setEvaluationDue(event.target.checked)}
                        />
                        Due Evaluation
                    </label>

                    <div className="flex gap-2 sm:col-span-2 lg:col-span-6">
                        <button
                            type="submit"
                            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black"
                        >
                            Apply
                        </button>
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Clear
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Requirement</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Source</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Responsible</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Next Evaluation</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {requirements.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                                            No requirements found.
                                        </td>
                                    </tr>
                                )}

                                {requirements.data.map((requirement) => (
                                    <tr key={requirement.id}>
                                        <td className="px-4 py-3">
                                            <div className="font-medium text-gray-900">{requirement.name}</div>
                                            <div className="text-xs text-gray-500">{requirement.reference_number || 'No reference number'}</div>
                                        </td>
                                        <td className="px-4 py-3 text-gray-700">{requirement.status.replaceAll('_', ' ')}</td>
                                        <td className="px-4 py-3 text-gray-700">{requirement.source}</td>
                                        <td className="px-4 py-3 text-gray-700">{requirement.responsible_person?.name || 'Unassigned'}</td>
                                        <td className="px-4 py-3 text-gray-700">{requirement.next_evaluation_date || '-'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                <Link href={route('compliance.requirements.show', requirement.id)} className="text-blue-600 hover:text-blue-800">
                                                    View
                                                </Link>
                                                <Link href={route('compliance.requirements.edit', requirement.id)} className="text-amber-600 hover:text-amber-800">
                                                    Edit
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => deleteRequirement(requirement.id)}
                                                    className="text-red-600 hover:text-red-800"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {requirements.links?.length > 3 && (
                        <div className="flex flex-wrap gap-2 border-t border-gray-200 px-4 py-3">
                            {requirements.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                                    className={`rounded-md px-3 py-1 text-sm ${link.active ? 'bg-blue-600 text-white' : 'border border-gray-300 text-gray-700'} ${!link.url ? 'cursor-not-allowed opacity-50' : 'hover:bg-gray-50'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </App>
    );
}
