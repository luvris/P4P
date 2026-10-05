import api from './api';

/**
 * ไฟล์เงินเดือนจริงมีหลายพันแถว ใช้เวลาอ่าน+เขียนนานกว่า timeout ปกติ (10 วิ)
 */
const UPLOAD_TIMEOUT = 180000;

export const hrImportService = {
  /**
   * อ่านไฟล์เพื่อดูตัวอย่างข้อมูล (10 แถวแรก) + คำเตือน
   */
  preview: async (file) => {
    const formData = new FormData();
    formData.append('file', file);

    const response = await api.post('/hr/imports/preview', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      timeout: UPLOAD_TIMEOUT,
    });

    return response.data;
  },

  /**
   * ยืนยันการนำเข้าข้อมูลบุคลากร (upsert ผ่าน citizen_id)
   */
  store: async (file) => {
    const formData = new FormData();
    formData.append('file', file);

    const response = await api.post('/hr/imports', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      timeout: UPLOAD_TIMEOUT,
    });

    return response.data;
  },

  /**
   * ประวัติการนำเข้าข้อมูลบุคลากร
   */
  getImports: async (page = 1) => {
    const response = await api.get(`/hr/imports?page=${page}`);
    return response.data;
  },
};