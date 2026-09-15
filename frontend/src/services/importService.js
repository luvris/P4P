import api from './api';

export const importService = {
  /**
   * อัปโหลดไฟล์
   */
  uploadFile: async (file) => {
    const formData = new FormData();
    formData.append('file', file);

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