import React from 'react';
import Icon from './Icon.jsx';

/** Page numbers shown around the current page (at most 5), as Cyber's .pagination-sm. */
export function pageWindow(current, last) {
    const size = Math.min(5, last);
    let start = Math.max(1, current - Math.floor(size / 2));
    start = Math.min(start, last - size + 1);
    return Array.from({ length: size }, (_, i) => start + i);
}

/**
 * Cyber .pagination (sm) with rows-per-page and a "from-to of total" line.
 * pagination = { currentPage, perPage, total }. Renders nothing for an empty list.
 */
export default function Pagination({ pagination, onPageChange, onRowsPerPageChange, loading = false, perPageOptions = [10, 20, 30, 50, 100], label = 'Pagination' }) {
    if (!pagination || pagination.total <= 0) return null;
    const { currentPage, perPage, total } = pagination;
    const last = Math.max(1, Math.ceil(total / perPage));
    const from = Math.min((currentPage - 1) * perPage + 1, total);
    const to = Math.min(currentPage * perPage, total);
    const options = perPageOptions.includes(perPage) ? perPageOptions : [...perPageOptions, perPage].sort((a, b) => a - b);
    const go = (page) => { if (!loading && page >= 1 && page <= last && page !== currentPage) onPageChange?.(page); };

    return (
        <nav className="cy-pagination" aria-label={label} aria-busy={loading || undefined}>
            {onRowsPerPageChange && (
                <label className="cy-pagination__rows">
                    Rows per page
                    <select className="cy-select" value={perPage} disabled={loading} onChange={(e) => onRowsPerPageChange(parseInt(e.target.value, 10))}>
                        {options.map((n) => <option key={n} value={n}>{n}</option>)}
                    </select>
                </label>
            )}
            <span className="cy-pagination__info" aria-live="polite">{from}–{to} of {total}</span>
            <ul className="cy-pagination__pages">
                <li><button type="button" className="cy-pagination__link" disabled={loading || currentPage <= 1} onClick={() => go(currentPage - 1)} aria-label="Previous page"><Icon name="chevron-left" /></button></li>
                {pageWindow(currentPage, last).map((page) => (
                    <li key={page}>
                        <button type="button" className="cy-pagination__link" disabled={loading} aria-current={page === currentPage ? 'page' : undefined} aria-label={`Page ${page}`} onClick={() => go(page)}>{page}</button>
                    </li>
                ))}
                <li><button type="button" className="cy-pagination__link" disabled={loading || currentPage >= last} onClick={() => go(currentPage + 1)} aria-label="Next page"><Icon name="chevron-right" /></button></li>
            </ul>
        </nav>
    );
}
