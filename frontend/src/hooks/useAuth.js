import { useState } from 'react';
import api from '../services/api';

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

  const login = async (username, password) => {
    setLoading(true);
    setError('');

    try {
      const response = await api.post('/login', { username, password });
      
      const userData = response.data.user;
      const token = response.data.token;
      
      setUser(userData);
      localStorage.setItem('user', JSON.stringify(userData));
      localStorage.setItem('token', token);   //เก็บ token
      
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

  const logout = () => {
    setUser(null);
    setError('');
    localStorage.removeItem('user');
    localStorage.removeItem('token');
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