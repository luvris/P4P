import { useState } from 'react';
import api from '../services/api';

const useAuth = () => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  
  // โหลด user จาก localStorage ตอนเริ่มต้น (persistent login)
  const [user, setUser] = useState(() => {
    try {
      const stored = localStorage.getItem('user');
      return stored ? JSON.parse(stored) : null;
    } catch {
      return null;
    }
  });

  const login = async (username, password) => {
    setLoading(true);
    setError('');

    try {
      const response = await api.post('/login', { username, password });
      
      const userData = response.data.user;
      setUser(userData);
      localStorage.setItem('user', JSON.stringify(userData));
      
      return { success: true, data: response.data };
    } catch (err) {
      let message = 'เกิดข้อผิดพลาด';
      
      if (err.response) {
        // Server ตอบกลับ (4xx, 5xx)
        message = err.response.data?.message 
          || `เกิดข้อผิดพลาด (${err.response.status})`;
      } else if (err.request) {
        // ไม่มีการตอบกลับจาก Server
        message = 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้ กรุณาตรวจสอบการเชื่อมต่อ';
      } else {
        // Error อื่นๆ
        message = err.message || 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
      }
      
      setError(message);
      return { success: false, error: message };
    } finally {
      setLoading(false);
    }
  };

  const logout = () => {
    setUser(null);
    setError('');
    localStorage.removeItem('user');
  };

  return { login, logout, loading, error, user };
};

export default useAuth;