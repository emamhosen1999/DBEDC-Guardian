import React from 'react';

const NebulaPagination = ({ 
  currentPage = 1, 
  totalPages = 1, 
  onPageChange, 
  className = '', 
  ...props 
}) => {
  const pages = [];
  const showEllipsis = totalPages > 7;

  for (let i = 1; i <= totalPages; i++) {
    if (
      i === 1 ||
      i === totalPages ||
      (i >= currentPage - 1 && i <= currentPage + 1)
    ) {
      pages.push(i);
    } else if (
      (i === currentPage - 2 && i > 1) ||
      (i === currentPage + 2 && i < totalPages)
    ) {
      pages.push('...');
    }
  }

  return (
    <div className={`ng-pagination ${className}`} {...props}>
      <button
        className="page-btn"
        onClick={() => onPageChange && onPageChange(currentPage - 1)}
        disabled={currentPage === 1}
      >
        ←
      </button>
      {pages.map((page, index) => (
        <button
          key={index}
          className={`page-btn ${page === currentPage ? 'active' : ''}`}
          onClick={() => typeof page === 'number' && onPageChange && onPageChange(page)}
          disabled={page === '...'}
        >
          {page}
        </button>
      ))}
      <button
        className="page-btn"
        onClick={() => onPageChange && onPageChange(currentPage + 1)}
        disabled={currentPage === totalPages}
      >
        →
      </button>
    </div>
  );
};

export default NebulaPagination;
