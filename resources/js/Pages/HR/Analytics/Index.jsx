import React from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function AnalyticsIndex({ stats = {} }) {
    return (
        <App>
            <Head title="HR Analytics" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">HR Analytics</h1>
                    <p className="text-sm text-gray-600">View comprehensive HR metrics and insights.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <StatCard title="Total WorkForce" value={stats.totalWorkForce || 0} />
                    <StatCard title="Active Listings" value={stats.activeJobs || 0} />
                    <StatCard title="Avg Salary" value={stats.averageSalary ? `$${(stats.averageSalary / 1000).toFixed(0)}k` : '-'} />
                    <StatCard title="Turnover Rate" value={`${stats.turnoverRate || 0}%`} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <chart className="rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <h3 className="mb-4 text-sm font-medium text-gray-900">Member Distribution by Department</h3>
                        <div className="space-y-3">
                            {stats.departmentDistribution && Object.entries(stats.departmentDistribution).map(([dept, count]) => (
                                <div key={dept} className="flex items-center justify-between">
                                    <span className="text-sm text-gray-600">{dept}</span>
                                    <div className="w-24 rounded-full bg-gray-200 h-2">
                                        <div className="h-full rounded-full bg-blue-600" style={{ width: `${Math.min(count * 10, 100)}%` }}></div>
                                    </div>
                                    <span className="text-sm font-medium text-gray-900">{count}</span>
                                </div>
                            ))}
                        </div>
                    </chart>

                    <chart className="rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <h3 className="mb-4 text-sm font-medium text-gray-900">Performance Ratings</h3>
                        <div className="space-y-3">
                            {stats.performanceRatings && Object.entries(stats.performanceRatings).map(([rating, count]) => (
                                <div key={rating} className="flex items-center justify-between">
                                    <span className="text-sm text-gray-600">{rating}</span>
                                    <div className="w-24 rounded-full bg-gray-200 h-2">
                                        <div className="h-full rounded-full bg-green-600" style={{ width: `${Math.min(count * 10, 100)}%` }}></div>
                                    </div>
                                    <span className="text-sm font-medium text-gray-900">{count}</span>
                                </div>
                            ))}
                        </div>
                    </chart>
                </div>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <h3 className="mb-4 text-sm font-medium text-gray-900">Key Metrics</h3>
                    <div className="space-y-3">
                        <div className="flex justify-between border-b border-gray-200 pb-3 last:border-b-0">
                            <span className="text-sm text-gray-600">Recruitment Cycle Time</span>
                            <span className="text-sm font-medium text-gray-900">{stats.recruitmentCycleTime || '-'} days</span>
                        </div>
                        <div className="flex justify-between border-b border-gray-200 pb-3 last:border-b-0">
                            <span className="text-sm text-gray-600">Time to Hire</span>
                            <span className="text-sm font-medium text-gray-900">{stats.timeToHire || '-'} days</span>
                        </div>
                        <div className="flex justify-between border-b border-gray-200 pb-3 last:border-b-0">
                            <span className="text-sm text-gray-600">Training Completion Rate</span>
                            <span className="text-sm font-medium text-gray-900">{stats.trainingCompletionRate || '-'}%</span>
                        </div>
                        <div className="flex justify-between border-b border-gray-200 pb-3 last:border-b-0">
                            <span className="text-sm text-gray-600">Benefits Enrollment</span>
                            <span className="text-sm font-medium text-gray-900">{stats.benefitsEnrollmentRate || '-'}%</span>
                        </div>
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
