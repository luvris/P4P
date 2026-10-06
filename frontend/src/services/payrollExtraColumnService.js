import api from './api';

/**
 * คอลัมน์เพิ่มเติมของไฟล์เงินเดือน — จัดการโดย admin เท่านั้น
 *
 * เมื่อเพิ่มคอลัมน์ใหม่ ผู้ใช้ต้องดาวน์โหลดแบบฟอร์มใหม่ ไฟล์เก่าที่ยังไม่มี
 * คอลัมน์นั้นจะอัปโหลดได้ปกติ แต่ค่าในคอลัมน์นั้นจะว่าง
 */
export const payrollExtraColumnService = {
  /**
   * คืนรายการคอลัมน์ทั้งหมด พร้อม `base_columns` = ชื่อ 39 คอลัมน์มาตรฐาน
   * ที่ใช้เป็นตัวเลือกตำแหน่งวางคอลัมน์
   */
  list: async () => {
    const response = await api.get('/admin/payroll-extra-columns');
    return response.data;
  },

  /**
   * before_column / after_column คือชื่อคอลัมน์มาตรฐานที่ใช้ยึดตำแหน่ง
   * ส่งค่า null เมื่อไม่ได้ยึด (ระบบจะต่อท้ายไฟล์)
   *
   * @param {{name: string, description?: string|null, data_type?: 'text'|'number'|'date',
   *          before_column?: string|null, after_column?: string|null}} payload
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