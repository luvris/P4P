import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 10000,
});

//REQUEST INTERCEPTOR
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

/* ============================================================
 * RESPONSE INTERCEPTOR
 * - 401: ล้าง token + redirect login
 * - 403: log เตือน (ไม่ redirect — user อาจมีสิทธิ์บางส่วน)
 * - Network error: log
 * ============================================================ */
api.interceptors.response.use(
  (response) => response,
  (error) => {
    // --- 401 Unauthorized (token หมดอายุ / ไม่ได้ login) ---
    if (error.response?.status === 401) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');

      // Redirect ไป login ถ้ายังไม่อยู่หน้า login
      if (
        typeof window !== 'undefined' &&
        !window.location.pathname.includes('/login')
      ) {
        window.location.href = '/login';
      }
    }

    // --- 403 Forbidden (ไม่มีสิทธิ์) ---
    if (error.response?.status === 403) {
      console.warn(
        '[403] Permission denied:',
        error.response?.data?.message || 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้'
      );
    }

    // --- Network error / Timeout (ไม่มี response กลับมา) ---
    if (!error.response) {
      console.error('[Network Error]', error.message);
    }

    return Promise.reject(error);
  }
);

export default api;