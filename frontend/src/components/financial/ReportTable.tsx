import type { ReactNode } from "react";

export type ReportColumn = {
  key: string;
  label: string;
  right?: boolean;
  sortKey?: string;
  render: (row: Record<string, any>) => ReactNode;
};

export type ReportTableProps = {
  columns: ReportColumn[];
  rows: Record<string, any>[];
  loading: boolean;
  error: string;
  onRetry: () => void;
  sort?: string;
  direction?: "asc" | "desc";
  onSort?: (key: string) => void;
  emptyMessage: string;
};

export default function ReportTable({ columns, rows, loading, error, onRetry, sort, direction, onSort, emptyMessage }: ReportTableProps) {
  if (error) {
    return (
      <div role="alert" className="rounded-xl bg-[var(--color-danger)]/10 p-4 text-sm text-[var(--color-danger)]">
        <p>{error}</p>
        <button type="button" onClick={onRetry} className="mt-2 min-h-9 rounded-lg border px-3 font-semibold" style={{ borderColor: "var(--color-border)" }}>
          Retry
        </button>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[900px] text-left text-sm">
        <thead className="text-[var(--color-text-secondary)]">
          <tr>
            {columns.map((column) => {
              const active = column.sortKey && sort === column.sortKey;
              return (
                <th key={column.key} className={`p-2 ${column.right ? "text-right" : ""}`} scope="col">
                  {column.sortKey && onSort ? (
                    <button
                      type="button"
                      onClick={() => onSort(column.sortKey as string)}
                      className={`inline-flex items-center gap-1 font-semibold ${active ? "text-[var(--color-accent)]" : ""}`}
                      aria-label={`Sort by ${column.label}`}
                    >
                      {column.label}
                      <span aria-hidden>{active ? (direction === "asc" ? "▲" : "▼") : "↕"}</span>
                    </button>
                  ) : column.label}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {loading && (
            <tr>
              <td colSpan={columns.length} className="p-6 text-center text-[var(--color-text-secondary)]">Loading report…</td>
            </tr>
          )}
          {!loading && rows.length === 0 && (
            <tr>
              <td colSpan={columns.length} className="p-6 text-center text-[var(--color-text-secondary)]">{emptyMessage}</td>
            </tr>
          )}
          {!loading && rows.map((row, index) => (
            <tr key={`${row.id ?? index}-${index}`} className="border-t align-top" style={{ borderColor: "var(--color-border)" }}>
              {columns.map((column) => (
                <td key={column.key} className={`p-2 ${column.right ? "text-right tabular-nums" : ""}`}>
                  {column.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
