import api from './api';

export const reserveFundService = {
    /**
     * สรุปเงินสำรองรายปี — รวม payroll ทุกงวดของปีงบประมาณ
     * @param {Object} params - { fiscal_year, percent }
     */
    getAnnual: async (params = {}) => {
        const response = await api.get('/hr/reserve-fund/annual', { params });
        return response.data;
    },

    /**
     * บันทึกผลการคำนวณรายปี (1 ปีงบ = 1 รายการ)
     * @param {Object} payload - { fiscal_year, percent, note }
     */
    saveAnnual: async (payload) => {
        const response = await api.post('/hr/reserve-fund/annual', payload);
        return response.data;
    },

    /**
     * ดึงรายชื่อ import ที่มีข้อมูล payroll (สำหรับเลือกช่วงข้อมูล)
     */
    getImports: async () => {
        const response = await api.get('/hr/reserve-fund/imports');
        return response.data;
    },

    /**
     * ดึงข้อมูลสรุปเงินสำรอง
     * คำนวณจากฐานรายรับ = เงินเดือน + ล่วงเวลา + เงินประจำตำแหน่ง + P4P
     * @param {Object} params - { import_id, percent }
     */
    getSummary: async (params = {}) => {
        const response = await api.get('/hr/reserve-fund', { params });
        return response.data;
    },

    /**
     * บันทึกผลการคำนวณของงวด payroll ภายใต้ปีงบประมาณ
     * @param {Object} payload - { import_id, percent, fiscal_year, period_month, note }
     */
    saveCalculation: async (payload) => {
        const response = await api.post('/hr/reserve-fund/calculations', payload);
        return response.data;
    },

    /**
     * ยืนยันงวด — ยอดสะสมนับเฉพาะงวดที่ยืนยันแล้ว
     */
    confirmCalculation: async (id) => {
        const response = await api.post(`/hr/reserve-fund/calculations/${id}/confirm`);
        return response.data;
    },

    /**
     * ยกเลิกการยืนยันงวด (ข้อมูลผลคำนวณยังอยู่)
     */
    unconfirmCalculation: async (id) => {
        const response = await api.post(`/hr/reserve-fund/calculations/${id}/unconfirm`);
        return response.data;
    },

    /**
     * ยอดเงินสำรองสะสมของปีงบประมาณ
     * @param {Object} params - { fiscal_year }
     */
    getAccumulated: async (params = {}) => {
        const response = await api.get('/hr/reserve-fund/accumulated', { params });
        return response.data;
    },

    /**
     * ปีงบประมาณที่มีผลการคำนวณบันทึกไว้แล้ว
     */
    getFiscalYears: async () => {
        const response = await api.get('/hr/reserve-fund/fiscal-years');
        return response.data;
    },

    /**
     * ประวัติผลการคำนวณที่บันทึกไว้
     * @param {Object} params - { fiscal_year, page }
     */
    getCalculations: async (params = {}) => {
        const response = await api.get('/hr/reserve-fund/calculations', { params });
        return response.data;
    },
};