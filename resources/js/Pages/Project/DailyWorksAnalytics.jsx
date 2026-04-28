import React, { useState, useEffect } from 'react';
import { Head, usePage } from "@inertiajs/react";
import { motion } from 'framer-motion';
import { 
    Tabs,
    Tab,
    Card,
    CardHeader,
    CardBody,
    Button
} from "@heroui/react";
import { 
    ChartBarIcon,
    TableCellsIcon,
    PresentationChartBarIcon,
    PresentationChartLineIcon,
    DocumentArrowDownIcon
} from "@heroicons/react/24/outline";
import App from "@/Layouts/App.jsx";
import AnalyticsOverviewTab from "@/Components/Analytics/AnalyticsOverviewTab.jsx";
import AnalyticsSummaryTab from "@/Components/Analytics/AnalyticsSummaryTab.jsx";
import AnalyticsVisualizationsTab from "@/Components/Analytics/AnalyticsVisualizationsTab.jsx";

const DailyWorksAnalytics = ({ title }) => {
    const { auth } = usePage().props;
    const [activeTab, setActiveTab] = useState('overview');

    // Helper function to convert theme borderRadius to HeroUI radius values
    const getThemeRadius = () => {
        if (typeof window === 'undefined') return 'lg';
        const rootStyles = getComputedStyle(document.documentElement);
        const borderRadius = rootStyles.getPropertyValue('--borderRadius')?.trim() || '12px';
        const radiusValue = parseInt(borderRadius);
        if (radiusValue === 0) return 'none';
        if (radiusValue <= 4) return 'sm';
        if (radiusValue <= 8) return 'md';
        if (radiusValue <= 16) return 'lg';
        return 'full';
    };

    // Custom media queries
    const [isMobile, setIsMobile] = useState(false);
    const [isTablet, setIsTablet] = useState(false);
    
    useEffect(() => {
        const checkScreenSize = () => {
            setIsMobile(window.innerWidth < 640);
            setIsTablet(window.innerWidth < 768);
        };
        checkScreenSize();
        window.addEventListener('resize', checkScreenSize);
        return () => window.removeEventListener('resize', checkScreenSize);
    }, []);

    const tabs = [
        {
            id: 'overview',
            title: 'Dashboard Overview',
            icon: <ChartBarIcon className="w-4 h-4" />,
            content: <AnalyticsOverviewTab auth={auth} />
        },
        {
            id: 'summary',
            title: 'Summary Table',
            icon: <TableCellsIcon className="w-4 h-4" />,
            content: <AnalyticsSummaryTab auth={auth} />
        },
        {
            id: 'visualizations',
            title: 'Visualizations',
            icon: <PresentationChartBarIcon className="w-4 h-4" />,
            content: <AnalyticsVisualizationsTab auth={auth} />
        }
    ];

    return (
        <>
            <Head title={title || 'Daily Works Analytics'} />
            
            <div 
                className="flex flex-col w-full h-full p-4"
                role="main"
                aria-label="Daily Works Analytics"
            >
                <div className="space-y-4">
                    <div className="w-full">
                        <motion.div
                            initial={{ scale: 0.9, opacity: 0 }}
                            animate={{ scale: 1, opacity: 1 }}
                            transition={{ duration: 0.5 }}
                        >
                            <Card 
                                className="transition-all duration-200"
                                style={{
                                    border: `var(--borderWidth, 2px) solid transparent`,
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                    transform: `scale(var(--scale, 1))`,
                                    background: `linear-gradient(135deg, 
                                        var(--theme-content1, #FAFAFA) 20%, 
                                        var(--theme-content2, #F4F4F5) 10%, 
                                        var(--theme-content3, #F1F3F4) 20%)`,
                                }}
                            >
                                <CardHeader 
                                    className="border-b p-0"
                                    style={{
                                        borderColor: `var(--theme-divider, #E4E4E7)`,
                                        background: `linear-gradient(135deg, 
                                            color-mix(in srgb, var(--theme-content1) 50%, transparent) 20%, 
                                            color-mix(in srgb, var(--theme-content2) 30%, transparent) 10%)`,
                                    }}
                                >
                                    <div className={`${!isMobile ? 'p-6' : 'p-4'} w-full`}>
                                        <div className="flex flex-col space-y-4">
                                            {/* Main Header Content */}
                                            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                                {/* Title Section */}
                                                <div className="flex items-center gap-3 lg:gap-4">
                                                    <div 
                                                        className={`
                                                            ${!isMobile ? 'p-3' : 'p-2'} 
                                                            rounded-xl flex items-center justify-center
                                                        `}
                                                        style={{
                                                            background: `color-mix(in srgb, var(--theme-primary) 15%, transparent)`,
                                                            borderColor: `color-mix(in srgb, var(--theme-primary) 25%, transparent)`,
                                                            borderWidth: `var(--borderWidth, 2px)`,
                                                            borderRadius: `var(--borderRadius, 12px)`,
                                                        }}
                                                    >
                                                        <PresentationChartLineIcon 
                                                            className={`
                                                                ${!isMobile ? 'w-8 h-8' : 'w-6 h-6'}
                                                            `}
                                                            style={{ color: 'var(--theme-primary)' }}
                                                        />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <h4 
                                                            className={`
                                                                ${!isMobile ? 'text-2xl' : 'text-xl'}
                                                                font-bold text-foreground
                                                                ${isMobile ? 'truncate' : ''}
                                                            `}
                                                            style={{
                                                                fontFamily: `var(--fontFamily, "Inter")`,
                                                            }}
                                                        >
                                                            Daily Works Analytics
                                                        </h4>
                                                        <p 
                                                            className={`
                                                                ${!isMobile ? 'text-sm' : 'text-xs'} 
                                                                text-default-500
                                                                ${isMobile ? 'truncate' : ''}
                                                            `}
                                                            style={{
                                                                fontFamily: `var(--fontFamily, "Inter")`,
                                                            }}
                                                        >
                                                            Comprehensive analytics and reporting for daily works
                                                        </p>
                                                    </div>
                                                </div>
                                                
                                                {/* Action Buttons */}
                                                <div className="flex gap-2 flex-wrap">
                                                    <Button
                                                        color="default"
                                                        variant="bordered"
                                                        startContent={<DocumentArrowDownIcon className="w-4 h-4" />}
                                                        size={isMobile ? "sm" : "md"}
                                                        className="font-semibold"
                                                        style={{
                                                            borderRadius: `var(--borderRadius, 8px)`,
                                                            fontFamily: `var(--fontFamily, "Inter")`,
                                                        }}
                                                    >
                                                        Export
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </CardHeader>

                                <CardBody className="p-0">
                                    {/* Tabs Navigation */}
                                    <div 
                                        className="px-6 pt-4"
                                        style={{
                                            borderBottom: `1px solid var(--theme-divider, #E4E4E7)`,
                                        }}
                                    >
                                        <Tabs
                                            selectedKey={activeTab}
                                            onSelectionChange={setActiveTab}
                                            variant="underlined"
                                            radius={getThemeRadius()}
                                            classNames={{
                                                tabList: "gap-6 w-full relative rounded-none p-0 border-b-0",
                                                cursor: "w-full bg-primary",
                                                tab: "max-w-fit px-0 h-12",
                                                tabContent: "group-data-[selected=true]:font-semibold"
                                            }}
                                        >
                                            {tabs.map((tab) => (
                                                <Tab
                                                    key={tab.id}
                                                    title={
                                                        <div className="flex items-center gap-2" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                                                            {tab.icon}
                                                            <span>{tab.title}</span>
                                                        </div>
                                                    }
                                                />
                                            ))}
                                        </Tabs>
                                    </div>

                                    {/* Tab Content */}
                                    <div className="p-6">
                                        <motion.div
                                            key={activeTab}
                                            initial={{ opacity: 0, x: 20 }}
                                            animate={{ opacity: 1, x: 0 }}
                                            exit={{ opacity: 0, x: -20 }}
                                            transition={{ duration: 0.2 }}
                                        >
                                            {tabs.find(tab => tab.id === activeTab)?.content}
                                        </motion.div>
                                    </div>
                                </CardBody>
                            </Card>
                        </motion.div>
                    </div>
                </div>
            </div>
        </>
    );
};

DailyWorksAnalytics.layout = (page) => <App children={page} />;

export default DailyWorksAnalytics;

