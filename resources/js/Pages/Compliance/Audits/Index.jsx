import React from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App';

export default function AuditsIndex({ audits }) {
    return (
        <App>
            <Head title="Audits" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 bg-white border-b border-gray-200">
                            <h1 className="text-2xl font-bold text-gray-900">Compliance Audits</h1>
                            <p className="mt-2 text-gray-600">Manage and track compliance audits</p>
                        </div>
                    </div>
                </div>
            </div>
        </App>
    );
}
