import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function RecruitmentEdit({ position, departments = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: position.title || '',
        department: position.department || '',
        posted_date: position.posted_date || '',
        description: position.description || '',
        requirements: position.requirements || '',
        number_of_openings: position.number_of_openings || '',
        salary_range_min: position.salary_range_min || '',
        salary_range_max: position.salary_range_max || '',
        status: position.status || 'open',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('hr.recruitment.update', position.id));
    };

    return (
        <App>
            <Head title="Edit Position" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Edit Job Position</h1>
                    <p className="text-sm text-gray-600">Update position details.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Job Title</label>
                            <input type="text" value={data.title} onChange={(e) => setData('title', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.title && <p className="mt-1 text-xs text-red-600">{errors.title}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Department</label>
                            <select value={data.department} onChange={(e) => setData('department', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select department</option>
                                {departments.map((dept) => (
                                    <option key={dept} value={dept}>{dept}</option>
                                ))}
                            </select>
                            {errors.department && <p className="mt-1 text-xs text-red-600">{errors.department}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Posted Date</label>
                            <input type="date" value={data.posted_date} onChange={(e) => setData('posted_date', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Status</label>
                            <select value={data.status} onChange={(e) => setData('status', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Number of Openings</label>
                            <input type="number" min="1" value={data.number_of_openings} onChange={(e) => setData('number_of_openings', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Salary Range (Min)</label>
                            <input type="number" value={data.salary_range_min} onChange={(e) => setData('salary_range_min', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Salary Range (Max)</label>
                            <input type="number" value={data.salary_range_max} onChange={(e) => setData('salary_range_max', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Job Description</label>
                            <textarea rows={4} value={data.description} onChange={(e) => setData('description', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Requirements</label>
                            <textarea rows={4} value={data.requirements} onChange={(e) => setData('requirements', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Update Position
                        </button>
                        <Link href={route('hr.recruitment.show', position.id)} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
