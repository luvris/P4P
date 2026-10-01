import axios from 'axios';

export const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

// ปลายทางของ Sanctum สำหรับขอ CSRF cookie — อยู่นอก prefix /api จึงต้องตัด /api ออก
export const CSRF_COOKIE_URL = `${API_URL.replace(/\/api\/?$/, '')}/sanctum/csrf-cookie`;

const api = axios.create({
  baseURL: API_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 10000,
  // ส่ง cookie ของ session ไปกับทุกคำขอ (Sanctum SPA) พร้อมให้ axios แนบ
  // header X-XSRF-TOKEN อัตโนมัติ — ไม่เก็บ token ใน localStorage อีกต่อไป
  withCredentials: true,
  withXSRFToken: true,
});

/* ============================================================
 * ขอ CSRF cookie ก่อนคำขอที่เปลี่ยนข้อมูล
 * ต้องเรียกก่อน POST /login ทุกครั้ง ไม่งั้นจะถูกปฏิเสธด้วย 419
 *
 * ใช้ axios ตัวกลาง (ไม่ผูก baseURL) เพราะ path นี้ไม่ได้อยู่ใต้ /api
 * ============================================================ */
export const fetchCsrfCookie = () =>
  axios.get(CSRF_COOKIE_URL, { withCredentials: true });

/* ============================================================
 * RESPONSE INTERCEPTOR
 * - 401: ล้าง token + redirect login
 * - 403: log เตือน (ไม่ redirect — user อาจมีสิทธิ์บางส่วน)
 * - Network error: log
 * ============================================================ */
api.interceptors.response.use(
  (response) => response,
  (error) => {
    // --- 401 Unauthorized (session หมดอายุ / ไม่ได้ login) ---
    if (error.response?.status === 401) {
      localStorage.removeItem('token'); // ล้าง token เก่าที่ค้างจากเวอร์ชันก่อน
      localStorage.removeItem('user');

      // หน้า login ของระบบอยู่ที่ "/" — ไม่ redirect ถ้าอยู่หน้านี้แล้ว
      // (เช่น กรอกรหัสผ่านผิด ซึ่งก็ตอบ 401 เช่นกัน)
      if (typeof window !== 'undefined' && window.location.pathname !== '/') {
        window.location.href = '/';
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