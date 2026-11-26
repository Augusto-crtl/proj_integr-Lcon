// Centraliza chamadas REST e anexa a credencial da sessão a cada requisição.
const api = axios.create({
  baseURL: "/api",
  timeout: 5000,
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
