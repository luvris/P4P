import api from './api';

export const employeeService = {
    /**
     * ดึงรายชื่อบุคลากร (list + search + filter + pagination)
     * @param {Object} params - { search, employee_type_id, position_id, duty_id, group_id, work_id, status_id, page, per_page }
     */
    getEmployees: async (params = {}) => {
        const response = await api.get('/hr/employees', { params });
        return response.data;
    },

    /**
     * เพิ่มบุคลากรใหม่
     * @param {Object} data - ข้อมูลบุคลากร
     */
    createEmployee: async (data) => {
        const response = await api.post('/hr/employees', data);
        return response.data;
    },

    /**
     * แก้ไขข้อมูลบุคลากร
     * @param {number|string} id - รหัสบุคลากร
     * @param {Object} data - ข้อมูลบุคลากร
     */
    updateEmployee: async (id, data) => {
        const response = await api.put(`/hr/employees/${id}`, data);
        return response.data;
    },

    /**
     * ดึงสถิติสำหรับ Stat Cards
     */
    getStats: async () => {
        const response = await api.get('/hr/employees/stats');
        return response.data;
    },

    /**
     * ดึง dropdown ทั้งหมด (prefixes, employee_types, positions, ...)
     */
    getLookups: async () => {
        const response = await api.get('/hr/lookups');
        return response.data;
    },
};