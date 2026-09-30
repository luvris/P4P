import api from './api';

export const profileService = {
  /**
   * ข้อมูลผู้ใช้ที่ล็อกอินอยู่ (สดจากฐานข้อมูล ไม่ใช่ค่าที่ค้างใน localStorage)
   */
  getProfile: async () => {
    const response = await api.get('/profile');
    return response.data;
  },

  /**
   * บันทึกข้อมูลส่วนตัว (ชื่อ / อีเมล)
   */
  updateProfile: async ({ name, email }) => {
    const response = await api.put('/profile', { name, email });
    return response.data;
  },

  /**
   * เปลี่ยนรหัสผ่าน — ส่งรหัสผ่านเดิมเพื่อยืนยันตัวตน
   */
  changePassword: async ({ currentPassword, password, passwordConfirmation }) => {
    const response = await api.put('/profile/password', {
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    });
    return response.data;
  },
};
