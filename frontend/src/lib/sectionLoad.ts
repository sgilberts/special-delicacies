export type SectionLoadState = "loading" | "ready" | "forbidden" | "error";

/** HTTP status of a rejected API call, when the failure carries a response. */
export function httpStatusOf(cause: unknown): number | undefined {
  const status = (cause as { response?: { status?: unknown } } | null | undefined)?.response?.status;
  return typeof status === "number" ? status : undefined;
}

/**
 * A section that answers 401/403 is an access decision, not a broken page:
 * the user must see an access message, never a generic load failure. Anything
 * else (5xx, network error, malformed response) is a genuine section failure.
 */
export function sectionFailureState(cause: unknown): SectionLoadState {
  const status = httpStatusOf(cause);
  return status === 401 || status === 403 ? "forbidden" : "error";
}

/** Empty-state copy is only truthful once the section actually loaded. */
export function showsEmptyState(state: SectionLoadState): boolean {
  return state === "ready";
}

/**
 * The page-level "could not be loaded" message is reserved for a genuine
 * outage: every requested section failed and at least one failed for a
 * server/network reason. A page where every section was denied shows access
 * messages instead, and a page with any loaded section never shows it.
 */
export function globalLoadErrorVisible(outcomes: Array<{ requested: boolean; state: SectionLoadState }>): boolean {
  const requested = outcomes.filter((outcome) => outcome.requested);
  if (requested.length === 0) return false;
  const failed = requested.filter((outcome) => outcome.state === "error" || outcome.state === "forbidden");
  return failed.length === requested.length && failed.some((outcome) => outcome.state === "error");
}
