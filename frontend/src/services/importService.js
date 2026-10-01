import api from './api';

export const importService = {
  /**
   * อัปโหลดไฟล์
   * @param {File} file
   * @param {{fiscal_year?: number, period_month?: number}} [period] งวดของไฟล์ payroll
   */
  uploadFile: async (file, period = {}) => {
    const formData = new FormData();
    formData.append('file', file);
    if (period?.period_month) formData.append('period_month', period.period_month);
    if (period?.fiscal_year) formData.append('fiscal_year', period.fiscal_year);

    const response = await api.post('/finance/imports', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  },

  /**
   * ดูประวัติการ import
   */
  getImports: async (page = 1) => {
    const response = await api.get(`/finance/imports?page=${page}`);
    return response.data;
  },

  /**
   * ดูรายละเอียด import
   */
  getImportDetail: async (id) => {
    const response = await api.get(`/finance/imports/${id}`);
    return response.data;
  },
};