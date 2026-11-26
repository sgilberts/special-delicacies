import axios from "axios";

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    Accept: "application/json",
  },
});

const getCache = new Map<string, { expiresAt: number; request: Promise<import("axios").AxiosResponse<unknown>> }>();

/** Reuse fresh public reads across routes and collapse concurrent identical requests. */
export function cachedGet<T>(url: string, ttlMs = 30_000, config?: import("axios").AxiosRequestConfig): Promise<import("axios").AxiosResponse<T>> {
  const token = localStorage.getItem("special_delicacies_token") || "public";
  const identity = localStorage.getItem("special_delicacies_user") || "";
  const params = config?.params ? JSON.stringify(config.params, Object.keys(config.params).sort()) : "";
  const key = `${token}:${identity}:${url}:${params}`;
  const cached = getCache.get(key);
  if (cached && cached.expiresAt > Date.now()) return cached.request as Promise<import("axios").AxiosResponse<T>>;

  const request = api.get<T>(url, config);
  getCache.set(key, { expiresAt: Date.now() + ttlMs, request: request as Promise<import("axios").AxiosResponse<unknown>> });
  request.catch(() => {
    if (getCache.get(key)?.request === request) getCache.delete(key);
  });
  return request;
}

export function invalidateCachedGets(): void {
  getCache.clear();
}

api.interceptors.request.use((config) => {
  const staffToken = localStorage.getItem("special_delicacies_token");
  const isOrderSubmission = config.method?.toLowerCase() === "post" && /\/orders\/?$/.test(config.url ?? "");
  const customerToken = isOrderSubmission ? localStorage.getItem("special_delicacies_customer_token") : null;
  const token = customerToken || staffToken;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  // If not FormData, default Content-Type to application/json
  if (!(config.data instanceof FormData) && !config.headers["Content-Type"]) {
    config.headers["Content-Type"] = "application/json";
  }

  return config;
});

api.interceptors.response.use((response) => {
  if (response.config.method && response.config.method.toLowerCase() !== "get") invalidateCachedGets();
  return response;
});

export default api;
