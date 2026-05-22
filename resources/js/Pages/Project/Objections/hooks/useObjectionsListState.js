import { useState, useEffect, useCallback, useRef } from 'react';
import { router } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';

/**
 * List, filter, pagination, and refresh behavior for the objections index page.
 */
export function useObjectionsListState({ initialObjections, initialFilters, isMobile }) {
    const [loading, setLoading] = useState(false);
    const [tableLoading, setTableLoading] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [search, setSearch] = useState(initialFilters?.search || '');
    const [currentPage, setCurrentPage] = useState(initialObjections?.current_page || 1);
    const [expandedItems, setExpandedItems] = useState(new Set());
    const [objections, setObjections] = useState(initialObjections);
    const [showFilters, setShowFilters] = useState(false);
    const [filterData, setFilterData] = useState({
        status: initialFilters?.status || 'all',
        category: initialFilters?.category || 'all',
        creator: initialFilters?.creator || '',
    });

    const abortControllerRef = useRef(null);
    const searchTimeoutRef = useRef(null);

    useEffect(() => {
        setObjections(initialObjections);
    }, [initialObjections]);

    const cancelPendingRequest = useCallback(() => {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }
        abortControllerRef.current = new AbortController();
        return abortControllerRef.current.signal;
    }, []);

    const buildFilterParams = useCallback(() => {
        const params = { page: currentPage };
        if (search) params.search = search;
        if (filterData.status && filterData.status !== 'all') params.status = filterData.status;
        if (filterData.category && filterData.category !== 'all') params.category = filterData.category;
        if (filterData.creator) params.creator = filterData.creator;
        return params;
    }, [search, filterData, currentPage]);

    const fetchData = useCallback((showLoader = true) => {
        if (showLoader) setTableLoading(true);

        router.get(route('objections.index'), buildFilterParams(), {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                setTableLoading(false);
                setIsRefreshing(false);
            },
        });
    }, [buildFilterParams]);

    const refreshData = useCallback(() => {
        setCurrentPage(1);
        fetchData();
    }, [fetchData]);

    const handlePullToRefresh = useCallback(async () => {
        if (isRefreshing || !isMobile) return;
        setIsRefreshing(true);
        refreshData();
        showToast.success('Data refreshed');
    }, [isRefreshing, isMobile, refreshData]);

    const handleSearch = useCallback((event) => {
        const value = event.target.value;
        setSearch(value);
        setCurrentPage(1);

        if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
        searchTimeoutRef.current = setTimeout(() => fetchData(), 300);
    }, [fetchData]);

    const handleFilterChange = useCallback((key, value) => {
        setFilterData(prev => ({ ...prev, [key]: value }));
        setCurrentPage(1);
    }, []);

    const handlePageChange = useCallback((page) => {
        setCurrentPage(page);
    }, []);

    const toggleExpanded = useCallback((id) => {
        setExpandedItems(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }, []);

    return {
        loading,
        setLoading,
        tableLoading,
        isRefreshing,
        search,
        currentPage,
        setCurrentPage,
        expandedItems,
        objections,
        setObjections,
        showFilters,
        setShowFilters,
        filterData,
        setFilterData,
        cancelPendingRequest,
        fetchData,
        refreshData,
        handlePullToRefresh,
        handleSearch,
        handleFilterChange,
        handlePageChange,
        toggleExpanded,
    };
}
