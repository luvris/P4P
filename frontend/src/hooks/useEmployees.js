import { useState, useEffect, useCallback, useRef } from 'react';
import api from '../services/api';

const useEmployees = (initialFilters = {}) => {
    const [employees, setEmployees] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const [stats, setStats] = useState(null);
    const [statsLoading, setStatsLoading] = useState(false);

    const [filters, setFilters] = useState({
        search: '',
        employee_type_id: '',
        position_id: '',
        duty_id: '',
        group_id: '',
        work_id: '',
        status_id: '',
        page: 1,
        per_page: 10,
        ...initialFilters,
    });

    const filtersRef = useRef(filters);
    useEffect(() => {
        filtersRef.current = filters;
    }, [filters]);

    const cleanParams = useCallback((raw) => {
        const params = {};
        Object.entries(raw).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                params[key] = value;
            }
        });
        return params;
    }, []);

    const fetchEmployees = useCallback(async (overrideFilters = null) => {
        setLoading(true);
        setError('');
        try {
            const params = cleanParams(overrideFilters || filtersRef.current);
            const response = await api.get('/hr/employees', { params });
            setEmployees(response.data.data || []);
            setMeta(response.data.meta || null);
            return { success: true, data: response.data };
        } catch (err) {
            let message = 'ไม่สามารถโหลดรายชื่อบุคลากรได้';
            if (err.response) {
                message = err.response.data?.message || `เกิดข้อผิดพลาด (${err.response.status})`;
            } else if (err.request) {
                message = 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้';
            } else {
                message = err.message || 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
            }
            setError(message);
            return { success: false, error: message };
        } finally {
            setLoading(false);
        }
    }, [cleanParams]);

    const fetchStats = useCallback(async () => {
        setStatsLoading(true);
        try {
            const response = await api.get('/hr/employees/stats');
            setStats(response.data);
            return { success: true, data: response.data };
        } catch (err) {
            console.error('Failed to fetch stats:', err);
            return { success: false, error: err.message };
        } finally {
            setStatsLoading(false);
        }
    }, []);

    const createEmployee = useCallback(async (payload) => {
        try {
            const response = await api.post('/hr/employees', payload);
            await Promise.all([fetchEmployees(), fetchStats()]);
            return { success: true, data: response.data.data };
        } catch (err) {
            let message = 'ไม่สามารถเพิ่มบุคลากรได้';
            let errors = {};
            if (err.response?.status === 422) {
                message = err.response.data?.message || 'ข้อมูลไม่ถูกต้อง';
                errors = err.response.data?.errors || {};
            } else if (err.response) {
                message = err.response.data?.message || `เกิดข้อผิดพลาด (${err.response.status})`;
            } else if (err.request) {
                message = 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้';
            } else {
                message = err.message || 'เกิดข้อผิดพลาดที่ไม่คาดคิด';
            }
            return { success: false, error: message, errors };
        }
    }, [fetchEmployees, fetchStats]);

    const updateFilters = useCallback((newFilters, resetPage = true) => {
        setFilters((prev) => ({
            ...prev,
            ...newFilters,
            ...(resetPage ? { page: 1 } : {}),
        }));
    }, []);

    const setSearchFilter = useCallback((value) => {
        setFilters((prev) => {
            if (prev.search === value) return prev;
            return { ...prev, search: value, page: 1 };
        });
    }, []);

    useEffect(() => {
        fetchEmployees();
    }, [
        filters.search,
        filters.employee_type_id,
        filters.position_id,
        filters.duty_id,
        filters.group_id,
        filters.work_id,
        filters.status_id,
        filters.page,
        filters.per_page,
    ]);

    useEffect(() => {
        fetchStats();
    }, [fetchStats]);

    return {
        employees, meta, loading, error,
        stats, statsLoading,
        filters, updateFilters, setFilters, setSearchFilter,
        refetch: fetchEmployees,
        refetchStats: fetchStats,
        createEmployee,
    };
};

export default useEmployees;