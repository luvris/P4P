import api from './api';

const BASE = '/finance/travel-expense-claims';

export const travelExpenseClaimService = {
    /** รายการใบเบิกของปีงบประมาณที่เลือก */
    getClaims: async (params = {}) => {
        const response = await api.get(BASE, { params });
        return response.data;
    },

    /** รายละเอียดเอกสาร 1 ฉบับ */
    getClaim: async (id) => {
        const response = await api.get(`${BASE}/${id}`);
        return response.data;
    },

    /** ค่าเริ่มต้นของฟอร์ม (ประเภทค่าใช้จ่าย, หน่วยงาน, เดือนของปีงบ) */
    getOptions: async (params = {}) => {
        const response = await api.get(`${BASE}/options`, { params });
        return response.data;
    },

    /** ค้นหาบุคลากรจากระบบเดิม (PID, ชื่อ, นามสกุล, ตำแหน่ง) */
    searchEmployees: async (params = {}) => {
        const response = await api.get(`${BASE}/employees`, { params });
        return response.data;
    },

    /** สร้างเอกสาร — status: draft หรือ confirmed */
    create: async (payload) => {
        const response = await api.post(BASE, payload);
        return response.data;
    },

    /** แก้ไขเอกสาร (ได้เฉพาะ draft) */
    update: async (id, payload) => {
        const response = await api.put(`${BASE}/${id}`, payload);
        return response.data;
    },

    confirm: async (id) => {
        const response = await api.post(`${BASE}/${id}/confirm`);
        return response.data;
    },

    /** ยกเลิกเอกสาร — เปลี่ยนสถานะเป็น cancelled ไม่ลบข้อมูล */
    cancel: async (id, cancelReason = null) => {
        const response = await api.post(`${BASE}/${id}/cancel`, {
            cancel_reason: cancelReason,
        });
        return response.data;
    },

    /** ดาวน์โหลด Excel (เฉพาะเอกสารที่ยืนยันแล้ว) */
    exportExcel: async (id, fileName) => {
        const response = await api.get(`${BASE}/${id}/export`, {
            responseType: 'blob',
        });

        const url = window.URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    },
};

export default travelExpenseClaimService;
