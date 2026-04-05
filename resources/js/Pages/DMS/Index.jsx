import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function DmsIndex(props) {
    const {
        page = 'dashboard',
        stats,
        recentDocuments,
        documents,
        document,
        shares,
        analytics,
        categories = [],
        folders = [],
        users = [],
        statuses = [],
        filters = {},
    } = props;

    const createForm = useForm({
        title: '',
        description: '',
        category_id: '',
        document: null,
        visibility: 'internal',
    });

    const shareForm = useForm({
        shared_with: '',
        permission: 'view',
        expires_at: '',
    });

    const accessForm = useForm({
        document_id: '',
        folder_id: '',
        access_permissions: [],
    });

    const submitCreate = (event) => {
        event.preventDefault();
        createForm.post(route('dms.documents.store'));
    };

    const submitShare = (event) => {
        event.preventDefault();
        if (!document) {
            return;
        }
        shareForm.post(route('dms.documents.share', document.id));
    };

    const submitAccess = (event) => {
        event.preventDefault();
        const permissionList = Array.from(new Set(accessForm.data.access_permissions.filter(Boolean)));
        accessForm.setData('access_permissions', permissionList);
        accessForm.post(route('dms.access-control.update'));
    };

    const deleteDocument = (documentId) => {
        if (!window.confirm('Delete this document?')) {
            return;
        }

        router.delete(route('dms.documents.destroy', documentId));
    };

    const DashboardView = () => (
        <div className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <StatCard title="Documents" value={stats?.total_documents || 0} />
                <StatCard title="Published" value={stats?.published_documents || 0} />
                <StatCard title="Shared" value={stats?.shared_documents || 0} />
                <StatCard title="Categories" value={stats?.total_categories || 0} />
                <StatCard title="Folders" value={stats?.total_folders || 0} />
            </div>

            <div className="rounded-lg border border-gray-200 bg-white p-4">
                <h2 className="text-lg font-semibold text-gray-900">Quick Links</h2>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Link className="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" href={route('dms.documents')}>Documents</Link>
                    <Link className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" href={route('dms.documents.create')}>Upload</Link>
                    <Link className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" href={route('dms.shared')}>Shared</Link>
                    <Link className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" href={route('dms.analytics')}>Analytics</Link>
                    <Link className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" href={route('dms.access-control')}>Access Control</Link>
                </div>
            </div>

            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div className="border-b border-gray-200 px-4 py-3">
                    <h2 className="text-sm font-semibold text-gray-900">Recent Documents</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Category</th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Uploaded</th>
                                <th className="px-4 py-3 text-right font-medium text-gray-600">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {(recentDocuments || []).length === 0 && (
                                <tr>
                                    <td className="px-4 py-8 text-center text-gray-500" colSpan={5}>No recent documents found.</td>
                                </tr>
                            )}
                            {(recentDocuments || []).map((item) => (
                                <tr key={item.id}>
                                    <td className="px-4 py-3 text-gray-900">{item.title}</td>
                                    <td className="px-4 py-3 text-gray-700">{item.category?.name || '-'}</td>
                                    <td className="px-4 py-3 text-gray-700">{item.status}</td>
                                    <td className="px-4 py-3 text-gray-700">{item.created_at}</td>
                                    <td className="px-4 py-3 text-right">
                                        <Link className="text-blue-600 hover:text-blue-800" href={route('dms.documents.show', item.id)}>View</Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );

    const DocumentsView = () => (
        <div className="space-y-4">
            <div className="flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 className="text-lg font-semibold text-gray-900">Documents</h2>
                <Link href={route('dms.documents.create')} className="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">Upload Document</Link>
            </div>

            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Number</th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Category</th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                                <th className="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {documents?.data?.length === 0 && (
                                <tr>
                                    <td className="px-4 py-8 text-center text-gray-500" colSpan={5}>No documents found.</td>
                                </tr>
                            )}
                            {(documents?.data || []).map((item) => (
                                <tr key={item.id}>
                                    <td className="px-4 py-3 text-gray-900">{item.title}</td>
                                    <td className="px-4 py-3 text-gray-700">{item.document_number}</td>
                                    <td className="px-4 py-3 text-gray-700">{item.category?.name || '-'}</td>
                                    <td className="px-4 py-3 text-gray-700">{item.status}</td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex justify-end gap-2">
                                            <Link className="text-blue-600 hover:text-blue-800" href={route('dms.documents.show', item.id)}>View</Link>
                                            <button type="button" className="text-red-600 hover:text-red-800" onClick={() => deleteDocument(item.id)}>Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );

    const CreateView = () => (
        <form onSubmit={submitCreate} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
            <h2 className="text-lg font-semibold text-gray-900">Upload Document</h2>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <label className="mb-1 block text-sm font-medium text-gray-700">Title</label>
                    <input
                        type="text"
                        value={createForm.data.title}
                        onChange={(event) => createForm.setData('title', event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    {createForm.errors.title && <p className="mt-1 text-xs text-red-600">{createForm.errors.title}</p>}
                </div>

                <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Category</label>
                    <select
                        value={createForm.data.category_id}
                        onChange={(event) => createForm.setData('category_id', event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">Select category</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>{category.name}</option>
                        ))}
                    </select>
                    {createForm.errors.category_id && <p className="mt-1 text-xs text-red-600">{createForm.errors.category_id}</p>}
                </div>

                <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Visibility</label>
                    <select
                        value={createForm.data.visibility}
                        onChange={(event) => createForm.setData('visibility', event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="public">Public</option>
                        <option value="internal">Internal</option>
                        <option value="restricted">Restricted</option>
                        <option value="confidential">Confidential</option>
                    </select>
                </div>

                <div className="sm:col-span-2">
                    <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                    <textarea
                        rows={3}
                        value={createForm.data.description}
                        onChange={(event) => createForm.setData('description', event.target.value)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                </div>

                <div className="sm:col-span-2">
                    <label className="mb-1 block text-sm font-medium text-gray-700">File</label>
                    <input
                        type="file"
                        onChange={(event) => createForm.setData('document', event.target.files?.[0] || null)}
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                    {createForm.errors.document && <p className="mt-1 text-xs text-red-600">{createForm.errors.document}</p>}
                </div>
            </div>

            <div className="flex gap-2">
                <button type="submit" disabled={createForm.processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    Save
                </button>
                <Link href={route('dms.documents')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </Link>
            </div>
        </form>
    );

    const ShowView = () => (
        <div className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900">{document?.title}</h2>
                    <p className="text-sm text-gray-600">{document?.document_number}</p>
                </div>
                <div className="flex gap-2">
                    <Link href={route('dms.documents.download', document?.id)} className="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-black">
                        Download
                    </Link>
                    <Link href={route('dms.documents')} className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Back
                    </Link>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Info label="Category" value={document?.category?.name || '-'} />
                <Info label="Status" value={document?.status || '-'} />
                <Info label="Version" value={document?.version || '-'} />
                <Info label="Visibility" value={document?.visibility || '-'} />
                <Info label="Created By" value={document?.creator?.name || '-'} />
                <Info label="Updated By" value={document?.updater?.name || '-'} />
            </div>

            <div>
                <p className="text-xs uppercase tracking-wide text-gray-500">Description</p>
                <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{document?.description || '-'}</p>
            </div>

            <form onSubmit={submitShare} className="space-y-3 rounded-md border border-gray-200 p-4">
                <h3 className="text-sm font-semibold text-gray-900">Share Document</h3>
                <div className="grid gap-3 sm:grid-cols-3">
                    <select
                        value={shareForm.data.shared_with}
                        onChange={(event) => shareForm.setData('shared_with', event.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="">Public Link (token)</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>{user.name}</option>
                        ))}
                    </select>

                    <select
                        value={shareForm.data.permission}
                        onChange={(event) => shareForm.setData('permission', event.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    >
                        <option value="view">View</option>
                        <option value="comment">Comment</option>
                        <option value="edit">Edit</option>
                    </select>

                    <input
                        type="date"
                        value={shareForm.data.expires_at}
                        onChange={(event) => shareForm.setData('expires_at', event.target.value)}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                    />
                </div>
                <button type="submit" className="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700" disabled={shareForm.processing}>
                    Share
                </button>
            </form>
        </div>
    );

    const SharedView = () => (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="border-b border-gray-200 px-4 py-3">
                <h2 className="text-lg font-semibold text-gray-900">Shared Documents</h2>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left font-medium text-gray-600">Document</th>
                            <th className="px-4 py-3 text-left font-medium text-gray-600">Shared By</th>
                            <th className="px-4 py-3 text-left font-medium text-gray-600">Shared With</th>
                            <th className="px-4 py-3 text-left font-medium text-gray-600">Permission</th>
                            <th className="px-4 py-3 text-left font-medium text-gray-600">Expires</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {(shares?.data || []).length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-gray-500">No shares found.</td>
                            </tr>
                        )}
                        {(shares?.data || []).map((share) => (
                            <tr key={share.id}>
                                <td className="px-4 py-3 text-gray-900">{share.document?.title || '-'}</td>
                                <td className="px-4 py-3 text-gray-700">{share.shared_by?.name || '-'}</td>
                                <td className="px-4 py-3 text-gray-700">{share.shared_with?.name || 'Public Link'}</td>
                                <td className="px-4 py-3 text-gray-700">{share.permission}</td>
                                <td className="px-4 py-3 text-gray-700">{share.expires_at || '-'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );

    const AnalyticsView = () => (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard title="Total Documents" value={analytics?.total_documents || 0} />
                <StatCard title="Latest Versions" value={analytics?.latest_versions || 0} />
                <StatCard title="Recent Access Logs" value={(analytics?.recent_access || []).length} />
            </div>

            <div className="rounded-lg border border-gray-200 bg-white p-4">
                <h2 className="text-sm font-semibold text-gray-900">Access Actions</h2>
                <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {Object.entries(analytics?.access_actions || {}).map(([action, total]) => (
                        <div key={action} className="rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700">
                            <span className="font-medium capitalize">{action.replace('_', ' ')}</span>: {total}
                        </div>
                    ))}
                    {Object.keys(analytics?.access_actions || {}).length === 0 && (
                        <p className="text-sm text-gray-500">No analytics data yet.</p>
                    )}
                </div>
            </div>
        </div>
    );

    const CategoriesView = () => (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <h2 className="text-lg font-semibold text-gray-900">Categories</h2>
            <ul className="mt-3 space-y-2 text-sm text-gray-700">
                {categories.length === 0 && <li className="text-gray-500">No categories found.</li>}
                {categories.map((category) => (
                    <li key={category.id} className="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2">
                        <span>{category.name}</span>
                        <span className="text-xs text-gray-500">{category.documents_count || 0} docs</span>
                    </li>
                ))}
            </ul>
        </div>
    );

    const FoldersView = () => (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <h2 className="text-lg font-semibold text-gray-900">Folders</h2>
            <ul className="mt-3 space-y-2 text-sm text-gray-700">
                {folders.length === 0 && <li className="text-gray-500">No folders found.</li>}
                {folders.map((folder) => (
                    <li key={folder.id} className="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2">
                        <span>{folder.name}</span>
                        <span className="text-xs text-gray-500">{folder.documents_count || 0} docs</span>
                    </li>
                ))}
            </ul>
        </div>
    );

    const AccessControlView = () => (
        <form onSubmit={submitAccess} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
            <h2 className="text-lg font-semibold text-gray-900">Access Control</h2>
            <p className="text-sm text-gray-600">Assign simple permission lists to a document or folder.</p>

            <div className="grid gap-3 sm:grid-cols-2">
                <select
                    value={accessForm.data.document_id}
                    onChange={(event) => accessForm.setData('document_id', event.target.value)}
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                >
                    <option value="">Select document</option>
                    {(documents || []).map((item) => (
                        <option key={item.id} value={item.id}>{item.title}</option>
                    ))}
                </select>

                <select
                    value={accessForm.data.folder_id}
                    onChange={(event) => accessForm.setData('folder_id', event.target.value)}
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                >
                    <option value="">Select folder</option>
                    {folders.map((folder) => (
                        <option key={folder.id} value={folder.id}>{folder.name}</option>
                    ))}
                </select>
            </div>

            <label className="block text-sm font-medium text-gray-700">Permission Users</label>
            <div className="grid max-h-48 gap-2 overflow-y-auto rounded-md border border-gray-200 p-3 sm:grid-cols-2">
                {users.map((user) => {
                    const checked = accessForm.data.access_permissions.includes(user.id);
                    return (
                        <label key={user.id} className="flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                checked={checked}
                                onChange={(event) => {
                                    if (event.target.checked) {
                                        accessForm.setData('access_permissions', [...accessForm.data.access_permissions, user.id]);
                                    } else {
                                        accessForm.setData('access_permissions', accessForm.data.access_permissions.filter((id) => id !== user.id));
                                    }
                                }}
                            />
                            {user.name}
                        </label>
                    );
                })}
            </div>

            <button type="submit" disabled={accessForm.processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                Update Access
            </button>
        </form>
    );

    return (
        <App>
            <Head title="Document Management" />

            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">Document Management System</h1>
                        <p className="text-sm text-gray-600">Manage documents, sharing, and access controls.</p>
                    </div>
                </div>

                {page === 'dashboard' && <DashboardView />}
                {page === 'documents' && <DocumentsView />}
                {page === 'create' && <CreateView />}
                {page === 'show' && <ShowView />}
                {page === 'shared' && <SharedView />}
                {page === 'analytics' && <AnalyticsView />}
                {page === 'categories' && <CategoriesView />}
                {page === 'folders' && <FoldersView />}
                {page === 'access-control' && <AccessControlView />}
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

function Info({ label, value }) {
    return (
        <div>
            <p className="text-xs uppercase tracking-wide text-gray-500">{label}</p>
            <p className="mt-1 text-sm font-medium text-gray-900">{value}</p>
        </div>
    );
}
