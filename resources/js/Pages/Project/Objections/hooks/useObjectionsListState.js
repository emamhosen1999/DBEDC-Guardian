import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { showToast } from '@/utils/toastUtils';
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';
import { useQueryFilters, useClampPage } from '@/Hooks/useQueryFilters';

/**
 * List, filter, pagination and refresh behaviour for the objections index page.
 *
 * Search, the status/category/creator filters and the page number are server
 * state, so they live in the URL and the controller reads them straight back —
 * a refresh or a copied link reproduces the same list. Only the two genuinely
 * presentational bits (whether the filter bar is open, which rows are expanded)
 * are remembered across navigation.
 *
 * This replaces an older arrangement that kept the same values in local state
 * and pushed them to the server from a `useEffect` watching `[filterData,
 * currentPage]`. That effect also ran on mount, so every visit to the page
 * issued a second, redundant request — and typing in the search box issued two.
 */
export function useObjectionsListState({ initialObjections, isMobile }) {
    const [loading, setLoading] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [objections, setObjections] = useState(initialObjections);

    /* ── server-driven state: the URL is the source of truth ── */
    const f = useQueryFilters({
        routeName: 'objections.index',
        defaults: {
            search: '',
            status: 'all',
            category: 'all',
            creator: '',
            page: 1,
        },
    });

    /* ── presentation-only state, remembered across navigation ── */
    const [ui, setUi] = usePersistentPageState('Objections/Index', {
        showFilters: false,
        expandedItems: [],
    });

    const showFilters = ui.showFilters;
    const setShowFilters = useCallback(
        (val) => setUi((prev) => ({ showFilters: typeof val === 'function' ? val(prev.showFilters) : val })),
        [setUi],
    );

    // Only row ids are remembered — never the row objects themselves.
    const expandedItems = useMemo(() => new Set(ui.expandedItems), [ui.expandedItems]);

    const abortControllerRef = useRef(null);
    useClampPage(f, objections?.last_page);

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

    /** Re-fetch the current URL without changing it. */
    const fetchData = useCallback(() => {
        router.reload({
            onFinish: () => setIsRefreshing(false),
        });
    }, []);

    const refreshData = useCallback(() => fetchData(), [fetchData]);

    const handlePullToRefresh = useCallback(async () => {
        if (isRefreshing || !isMobile) return;
        setIsRefreshing(true);
        refreshData();
        showToast.success('Data refreshed');
    }, [isRefreshing, isMobile, refreshData]);

    /* ── filter/search/pagination handlers — each writes to the URL once ── */

    const handleSearch = useCallback((event) => f.setDraft('search', event.target.value), [f.setDraft]);

    const handleFilterChange = useCallback((key, value) => f.set(key, value), [f.set]);

    const handlePageChange = useCallback((page) => f.setPage(page), [f.setPage]);

    const filterData = useMemo(
        () => ({ status: f.values.status, category: f.values.category, creator: f.values.creator }),
        [f.values.status, f.values.category, f.values.creator],
    );

    const setFilterData = useCallback((next) => f.setMany(next), [f.setMany]);

    /** Clear search and filters. Leaves the remembered filter-bar/expanded state alone. */
    const resetFilters = useCallback(() => f.reset(), [f.reset]);

    const toggleExpanded = useCallback(
        (id) => {
            setUi((prev) => {
                const current = new Set(prev.expandedItems || []);
                if (current.has(id)) current.delete(id);
                else current.add(id);
                return { expandedItems: [...current] };
            });
        },
        [setUi],
    );

    return {
        loading,
        setLoading,
        /** True while a filter/search/page visit is in flight. */
        tableLoading: f.loading,
        isRefreshing,
        search: f.draft.search,
        currentPage: f.values.page,
        setCurrentPage: handlePageChange,
        expandedItems,
        objections,
        setObjections,
        showFilters,
        setShowFilters,
        filterData,
        setFilterData,
        resetFilters,
        isFiltered: f.isFiltered,
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
