import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function PerformanceEdit({ review, employees = [], reviewers = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        employee_id: review.employee_id || '',
        reviewer_id: review.reviewer_id || '',
        review_date: review.review_date || '',
        review_period_start: review.review_period_start || '',
        review_period_end: review.review_period_end || '',
        overall_rating: review.overall_rating || '',
        comments: review.comments || '',
        strengths: review.strengths || '',
        areas_for_improvement: review.areas_for_improvement || '',
        goals: review.goals || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('hr.performance.update', review.id));
    };

    return (
        <App>
            <Head title="Edit Performance Review" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Edit Performance Review</h1>
                    <p className="text-sm text-gray-600">Update performance evaluation details.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Employee</label>
                            <select value={data.employee_id} onChange={(e) => setData('employee_id', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select employee</option>
                                {employees.map((emp) => (
                                    <option key={emp.id} value={emp.id}>{emp.name}</option>
                                ))}
                            </select>
                            {errors.employee_id && <p className="mt-1 text-xs text-red-600">{errors.employee_id}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Reviewer</label>
                            <select value={data.reviewer_id} onChange={(e) => setData('reviewer_id', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select reviewer</option>
                                {reviewers.map((rev) => (
                                    <option key={rev.id} value={rev.id}>{rev.name}</option>
                                ))}
                            </select>
                            {errors.reviewer_id && <p className="mt-1 text-xs text-red-600">{errors.reviewer_id}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Review Date</label>
                            <input type="date" value={data.review_date} onChange={(e) => setData('review_date', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.review_date && <p className="mt-1 text-xs text-red-600">{errors.review_date}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Overall Rating (1-5)</label>
                            <input type="number" min="1" max="5" step="0.5" value={data.overall_rating} onChange={(e) => setData('overall_rating', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.overall_rating && <p className="mt-1 text-xs text-red-600">{errors.overall_rating}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Review Period Start</label>
                            <input type="date" value={data.review_period_start} onChange={(e) => setData('review_period_start', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Review Period End</label>
                            <input type="date" value={data.review_period_end} onChange={(e) => setData('review_period_end', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Strengths</label>
                            <textarea rows={3} value={data.strengths} onChange={(e) => setData('strengths', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Areas for Improvement</label>
                            <textarea rows={3} value={data.areas_for_improvement} onChange={(e) => setData('areas_for_improvement', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Goals</label>
                            <textarea rows={3} value={data.goals} onChange={(e) => setData('goals', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Comments</label>
                            <textarea rows={3} value={data.comments} onChange={(e) => setData('comments', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Update Review
                        </button>
                        <Link href={route('hr.performance.show', review.id)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
