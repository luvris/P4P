import api from './api';

const BASE = '/finance/travel-expense-claims';

/** แปลง error ของ request แบบ blob ให้ได้ข้อความจริงจาก backend */
const readErrorMessage = async (err) => {
    const fallback = 'ไม่สามารถส่งออก Excel ได้';
    const data = err.response?.data;

    if (data instanceof Blob) {
        try {
            const parsed = JSON.parse(await data.text());
            return parsed.message || fallback;
        } catch {
            return fallback;
        }
    }

    return data?.message || err.message || fallback;
};

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

    /**
     * สรุปผลการเบิกค่าใช้จ่าย — ยอดแยกตาม ภารกิจ → กลุ่มงาน → งาน
     *
     * params: fiscal_year, level (duty|group|work), duty_id, group_id, work_id,
     *         period_from, period_to (Y-m-d), include_draft
     * ทุกยอดคำนวณจากข้อมูลชุดเดียวกันตามตัวกรองทั้งชุด
     */
    getSummary: async (params = {}) => {
        const response = await api.get(`${BASE}/summary`, { params });
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

    /**
     * ดาวน์โหลด Excel (เฉพาะเอกสารที่ยืนยันแล้ว)
     *
     * ต้อง override Accept เพราะ axios instance ตั้ง application/json ไว้
     * และขยาย timeout เพราะการสร้างไฟล์ใช้เวลานานกว่า request ปกติ
     */
    exportExcel: async (id, fileName) => {
        let response;
        try {
            response = await api.get(`${BASE}/${id}/export`, {
                responseType: 'blob',
                timeout: 60000,
                headers: {
                    Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                },
            });
        } catch (err) {
            // error ที่ตอบกลับมาเป็น blob ต้องอ่านเป็นข้อความก่อนจึงเห็น message จริง
            throw new Error(await readErrorMessage(err));
        }

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
