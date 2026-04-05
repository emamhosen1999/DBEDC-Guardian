import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function RecruitmentApplications({ position, applications = [], filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [status, setStatus] = useState(filters?.status || '');

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('hr.recruitment.applications', position.id), {
            search: search || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        router.get(route('hr.recruitment.applications', position.id));
    };

    return (
        <App>
            <Head title="Applications" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Applications for {position.title}</h1>
                        <p className="text-sm text-gray-600">Total: {applications.length || 0} applicants</p>
                    </div>
                    <Link href={route('hr.recruitment.show', position.id)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Back
                    </Link>
                </div>

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3">
                    <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search..." className="rounded-md border border-gray-300 px-3 py-2 text-sm" />
                    <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="">All Statuses</option>
                        <option value="new">New</option>
                        <option value="under_review">Under Review</option>
                        <option value="interview">Interview</option>
                        <option value="rejected">Rejected</option>
                        <option value="approved">Approved</option>
                    </select>
                    <div className="flex gap-2">
                        <button type="submit" className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">Apply</button>
                        <button type="button" onClick={clearFilters} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Applied</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {!applications || applications.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No applications found.</td>
                                    </tr>
                                ) : (
                                    applications.map((app) => (
                                        <tr key={app.id}>
                                            <td className="px-4 py-3 font-medium text-gray-900">{app.name || '-'}</td>
                                            <td className="px-4 py-3 text-gray-700">{app.email || '-'}</td>
                                            <td className="px-4 py-3 text-gray-700">{app.applied_date || '-'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                    app.status === 'approved' ? 'bg-green-100 text-green-800' :
                                                    app.status === 'rejected' ? 'bg-red-100 text-red-800' :
                                                    app.status === 'interview' ? 'bg-blue-100 text-blue-800' :
                                                    'bg-gray-100 text-gray-800'
                                                }`}>
                                                    {app.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={`#`} className="text-blue-600 hover:text-blue-800">View</Link>
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
