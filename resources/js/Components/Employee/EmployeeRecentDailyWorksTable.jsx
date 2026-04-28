import React, { useState, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Table, TableHeader, TableColumn, TableBody, TableRow, TableCell, Chip, Button, Spinner } from '@heroui/react';
import { 
    DocumentTextIcon,
    ChevronLeftIcon,
    ChevronRightIcon
} from '@heroicons/react/24/outline';
import axios from 'axios';
import { route } from 'ziggy-js';
import { router } from '@inertiajs/react';

// Helper function to get theme radius
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

// Get theme-aware card style
const getCardStyle = () => ({
    background: `linear-gradient(135deg, 
        var(--theme-content1, #FAFAFA) 20%, 
        var(--theme-content2, #F4F4F5) 10%, 
        var(--theme-content3, #F1F3F4) 20%)`,
    borderColor: `transparent`,
    borderWidth: `var(--borderWidth, 2px)`,
    borderRadius: `var(--borderRadius, 12px)`,
    fontFamily: `var(--fontFamily, "Inter")`,
    transform: `scale(var(--scale, 1))`,
    transition: 'all 0.2s ease-in-out',
    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
});

const EmployeeRecentDailyWorksTable = ({ auth }) => {
    const [dailyWorks, setDailyWorks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [total, setTotal] = useState(0);
    const perPage = 10;

    const fetchDailyWorks = useCallback(async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('dailyWorks.paginate'), {
                params: { 
                    page,
                    perPage,
                    search: '',
                    // Filter for current user's works
                    assigned: auth.user?.id,
                    incharge: auth.user?.id
                }
            });
            
            const data = response.data?.data || [];
            setDailyWorks(Array.isArray(data) ? data : []);
            setTotal(response.data?.total || 0);
        } catch (error) {
            console.error('Error fetching daily works:', error);
            setDailyWorks([]);
        } finally {
            setLoading(false);
        }
    }, [page, auth.user?.id]);

    React.useEffect(() => {
        fetchDailyWorks();
    }, [fetchDailyWorks]);

    const getStatusColor = (status) => {
        switch (status?.toLowerCase()) {
            case 'completed':
                return 'success';
            case 'pending':
                return 'warning';
            case 'in-progress':
                return 'primary';
            default:
                return 'default';
        }
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const totalPages = Math.ceil(total / perPage);

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.4 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                className="h-full"
                onMouseEnter={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-primary) 50%, transparent)`;
                    e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                    e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
                }}
                onMouseLeave={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                    e.currentTarget.style.transform = `scale(var(--scale, 1))`;
                }}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <div className="flex items-center justify-between w-full">
                        <div className="flex items-center gap-3">
                            <div 
                                className="p-2 rounded-lg"
                                style={{ background: `color-mix(in srgb, var(--theme-primary, #006FEE) 10%, transparent)` }}
                            >
                                <DocumentTextIcon className="w-5 h-5" style={{ color: `var(--theme-primary, #006FEE)` }} />
                            </div>
                            <div>
                                <h3 className="text-lg font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Recent Daily Works
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Your task history
                                </p>
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="flat"
                            color="primary"
                            onPress={() => router.visit(route('daily-works'))}
                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                        >
                            View All
                        </Button>
                    </div>
                </CardHeader>
                <CardBody className="pt-0">
                    {loading ? (
                        <div className="flex justify-center items-center py-12">
                            <Spinner color="primary" />
                        </div>
                    ) : dailyWorks.length === 0 ? (
                        <div className="text-center py-12">
                            <DocumentTextIcon className="w-16 h-16 mx-auto mb-3" style={{ color: `var(--theme-foreground-400, #A1A1AA)` }} />
                            <p className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                No daily works found
                            </p>
                        </div>
                    ) : (
                        <>
                            <Table
                                aria-label="Daily works table"
                                removeWrapper
                                style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                            >
                                <TableHeader style={{ background: `var(--theme-content2, #F4F4F5)` }}>
                                    <TableColumn style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>DATE</TableColumn>
                                    <TableColumn style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>PROJECT</TableColumn>
                                    <TableColumn style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>TASK</TableColumn>
                                    <TableColumn style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>STATUS</TableColumn>
                                    <TableColumn style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>HOURS</TableColumn>
                                </TableHeader>
                                <TableBody>
                                    {dailyWorks.map((work) => (
                                        <TableRow key={work.id} style={{ borderBottom: `1px solid var(--theme-divider, #E4E4E7)` }}>
                                            <TableCell style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                                {formatDate(work.date)}
                                            </TableCell>
                                            <TableCell style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                                {work.project_name || 'N/A'}
                                            </TableCell>
                                            <TableCell style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                                {work.task_name || work.name || 'N/A'}
                                            </TableCell>
                                            <TableCell>
                                                <Chip
                                                    size="sm"
                                                    variant="flat"
                                                    color={getStatusColor(work.status)}
                                                    style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                                >
                                                    {work.status || 'Unknown'}
                                                </Chip>
                                            </TableCell>
                                            <TableCell style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                                {work.hours || 'N/A'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            
                            {totalPages > 1 && (
                                <div className="flex items-center justify-between mt-4 pt-4" style={{ borderTop: `1px solid var(--theme-divider, #E4E4E7)` }}>
                                    <span className="text-sm" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        Showing {((page - 1) * perPage) + 1} to {Math.min(page * perPage, total)} of {total}
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            isDisabled={page === 1}
                                            size="sm"
                                            variant="flat"
                                            onPress={() => setPage(p => Math.max(1, p - 1))}
                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                        >
                                            <ChevronLeftIcon className="w-4 h-4" />
                                        </Button>
                                        <Button
                                            isDisabled={page === totalPages}
                                            size="sm"
                                            variant="flat"
                                            onPress={() => setPage(p => Math.min(totalPages, p + 1))}
                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                        >
                                            <ChevronRightIcon className="w-4 h-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default EmployeeRecentDailyWorksTable;
