import api from './api';

export const ADJUSTMENT_TYPES = [
    { value: 'ครบ 6 เดือน', label: 'ครบ 6 เดือน' },
    { value: 'ครบ 1 ปี', label: 'ครบ 1 ปี' },
    { value: 'ประจำปี', label: 'ประจำปี' },
    { value: 'อื่นๆ', label: 'อื่นๆ' },
];

export const salaryAdjustmentService = {
    // GET /hr/salary-adjustments
    list(params) {
        return api.get('/hr/salary-adjustments', { params });
    },

    // GET /hr/salary-adjustments/summary
    summary(params) {
        return api.get('/hr/salary-adjustments/summary', { params });
    },

    // POST /hr/salary-adjustments
    create(payload) {
        return api.post('/hr/salary-adjustments', payload);
    },
};
