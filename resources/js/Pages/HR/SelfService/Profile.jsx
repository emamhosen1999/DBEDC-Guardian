import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function SelfServiceProfile({ employee }) {
    const { data, setData, put, processing, errors } = useForm({
        first_name: employee.first_name || '',
        last_name: employee.last_name || '',
        email: employee.email || '',
        phone: employee.phone || '',
        address: employee.address || '',
        city: employee.city || '',
        state: employee.state || '',
        zipcode: employee.zipcode || '',
        emergency_contact_name: employee.emergency_contact_name || '',
        emergency_contact_phone: employee.emergency_contact_phone || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('hr.selfservice.profile.update'));
    };

    return (
        <App>
            <Head title="My Profile" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">My Profile</h1>
                    <p className="text-sm text-gray-600">Update your personal information.</p>
                </div>

                <form onSubmit={submit} className="space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">First Name</label>
                            <input type="text" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.first_name && <p className="mt-1 text-xs text-red-600">{errors.first_name}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Last Name</label>
                            <input type="text" value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                            {errors.last_name && <p className="mt-1 text-xs text-red-600">{errors.last_name}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" disabled />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Phone</label>
                            <input type="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-gray-700">Address</label>
                            <input type="text" value={data.address} onChange={(e) => setData('address', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">City</label>
                            <input type="text" value={data.city} onChange={(e) => setData('city', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">State</label>
                            <input type="text" value={data.state} onChange={(e) => setData('state', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Zipcode</label>
                            <input type="text" value={data.zipcode} onChange={(e) => setData('zipcode', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div className="sm:col-span-2">
                            <p className="mb-3 text-sm font-medium text-gray-700">Emergency Contact</p>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Contact Name</label>
                            <input type="text" value={data.emergency_contact_name} onChange={(e) => setData('emergency_contact_name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Contact Phone</label>
                            <input type="tel" value={data.emergency_contact_phone} onChange={(e) => setData('emergency_contact_phone', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <button type="submit" disabled={processing} className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            Save Changes
                        </button>
                        <Link href={route('hr.selfservice.index')} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Cancel
                        </Link>
                    </div>
                </form>
            </div>
        </App>
    );
}
