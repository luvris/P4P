import api from './api';

/**
 * ไฟล์เงินเดือนจริงมีหลายพันแถว ใช้เวลาอ่าน+เขียนนานกว่า timeout ปกติ (10 วิ)
 */
const UPLOAD_TIMEOUT = 180000;

export const importService = {  // ไฟล์เดียวได้ทั้งทะเบียนบุคลากรและแถวเงินเดือน
  /**
   * อัปโหลดไฟล์
   * @param {File} file
   * @param {{fiscal_year?: number, period_month?: number}} [period] งวดสำรอง (ไฟล์ที่มีคอลัมน์ปี/เดือนจะใช้ของแต่ละแถวแทน)
   */
  uploadFile: async (file, period = {}) => {
    const formData = new FormData();
    formData.append('file', file);
    if (period?.period_month) formData.append('period_month', period.period_month);
    if (period?.fiscal_year) formData.append('fiscal_year', period.fiscal_year);

    const response = await api.post('/imports', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      timeout: UPLOAD_TIMEOUT,
    });

    return response.data;
  },

  /**
   * งวดที่มีข้อมูลในระบบ ใช้เป็นตัวเลือกงวดของปุ่ม export (งวดใหม่สุดมาก่อน)
   */
  getPeriods: async () => {
    const response = await api.get('/imports/periods');
    return response.data;
  },

  /**
   * ที่อยู่ไฟล์ export — ใช้กับ TemplateDownloadButton ที่รับ endpoint เป็นข้อความ
   *
   * @param {{period_year: number, period_month: number}} period
   */
  exportUrl: (period) =>
    `/imports/export?period_year=${period.period_year}&period_month=${period.period_month}`,

  /**
   * ดูประวัติการ import
   */
  getImports: async (page = 1) => {
    const response = await api.get(`/imports?page=${page}`);
    return response.data;
  },

  /**
   * ดูรายละเอียด import
   */
  getImportDetail: async (id) => {
    const response = await api.get(`/imports/${id}`);
    return response.data;
  },
};