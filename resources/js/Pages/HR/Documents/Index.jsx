import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function DocumentsIndex({ documents = [], stats, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [category, setCategory] = useState(filters?.category || '');

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('hr.documents.index'), {
            search: search || undefined,
            category: category || undefined,
        }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        setCategory('');
        router.get(route('hr.documents.index'));
    };

    return (
        <App>
            <Head title="HR Documents" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">HR Documents</h1>
                        <p className="text-sm text-gray-600">Manage company and employee documents.</p>
                    </div>
                    <Link href={route('hr.documents.create')} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        Upload Document
                    </Link>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard title="Total Documents" value={stats.totalDocuments || 0} />
                        <StatCard title="Updated This Month" value={stats.monthlyUploads || 0} />
                        <StatCard title="Categories" value={stats.categories || 0} />
                    </div>
                )}

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search documents..."
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <select
                        value={category}
                        onChange={(e) => setCategory(e.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">All Categories</option>
                        <option value="policies">Policies</option>
                        <option value="procedures">Procedures</option>
                        <option value="forms">Forms</option>
                        <option value="contracts">Contracts</option>
                        <option value="handbooks">Handbooks</option>
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
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Document Name</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Category</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Uploaded</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Uploaded By</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {documents.data?.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No documents found.</td>
                                    </tr>
                                )}
                                {(documents.data || []).map((doc) => (
                                    <tr key={doc.id}>
                                        <td className="px-4 py-3 font-medium text-gray-900">{doc.name || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{doc.category || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{doc.uploaded_at || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{doc.uploaded_by || '-'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <a href={`#`} className="text-blue-600 hover:text-blue-800">Download</a>
                                                <Link href={route('hr.documents.show', doc.id)} className="text-blue-600 hover:text-blue-800">View</Link>
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
