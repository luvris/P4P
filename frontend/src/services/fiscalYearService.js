import api from './api';

/**
 * ปีงบประมาณสำหรับตัวเลือกบน Header — ใช้ร่วมกันทุกโมดูล
 * อ่านได้ทุก role ที่ login แล้ว
 */
export const fiscalYearService = {
    getFiscalYears: async () => {
        const response = await api.get('/fiscal-years');
        return response.data;
    },
};

export default fiscalYearService;
