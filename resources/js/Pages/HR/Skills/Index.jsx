import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function SkillsIndex({ skills = [], stats, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('hr.skills.index'), {
            search: search || undefined,
        }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        router.get(route('hr.skills.index'));
    };

    return (
        <App>
            <Head title="Skills Management" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Skills Management</h1>
                        <p className="text-sm text-gray-600">Manage employee skills and competencies.</p>
                    </div>
                    <Link href={route('hr.skills.create')} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        Add Skill
                    </Link>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard title="Total Skills" value={stats.totalSkills || 0} />
                        <StatCard title="WorkForce with Skills" value={stats.employeesWithSkills || 0} />
                        <StatCard title="Skill Gap Areas" value={stats.skillGaps || 0} />
                    </div>
                )}

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search skills..."
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <div className="flex gap-2">
                        <button type="submit" className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                            Search
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
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Skill Name</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Category</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">WorkForce</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Proficiency Level</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {skills.data?.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No skills found.</td>
                                    </tr>
                                )}
                                {(skills.data || []).map((skill) => (
                                    <tr key={skill.id}>
                                        <td className="px-4 py-3 font-medium text-gray-900">{skill.name || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{skill.category || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{skill.employee_count || 0}</td>
                                        <td className="px-4 py-3 text-gray-700">{skill.avg_proficiency || '-'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={`#`} className="text-blue-600 hover:text-blue-800">View</Link>
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
