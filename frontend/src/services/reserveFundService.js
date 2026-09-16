import api from './api';

export const reserveFundService = {
    /**
     * ดึงรายชื่อ import ที่มีข้อมูล payroll (สำหรับเลือกช่วงข้อมูล)
     */
    getImports: async () => {
        const response = await api.get('/hr/reserve-fund/imports');
        return response.data;
    },

    /**
     * ดึงข้อมูลสรุปเงินสำรอง 3% (คำนวณจาก payroll.net_income)
     * @param {Object} params - { import_id }
     */
    getSummary: async (params = {}) => {
        const response = await api.get('/hr/reserve-fund', { params });
        return response.data;
    },
};