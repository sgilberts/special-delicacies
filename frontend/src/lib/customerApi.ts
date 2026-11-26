import axios from "axios";

const customerApi = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  headers: { Accept: "application/json" },
});

customerApi.interceptors.request.use((config) => {
  const token = sessionStorage.getItem("special_delicacies_customer_token") || localStorage.getItem("special_delicacies_customer_token");
  localStorage.removeItem("special_delicacies_customer_token");
  if (token) config.headers.Authorization = `Bearer ${token}`;
  if (!(config.data instanceof FormData) && !config.headers["Content-Type"]) {
    config.headers["Content-Type"] = "application/json";
  }
  return config;
});

export default customerApi;
