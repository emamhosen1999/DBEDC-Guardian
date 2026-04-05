import React from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function Trainings({ trainings = [] }) {
    return (
        <App>
            <Head title="My Trainings" />
            <div className="space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Training & Development</h1>
                    <p className="text-sm text-gray-600">View your completed and upcoming trainings.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <p className="text-xs font-medium text-blue-600">Completed</p>
                        <p className="text-2xl font-bold text-blue-900">{trainings.filter((t) => t.status === 'completed').length}</p>
                    </div>
                    <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-4">
                        <p className="text-xs font-medium text-yellow-600">In Progress</p>
                        <p className="text-2xl font-bold text-yellow-900">{trainings.filter((t) => t.status === 'in_progress').length}</p>
                    </div>
                    <div className="rounded-lg border border-purple-200 bg-purple-50 p-4">
                        <p className="text-xs font-medium text-purple-600">Pending</p>
                        <p className="text-2xl font-bold text-purple-900">{trainings.filter((t) => t.status === 'pending').length}</p>
                    </div>
                </div>

                <div className="rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b border-gray-200 bg-gray-50">
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Training</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Status</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Completion Date</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Certificate</th>
                                </tr>
                            </thead>
                            <tbody>
                                {trainings.map((training) => (
                                    <tr key={training.id} className="border-b border-gray-100 hover:bg-gray-50">
                                        <td className="px-4 py-3 text-sm text-gray-900">{training.title}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span
                                                className={`inline-block rounded-full px-3 py-1 text-xs font-medium ${
                                                    training.status === 'completed'
                                                        ? 'bg-green-100 text-green-800'
                                                        : training.status === 'in_progress'
                                                          ? 'bg-yellow-100 text-yellow-800'
                                                          : 'bg-gray-100 text-gray-800'
                                                }`}
                                            >
                                                {training.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-600">{training.completion_date || '—'}</td>
                                        <td className="px-4 py-3 text-sm">
                                            {training.status === 'completed' ? (
                                                <button className="text-blue-600 hover:underline">Download</button>
                                            ) : (
                                                <span className="text-gray-400">—</span>
                                            )}
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
