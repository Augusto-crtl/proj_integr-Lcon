const api = axios.create({
  baseURL: "http://localhost:8000/api",
  timeout: 2500,
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token") || "seu_token_aqui";
  config.headers.Authorization = `Bearer ${token}`;
  return config;
});
