import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function PerformanceShow({ review }) {
    return (
        <App>
            <Head title="Performance Review Details" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Performance Review</h1>
                        <p className="text-sm text-gray-600">{review.employee?.name} - {review.review_date}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('hr.performance.edit', review.id)} className="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                            Edit
                        </Link>
                        <Link href={route('hr.performance.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Back
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3 sm:p-6">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Employee</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{review.employee?.name || '-'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Reviewer</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{review.reviewer?.name || '-'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Overall Rating</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{review.overall_rating ? `${review.overall_rating}/5` : '-'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Status</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{review.status}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase tracking-wide text-gray-500">Review Period</p>
                        <p className="mt-1 text-sm font-medium text-gray-900">{review.review_period_start} to {review.review_period_end}</p>
                    </div>
                </div>

                <div className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    {review.strengths && (
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-500">Strengths</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{review.strengths}</p>
                        </div>
                    )}
                    {review.areas_for_improvement && (
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-500">Areas for Improvement</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{review.areas_for_improvement}</p>
                        </div>
                    )}
                    {review.goals && (
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-500">Goals</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{review.goals}</p>
                        </div>
                    )}
                    {review.comments && (
                        <div>
                            <p className="text-xs uppercase tracking-wide text-gray-500">Comments</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{review.comments}</p>
                        </div>
                    )}
                </div>
            </div>
        </App>
    );
}
