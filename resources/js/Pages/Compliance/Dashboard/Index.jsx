import React from 'react';
import { Head } from '@inertiajs/react';
import App from '@/Layouts/App';
import { GlassContainer, GlassCard } from '@/Components/Nebula/index';

export default function ComplianceDashboard({ stats, recentActivities, upcomingDeadlines, criticalIssues }) {
    return (
        <App>
            <Head title="Compliance Dashboard" />
            <GlassContainer perspective="mid">
                <div className="py-12 px-6">
                    <div className="max-w-7xl mx-auto">
                        <GlassCard>
                            <div className="p-6">
                                <h1 className="text-2xl font-bold">Compliance Overview</h1>
                                <p className="mt-2 opacity-70">Monitor your organizational compliance status</p>
                            </div>
                        </GlassCard>
                    </div>
                </div>
            </GlassContainer>
        </App>
    );
}
