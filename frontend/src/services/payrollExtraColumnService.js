import api from './api';

/**
 * คอลัมน์เพิ่มเติมของไฟล์เงินเดือน — จัดการโดย admin เท่านั้น
 *
 * เมื่อเพิ่มคอลัมน์ใหม่ ผู้ใช้ต้องดาวน์โหลดแบบฟอร์มใหม่ ไฟล์เก่าที่ยังไม่มี
 * คอลัมน์นั้นจะอัปโหลดได้ปกติ แต่ค่าในคอลัมน์นั้นจะว่าง
 */
export const payrollExtraColumnService = {
  list: async () => {
    const response = await api.get('/admin/payroll-extra-columns');
    return response.data;
  },

  /**
   * @param {{name: string, description?: string, data_type?: 'text'|'number'|'date'}} payload
   */
  create: async (payload) => {
    const response = await api.post('/admin/payroll-extra-columns', payload);
    return response.data;
  },

  update: async (id, payload) => {
    const response = await api.put(`/admin/payroll-extra-columns/${id}`, payload);
    return response.data;
  },

  /** ปิดใช้งาน (ไม่ลบข้อมูลเดิมทิ้ง) */
  disable: async (id) => {
    const response = await api.delete(`/admin/payroll-extra-columns/${id}`);
    return response.data;
  },
};