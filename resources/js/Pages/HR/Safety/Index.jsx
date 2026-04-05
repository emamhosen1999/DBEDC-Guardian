import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function SafetyIndex({ incidents = [], stats, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [severity, setSeverity] = useState(filters?.severity || '');

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('hr.safety.index'), {
            search: search || undefined,
            severity: severity || undefined,
        }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setSearch('');
        setSeverity('');
        router.get(route('hr.safety.index'));
    };

    return (
        <App>
            <Head title="Safety Management" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Safety Management</h1>
                        <p className="text-sm text-gray-600">Track incidents, inspections, and safety training.</p>
                    </div>
                    <Link href={route('hr.safety.create')} className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Report Incident
                    </Link>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-4">
                        <StatCard title="Total Incidents" value={stats.totalIncidents || 0} bgColor="bg-red-50" textColor="text-red-600" />
                        <StatCard title="Critical" value={stats.criticalIncidents || 0} bgColor="bg-red-50" textColor="text-red-600" />
                        <StatCard title="This Month" value={stats.monthlyIncidents || 0} bgColor="bg-yellow-50" textColor="text-yellow-600" />
                        <StatCard title="Days Incident Free" value={stats.daysIncidentFree || 0} bgColor="bg-green-50" textColor="text-green-600" />
                    </div>
                )}

                <form onSubmit={applyFilters} className="grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-3">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search incidents..."
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    <select
                        value={severity}
                        onChange={(e) => setSeverity(e.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">All Severities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
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
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Description</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Severity</th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600">Reported By</th>
                                    <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {incidents.data?.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No incidents found.</td>
                                    </tr>
                                )}
                                {(incidents.data || []).map((incident) => (
                                    <tr key={incident.id}>
                                        <td className="px-4 py-3 text-gray-700">{incident.incident_date || '-'}</td>
                                        <td className="px-4 py-3 font-medium text-gray-900">{incident.description || '-'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                incident.severity === 'critical' ? 'bg-red-100 text-red-800' :
                                                incident.severity === 'high' ? 'bg-orange-100 text-orange-800' :
                                                incident.severity === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                                                'bg-blue-100 text-blue-800'
                                            }`}>
                                                {incident.severity}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-700">{incident.reported_by || '-'}</td>
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

function StatCard({ title, value, bgColor, textColor }) {
    return (
        <div className={`rounded-lg border border-gray-200 ${bgColor} p-4`}>
            <p className="text-xs uppercase tracking-wide text-gray-500">{title}</p>
            <p className={`mt-1 text-2xl font-semibold ${textColor}`}>{value}</p>
        </div>
    );
}
