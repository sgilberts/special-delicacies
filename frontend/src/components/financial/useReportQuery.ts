import { useCallback, useEffect, useState } from "react";
import api from "@/lib/api";

export type ReportMeta = {
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
  date_basis?: string;
  summary?: Record<string, unknown>;
};

export type ReportRow = Record<string, any>;

/**
 * Loads one page of a report from the backend. The backend is the only place a
 * financial figure is calculated; this hook never derives totals itself.
 */
export function useReportQuery(endpoint: string, params: Record<string, string | number | boolean | undefined>, page: number) {
  const [rows, setRows] = useState<ReportRow[]>([]);
  const [meta, setMeta] = useState<ReportMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const paramsKey = JSON.stringify(params);

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await api.get(endpoint, { params: { ...JSON.parse(paramsKey), page, per_page: 25 } });
      setRows((response.data?.data ?? []) as ReportRow[]);
      setMeta((response.data?.meta ?? null) as ReportMeta | null);
    } catch (cause) {
      const status = (cause as { response?: { status?: number } }).response?.status;
      setError(status === 403
        ? "Your account does not have permission to view this report."
        : "This report could not be loaded. Check the connection and retry.");
      setRows([]);
      setMeta(null);
    } finally {
      setLoading(false);
    }
  }, [endpoint, paramsKey, page]);

  useEffect(() => {
    void load();
  }, [load]);

  return { rows, meta, loading, error, reload: load };
}

export async function downloadCsv(path: string, params: Record<string, string | number | boolean | undefined>) {
  const response = await api.get(path, { params, responseType: "blob" });
  const url = URL.createObjectURL(response.data as Blob);
  const link = document.createElement("a");
  const disposition = String(response.headers?.["content-disposition"] ?? "");
  const match = disposition.match(/filename="?([^";]+)"?/);
  link.href = url;
  link.download = match?.[1] ?? "financial-report.csv";
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
