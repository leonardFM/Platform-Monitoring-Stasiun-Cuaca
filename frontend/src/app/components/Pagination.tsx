"use client";

interface PaginationProps {
  currentPage: number;
  totalPages: number;
  totalItems: number;
  perPage: number;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  perPageOptions?: number[];
}

export function Pagination({
  currentPage,
  totalPages,
  totalItems,
  perPage,
  onPageChange,
  onPerPageChange,
  perPageOptions = [10, 20, 50, 100],
}: PaginationProps) {
  if (totalPages <= 1) return null;

  const pages = getPageNumbers(currentPage, totalPages);

  return (
    <div className="pagination">
      <div className="pagination-info">
        Showing {(currentPage - 1) * perPage + 1}–{Math.min(currentPage * perPage, totalItems)} of {totalItems}
      </div>
      <div className="pagination-controls">
        {onPerPageChange && (
          <select
            value={perPage}
            onChange={(e) => onPerPageChange(Number(e.target.value))}
            className="per-page-select"
          >
            {perPageOptions.map((opt) => (
              <option key={opt} value={opt}>
                {opt} / page
              </option>
            ))}
          </select>
        )}
        <button
          onClick={() => onPageChange(1)}
          disabled={currentPage === 1}
          className="page-btn"
          aria-label="First page"
        >
          ««
        </button>
        <button
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage === 1}
          className="page-btn"
          aria-label="Previous page"
        >
          «
        </button>
        {pages.map((page, i) => (
          <button
            key={page}
            onClick={() => typeof page === "number" && onPageChange(page)}
            className={`page-btn ${page === currentPage ? "active" : ""}`}
            aria-current={page === currentPage ? "page" : undefined}
            disabled={page === "..."}
          >
            {page === "..." ? "…" : page}
          </button>
        ))}
        <button
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage === totalPages}
          className="page-btn"
          aria-label="Next page"
        >
          »
        </button>
        <button
          onClick={() => onPageChange(totalPages)}
          disabled={currentPage === totalPages}
          className="page-btn"
          aria-label="Last page"
        >
          »»
        </button>
      </div>
    </div>
  );
}

function getPageNumbers(current: number, total: number): (number | "...")[] {
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1);
  }
  if (current <= 4) {
    return [1, 2, 3, 4, 5, "...", total];
  }
  if (current >= total - 3) {
    return [1, "...", total - 4, total - 3, total - 2, total - 1, total];
  }
  return [1, "...", current - 1, current, current + 1, "...", total];
}