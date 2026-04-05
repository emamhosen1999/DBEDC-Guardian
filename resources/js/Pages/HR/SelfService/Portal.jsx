import React from 'react';
import { Head, Link } from '@inertiajs/react';
import App from '@/Layouts/App.jsx';

export default function SelfServicePortal({ employee, stats }) {
    return (
        <App>
            <Head title="Self-Service Portal" />
            <div className="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">Welcome, {employee?.name}</h1>
                    <p className="text-sm text-gray-600">Manage your profile and access company resources.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <StatCard title="Balance Days Off" value={stats?.daysOffBalance || 0} icon="📅" />
                    <StatCard title="Upcoming Trainings" value={stats?.upcomingTrainings || 0} icon="📚" />
                    <StatCard title="Benefits Enrolled" value={stats?.benefitsEnrolled || 0} icon="💼" />
                    <StatCard title="Documents" value={stats?.documents || 0} icon="📄" />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <PortalLink
                        title="My Profile"
                        description="View and update your profile"
                        icon="👤"
                        href={route('hr.selfservice.profile')}
                    />
                    <PortalLink
                        title="My Documents"
                        description="Access personal documents"
                        icon="📁"
                        href={route('hr.selfservice.documents')}
                    />
                    <PortalLink
                        title="Benefits"
                        description="View and manage benefits"
                        icon="🏥"
                        href={route('hr.selfservice.benefits')}
                    />
                    <PortalLink
                        title="Time Off"
                        description="Request and track time off"
                        icon="✈️"
                        href={route('hr.selfservice.timeoff')}
                    />
                    <PortalLink
                        title="Trainings"
                        description="View available trainings"
                        icon="🎓"
                        href={route('hr.selfservice.trainings')}
                    />
                    <PortalLink
                        title="Payslips"
                        description="Access your payslips"
                        icon="💰"
                        href={route('hr.selfservice.payslips')}
                    />
                    <PortalLink
                        title="Performance"
                        description="View your performance"
                        icon="📊"
                        href={route('hr.selfservice.performance')}
                    />
                    <PortalLink
                        title="Support"
                        description="Get help from HR"
                        icon="🆘"
                        href={route('hr.selfservice.index')}
                    />
                </div>

                {stats?.announcements && stats.announcements.length > 0 && (
                    <div className="rounded-lg border border-gray-200 bg-white p-4 sm:p-6">
                        <h3 className="mb-4 text-sm font-medium text-gray-900">Recent Announcements</h3>
                        <div className="space-y-3">
                            {stats.announcements.map((announcement, idx) => (
                                <div key={idx} className="border-l-4 border-blue-600 bg-blue-50 p-3">
                                    <p className="text-sm font-medium text-gray-900">{announcement.title}</p>
                                    <p className="text-xs text-gray-600">{announcement.date}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </App>
    );
}

function StatCard({ title, value, icon }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-xs uppercase tracking-wide text-gray-500">{title}</p>
                    <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
                </div>
                <span className="text-3xl">{icon}</span>
            </div>
        </div>
    );
}

function PortalLink({ title, description, icon, href }) {
    return (
        <Link href={href} className="rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-300 hover:bg-blue-50 transition">
            <div className="text-2xl mb-2">{icon}</div>
            <p className="font-medium text-gray-900">{title}</p>
            <p className="text-xs text-gray-600">{description}</p>
        </Link>
    );
}
