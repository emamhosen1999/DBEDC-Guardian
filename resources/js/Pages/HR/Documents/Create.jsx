import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function DocumentsCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        category: '',
        file: null,
        description: '',
        access_level: 'private',
    });

    const handleFileChange = (e) => {
        setData('file', e.target.files[0]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('hr.documents.store'));
    };

    return (
        <App>
            <Head title="Upload Document" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Upload HR Document</h1>
                    <p className="text-sm text-gray-600">Add a new document to the HR repository.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Document Name</label>
                            <input type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Category</label>
                            <select value={data.category} onChange={(e) => setData('category', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Select category</option>
                                <option value="policies">Policies</option>
                                <option value="procedures">Procedures</option>
                                <option value="forms">Forms</option>
                                <option value="contracts">Contracts</option>
                                <option value="handbooks">Handbooks</option>
                            </select>
                            {errors.category && <p className="mt-1 text-xs text-red-600">{errors.category}</p>}
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Upload File</label>
                            <input type="file" onChange={handleFileChange} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.file && <p className="mt-1 text-xs text-red-600">{errors.file}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Access Level</label>
                            <select value={data.access_level} onChange={(e) => setData('access_level', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                                <option value="private">Private (Admin Only)</option>
                                <option value="restricted">Restricted</option>
                                <option value="public">Public</option>
                            </select>
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                            <textarea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Upload Document
                        </button>
                        <Link href={route('hr.documents.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
