import api from './api';

/**
 * บริการนำเข้าข้อมูล "การอยู่ภารกิจของบุคลากร"
 * แยกจาก hrImportService (นำเข้าข้อมูลบุคลากร) โดยสิ้นเชิง
 */
export const dutyAssignmentImportService = {
  /**
   * อ่านไฟล์เพื่อดูตัวอย่างข้อมูล + คำเตือน (ยังไม่บันทึก)
   */
  preview: async (file) => {
    const formData = new FormData();
    formData.append('file', file);

    const response = await api.post('/hr/duty-assignment-imports/preview', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  },

  /**
   * ยืนยันการนำเข้า — อัปเดตภารกิจ/กลุ่มงาน/งาน ของบุคลากรตาม PID
   */
  store: async (file) => {
    const formData = new FormData();
    formData.append('file', file);

    const response = await api.post('/hr/duty-assignment-imports', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  },

  /**
   * ประวัติการนำเข้าข้อมูลการอยู่ภารกิจ
   */
  getImports: async (page = 1) => {
    const response = await api.get(`/hr/duty-assignment-imports?page=${page}`);
    return response.data;
  },
};
