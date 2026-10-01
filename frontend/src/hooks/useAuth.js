import { useEffect, useState } from 'react';
import api, { fetchCsrfCookie } from '../services/api';

const useAuth = () => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  
  // โหลด user จาก localStorage ตอนเริ่มต้น
  const [user, setUser] = useState(() => {
    try {
      const stored = localStorage.getItem('user');
      return stored ? JSON.parse(stored) : null;
    } catch {
      return null;
    }
  });

  // อัปเดต state ทันทีเมื่อมีการแก้ไขโปรไฟล์จากหน้าอื่น (เช่น หน้า "โปรไฟล์ของฉัน")
  useEffect(() => {
    const handleUserUpdated = (event) => {
      if (event.detail) setUser(event.detail);
    };
    window.addEventListener('user-updated', handleUserUpdated);
    return () => window.removeEventListener('user-updated', handleUserUpdated);
  }, []);

  const login = async (username, password) => {
    setLoading(true);
    setError('');

    try {
      // ต้องขอ CSRF cookie ก่อน ไม่งั้นคำขอ POST จะถูกปฏิเสธด้วย 419
      await fetchCsrfCookie();

      const response = await api.post('/login', { username, password });

      const userData = response.data.user;

      // เก็บเฉพาะข้อมูลผู้ใช้สำหรับแสดงผลและการตรวจสิทธิ์ฝั่ง client
      // ตัวตนจริงอยู่ใน session cookie ที่เป็น HttpOnly (JavaScript อ่านไม่ได้)
      setUser(userData);
      localStorage.setItem('user', JSON.stringify(userData));
      localStorage.removeItem('token'); // ล้าง token เก่าที่ค้างจากเวอร์ชันก่อน

      return { success: true, data: response.data };
    } catch (err) {
      let message = 'เกิดข้อผิดพลาด';
      
      if (err.response) {
        message = err.response.data?.message 
          || `เกิดข้อผิดพลาด (${err.response.status})`;
      } else if (err.request) {
        message = 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่อ';
      } else {
        message = err.message || 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
      }
      
      setError(message);
      return { success: false, error: message };
    } finally {
      setLoading(false);
    }
  };

  const logout = async () => {
    try {
      // สั่งล้าง session ที่ฝั่งเซิร์ฟเวอร์ก่อน — ถ้า session หมดอายุไปแล้ว
      // (401) ก็ยังต้องล้างสถานะฝั่ง client ต่อ
      await api.post('/logout');
    } catch {
      // ไม่ต้องทำอะไร — ล้างฝั่ง client ด้านล่างเสมอ
    } finally {
      setUser(null);
      setError('');
      localStorage.removeItem('user');
      localStorage.removeItem('token');
    }
  };

  //Helper functions สำหรับ role
  const isAdmin = () => user?.role === 'admin';
  const isHr = () => user?.role === 'hr';
  const isFinance = () => user?.role === 'finance';
  const hasRole = (...roles) => roles.includes(user?.role);

  return { 
    login, 
    logout, 
    loading, 
    error, 
    user,
    isAdmin,
    isHr,
    isFinance,
    hasRole,
  };
};

export default useAuth;