import { useState, useEffect, useCallback, useRef } from 'react';
import api from '../services/api';

const useLookups = () => {
    const [lookups, setLookups] = useState({
        prefixes: [],
        employee_types: [],
        positions: [],
        duties: [],
        groups: [],
        works: [],
        employee_statuses: [],
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const fetchedRef = useRef(false);

    const fetchLookups = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const response = await api.get('/hr/lookups');
            setLookups(response.data);
            return { success: true, data: response.data };
        } catch (err) {
            let message = 'ไม่สามารถโหลดข้อมูลตัวเลือกได้';
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
    }, []);

    useEffect(() => {
        if (fetchedRef.current) return;
        fetchedRef.current = true;
        fetchLookups();
    }, [fetchLookups]);

    return {
        lookups,
        loading,
        error,
        refetch: fetchLookups,
    };
};

export default useLookups;