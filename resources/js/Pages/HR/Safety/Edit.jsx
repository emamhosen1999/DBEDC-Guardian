import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function EditSafety({ incident }) {
    const { data, setData, post, processing, errors } = useForm({
        incident_date: incident?.incident_date || '',
        description: incident?.description || '',
        severity: incident?.severity || '',
        location: incident?.location || '',
        reported_by: incident?.reported_by || '',
        involved_employees: incident?.involved_employees || '',
        root_cause: incident?.root_cause || '',
        corrective_action: incident?.corrective_action || '',
        status: incident?.status || 'open',
        _method: 'PUT',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('hr.safety.incidents.update', incident.id));
    };

    return (
        <App>
            <Head title="Edit Incident" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Edit Safety Incident</h1>
                    <p className="text-sm text-gray-600">Update incident and corrective action details.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Incident Date</label>
                            <input type="date" value={data.incident_date} onChange={(e) => setData('incident_date', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.incident_date && <p className="mt-1 text-xs text-red-600">{errors.incident_date}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Severity</label>
                            <select value={data.severity} onChange={(e) => setData('severity', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select severity</option>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </select>
                            {errors.severity && <p className="mt-1 text-xs text-red-600">{errors.severity}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Location</label>
                            <input type="text" value={data.location} onChange={(e) => setData('location', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Status</label>
                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="open">Open</option>
                                <option value="in_investigation">In Investigation</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                            <textarea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Root Cause</label>
                            <textarea rows={3} value={data.root_cause} onChange={(e) => setData('root_cause', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Corrective Action</label>
                            <textarea rows={3} value={data.corrective_action} onChange={(e) => setData('corrective_action', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Update Incident
                        </button>
                        <Link href={route('hr.safety.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
