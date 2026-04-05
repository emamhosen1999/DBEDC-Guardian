import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function EditSkills({ skill }) {
    const { data, setData, post, processing, errors } = useForm({
        name: skill?.name || '',
        category: skill?.category || '',
        description: skill?.description || '',
        proficiency_level: skill?.proficiency_level || '',
        required_for_roles: skill?.required_for_roles || '',
        _method: 'PUT',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('hr.skills.update', skill.id));
    };

    return (
        <App>
            <Head title="Edit Skill" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Edit Skill</h1>
                    <p className="text-sm text-gray-600">Update skill details and proficiency information.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Skill Name</label>
                            <input type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Category</label>
                            <input type="text" value={data.category} onChange={(e) => setData('category', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Proficiency Level</label>
                            <select value={data.proficiency_level} onChange={(e) => setData('proficiency_level', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select level</option>
                                <option value="beginner">Beginner</option>
                                <option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option>
                                <option value="expert">Expert</option>
                            </select>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Required for Roles</label>
                            <input type="text" value={data.required_for_roles} onChange={(e) => setData('required_for_roles', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                            <textarea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Update Skill
                        </button>
                        <Link href={route('hr.skills.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
