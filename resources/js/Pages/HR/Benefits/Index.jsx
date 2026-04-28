import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function BenefitsIndex({ benefits = [], stats, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [type, setType] = useState(filters?.type || '');

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('hr.benefits.index'), {
            search: search || undefined,
            type: type || undefined,
        }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        setType('');
        router.get(route('hr.benefits.index'));
    };

    const deleteBenefit = (id) => {
        if (!window.confirm('Delete this benefit?')) return;
        router.delete(route('hr.benefits.destroy', id));
    };

    return (
        <App>
            <Head title="Benefits" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Benefits Management</h1>
                        <p className="text-sm text-gray-600">Manage employee benefits and enrollment.</p>
                    </div>
                    <Link href={route('hr.benefits.create')} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        Add Benefit
                    </Link>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard title="Total Benefits" value={stats.totalBenefits || 0} />
                        <StatCard title="Enrolled WorkForce" value={stats.enrolledWorkForce || 0} />
                        <StatCard title="Active Plans" value={stats.activePlans || 0} />
                    </div>
                )}

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search benefits..."
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <select
                        value={type}
                        onChange={(e) => setType(e.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">All Types</option>
                        <option value="health">Health</option>
                        <option value="insurance">Insurance</option>
                        <option value="retirement">Retirement</option>
                        <option value="wellness">Wellness</option>
                        <option value="other">Other</option>
                    </select>
                    <div className="flex gap-2">
                        <button type="submit" className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Apply
                        </button>
                        <button type="button" onClick={clearFilters} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Clear
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Benefit Name</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Type</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Enrolled</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Contribution</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {benefits.data?.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No benefits found.</td>
                                    </tr>
                                )}
                                {(benefits.data || []).map((benefit) => (
                                    <tr key={benefit.id}>
                                        <td className="px-4 py-3 font-medium text-gray-900">{benefit.name || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{benefit.type || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{benefit.enrolled_count || 0}</td>
                                        <td className="px-4 py-3 text-gray-700">{benefit.company_contribution_rate || '-'}%</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link href={route('hr.benefits.show', benefit.id)} className="text-blue-600 hover:text-blue-800">View</Link>
                                                <Link href={route('hr.benefits.edit', benefit.id)} className="text-amber-600 hover:text-amber-800">Edit</Link>
                                                <button onClick={() => deleteBenefit(benefit.id)} className="text-red-600 hover:text-red-800">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </App>
    );
}

function StatCard({ title, value }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wide text-gray-500">{title}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
        </div>
    );
}
