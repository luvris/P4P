import api from './api';

const BASE = '/finance/budget-frameworks';

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

/** ดาวน์โหลด blob เป็นไฟล์ */
const downloadBlob = (blob, fileName) => {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
};

export const budgetFrameworkService = {
    /** นิยามกลุ่มวิชาชีพ + ค่าเริ่มต้น (สัดส่วน, ฐานค่าแรง) */
    getOptions: async () => {
        const response = await api.get(`${BASE}/options`);
        return response.data;
    },

    /**
     * คำนวณกรอบวงเงินสด ๆ (ยังไม่บันทึก)
     * params: fiscal_year, cost_basis, labor_percent, activity_ratio, quality_ratio, weights{}
     */
    getPreview: async (params = {}) => {
        const response = await api.get(`${BASE}/preview`, { params });
        return response.data;
    },

    /** รายชื่อตำแหน่งจากไฟล์ + กลุ่มวิชาชีพที่จับไว้ (สำหรับหน้าจอแก้ mapping) */
    getPositionGroups: async (params = {}) => {
        const response = await api.get(`${BASE}/position-groups`, { params });
        return response.data;
    },

    /** บันทึกการจับคู่ ตำแหน่ง → กลุ่มวิชาชีพ (เฉพาะตำแหน่งที่ส่งมา) */
    savePositionGroups: async (payload) => {
        const response = await api.put(`${BASE}/position-groups`, payload);
        return response.data;
    },

    /** รายการกรอบวงเงินที่บันทึกไว้ */
    list: async (params = {}) => {
        const response = await api.get(BASE, { params });
        return response.data;
    },

    /** บันทึกกรอบวงเงินของปีงบประมาณ */
    save: async (payload) => {
        const response = await api.post(BASE, payload);
        return response.data;
    },

    /**
     * ส่งออก Excel จากค่าที่ส่งมา (ตรงกับที่แสดงบนหน้าจอ)
     * ขยาย timeout เพราะการสร้างไฟล์ใช้เวลานานกว่า request ปกติ
     */
    exportExcel: async (params = {}, fileName) => {
        let response;
        try {
            response = await api.get(`${BASE}/export`, {
                params,
                responseType: 'blob',
                timeout: 60000,
                headers: {
                    Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                },
            });
        } catch (err) {
            throw new Error(await readErrorMessage(err));
        }

        downloadBlob(response.data, fileName);
    },

    /** ส่งออก Excel จากกรอบวงเงินที่บันทึกไว้ */
    exportSaved: async (id, fileName) => {
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
            throw new Error(await readErrorMessage(err));
        }

        downloadBlob(response.data, fileName);
    },
};

export default budgetFrameworkService;
