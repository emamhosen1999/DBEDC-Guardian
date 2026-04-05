import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function PerformanceIndex({ reviews = [], stats, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [status, setStatus] = useState(filters?.status || '');

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('hr.performance.index'), {
            search: search || undefined,
            status: status || undefined,
        }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        router.get(route('hr.performance.index'));
    };

    const deleteReview = (id) => {
        if (!window.confirm('Delete this performance review?')) return;
        router.delete(route('hr.performance.destroy', id));
    };

    return (
        <App>
            <Head title="Performance Reviews" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Performance Reviews</h1>
                        <p className="text-sm text-gray-600">Manage employee performance evaluations.</p>
                    </div>
                    <Link href={route('hr.performance.create')} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        New Review
                    </Link>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-4">
                        <StatCard title="Total Reviews" value={stats.totalReviews || 0} />
                        <StatCard title="Pending" value={stats.pendingReviews || 0} />
                        <StatCard title="Completed" value={stats.completedReviews || 0} />
                        <StatCard title="Avg Rating" value={stats.averageRating ? stats.averageRating.toFixed(1) : '-'} />
                    </div>
                )}

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search reviews..."
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="completed">Completed</option>
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
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Employee</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Reviewer</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Period</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Rating</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {reviews.data?.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-gray-500">No performance reviews found.</td>
                                    </tr>
                                )}
                                {(reviews.data || []).map((review) => (
                                    <tr key={review.id}>
                                        <td className="px-4 py-3 font-medium text-gray-900">{review.employee?.name || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{review.reviewer?.name || '-'}</td>
                                        <td className="px-4 py-3 text-gray-700">{review.review_period_start} to {review.review_period_end}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                review.status === 'completed' ? 'bg-green-100 text-green-800' :
                                                review.status === 'pending' ? 'bg-yellow-100 text-yellow-800' :
                                                'bg-blue-100 text-blue-800'
                                            }`}>
                                                {review.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-700">{review.overall_rating ? `${review.overall_rating}/5` : '-'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link href={route('hr.performance.show', review.id)} className="text-blue-600 hover:text-blue-800">View</Link>
                                                <Link href={route('hr.performance.edit', review.id)} className="text-amber-600 hover:text-amber-800">Edit</Link>
                                                <button onClick={() => deleteReview(review.id)} className="text-red-600 hover:text-red-800">Delete</button>
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
