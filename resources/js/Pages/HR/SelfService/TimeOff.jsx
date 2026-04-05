import React from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function TimeOff({ timeOffRequests = [] }) {
    return (
        <App>
            <Head title="Time Off" />
            <div className="space-y-6 p-4 sm:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Time Off Management</h1>
                        <p className="text-sm text-gray-600">View and manage your leave requests.</p>
                    </div>
                    <button className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        Request Time Off
                    </button>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <p className="text-xs font-medium text-blue-600">Available Days</p>
                        <p className="text-2xl font-bold text-blue-900">15</p>
                    </div>
                    <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-4">
                        <p className="text-xs font-medium text-yellow-600">Pending Approval</p>
                        <p className="text-2xl font-bold text-yellow-900">{timeOffRequests.filter((r) => r.status === 'pending').length}</p>
                    </div>
                    <div className="rounded-lg border border-green-200 bg-green-50 p-4">
                        <p className="text-xs font-medium text-green-600">Approved</p>
                        <p className="text-2xl font-bold text-green-900">{timeOffRequests.filter((r) => r.status === 'approved').length}</p>
                    </div>
                </div>

                <div className="rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b border-gray-200 bg-gray-50">
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Type</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">From</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">To</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Days</th>
                                    <th className="px-4 py-3 text-left text-sm font-medium text-gray-700">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {timeOffRequests.map((request) => (
                                    <tr key={request.id} className="border-b border-gray-100 hover:bg-gray-50">
                                        <td className="px-4 py-3 text-sm font-medium text-gray-900">{request.type}</td>
                                        <td className="px-4 py-3 text-sm text-gray-600">{request.from_date}</td>
                                        <td className="px-4 py-3 text-sm text-gray-600">{request.to_date}</td>
                                        <td className="px-4 py-3 text-sm text-gray-600">{request.days}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span
                                                className={`inline-block rounded-full px-3 py-1 text-xs font-medium ${
                                                    request.status === 'approved'
                                                        ? 'bg-green-100 text-green-800'
                                                        : request.status === 'pending'
                                                          ? 'bg-yellow-100 text-yellow-800'
                                                          : 'bg-red-100 text-red-800'
                                                }`}
                                            >
                                                {request.status}
                                            </span>
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
