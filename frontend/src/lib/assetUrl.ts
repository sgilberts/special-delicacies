/** Resolve uploaded paths against the API host instead of the current web host. */
export function assetUrl(path?: string | null): string | null {
  if (!path) return null;
  const apiBase = import.meta.env.VITE_API_BASE_URL as string | undefined;
  const apiOrigin = apiBase ? new URL(apiBase, window.location.origin).origin : window.location.origin;

  if (/^(data:|blob:)/i.test(path)) return path;
  if (/^https?:/i.test(path)) {
    const parsed = new URL(path);
    // Files uploaded by Laravel belong to the API host even when a stored URL
    // was originally generated while APP_URL pointed at the frontend host.
    return parsed.pathname.startsWith("/storage/")
      ? `${apiOrigin}${parsed.pathname}${parsed.search}${parsed.hash}`
      : path;
  }

  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  return `${apiOrigin}${normalizedPath}`;
}

export function applyFavicon(path?: string | null): void {
  const href = assetUrl(path);
  if (!href) return;
  // Keep one authoritative icon link. The app shell's default favicon must not
  // take precedence over a favicon selected in Restaurant Settings.
  document.querySelectorAll('link[rel~="icon"]').forEach((existing) => existing.remove());
  const icon = document.createElement("link");
  icon.rel = "icon";
  const versionedUrl = new URL(href, window.location.origin);
  versionedUrl.searchParams.set("v", String(Date.now()));
  icon.href = versionedUrl.toString();
  const pathname = new URL(href, window.location.origin).pathname.toLowerCase();
  icon.type = pathname.endsWith(".png") ? "image/png" : pathname.endsWith(".svg") ? "image/svg+xml" : "image/x-icon";
  document.head.appendChild(icon);
}
