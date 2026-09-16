import { useState, useEffect, useCallback, useRef } from 'react';
import { salaryAdjustmentService } from '../services/salaryAdjustmentService';

const useSalaryAdjustments = (initialFilters = {}) => {
    const [items, setItems] = useState([]);
    const [meta, setMeta] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
        from: 1,
        to: 0,
    });

    const [summary, setSummary] = useState({
        total_adjustments: 0,
        total_increase: 0,
        total_employees: 0,
    });

    const [loading, setLoading] = useState(false);
    const [summaryLoading, setSummaryLoading] = useState(false);
    const [error, setError] = useState('');

    const [filters, setFilters] = useState({
        search: '',
        adjustment_type: '',
        date_from: '',
        date_to: '',
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

    const fetchItems = useCallback(async (overrideFilters = null) => {
        setLoading(true);
        setError('');
        try {
            const params = cleanParams(overrideFilters || filtersRef.current);
            const response = await salaryAdjustmentService.list(params);

            const payload = response.data;
            setItems(payload.data || []);
            setMeta({
                current_page: payload.current_page ?? 1,
                last_page: payload.last_page ?? 1,
                per_page: payload.per_page ?? 10,
                total: payload.total ?? 0,
                from: payload.from ?? 0,
                to: payload.to ?? 0,
            });
            return { success: true, data: payload };
        } catch (err) {
            let message = 'ไม่สามารถโหลดประวัติการปรับฐานเงินเดือนได้';
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

    const fetchSummary = useCallback(async (overrideFilters = null) => {
        setSummaryLoading(true);
        try {
            const params = cleanParams(overrideFilters || filtersRef.current);
            const response = await salaryAdjustmentService.summary(params);
            setSummary(response.data?.data || {
                total_adjustments: 0,
                total_increase: 0,
                total_employees: 0,
            });
            return { success: true, data: response.data };
        } catch (err) {
            console.error('Failed to fetch salary adjustment summary:', err);
            return { success: false, error: err.message };
        } finally {
            setSummaryLoading(false);
        }
    }, [cleanParams]);

    const createAdjustment = useCallback(async (payload) => {
        try {
            const response = await salaryAdjustmentService.create(payload);
            await Promise.all([fetchItems(), fetchSummary()]);
            return { success: true, data: response.data?.data };
        } catch (err) {
            let message = 'ไม่สามารถบันทึกการปรับฐานเงินเดือนได้';
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
    }, [fetchItems, fetchSummary]);

    const updateFilters = useCallback((newFilters, resetPage = true) => {
        setFilters((prev) => ({
            ...prev,
            ...newFilters,
            ...(resetPage ? { page: 1 } : {}),
        }));
    }, []);

    const setPage = useCallback((page) => {
        setFilters((prev) => ({ ...prev, page }));
    }, []);

    // Load list + summary เมื่อ filter เปลี่ยน
    useEffect(() => {
        fetchItems();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        filters.search,
        filters.adjustment_type,
        filters.date_from,
        filters.date_to,
        filters.page,
        filters.per_page,
    ]);

    useEffect(() => {
        fetchSummary();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        filters.adjustment_type,
        filters.date_from,
        filters.date_to,
    ]);

    return {
        items,
        meta,
        summary,
        loading,
        summaryLoading,
        error,
        filters,
        updateFilters,
        setPage,
        createAdjustment,
        refetch: fetchItems,
        refetchSummary: fetchSummary,
    };
};

export default useSalaryAdjustments;