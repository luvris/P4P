import { useState, useEffect, useCallback } from 'react';
import { reserveFundService } from '../services/reserveFundService';

const useReserveFund = () => {
    const [imports, setImports] = useState([]);
    const [importId, setImportId] = useState('');
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const fetchImports = useCallback(async () => {
        try {
            const response = await reserveFundService.getImports();
            setImports(response.data || []);
            return response.data || [];
        } catch (err) {
            console.error('Failed to fetch imports:', err);
            return [];
        }
    }, []);

    const fetchSummary = useCallback(async (selectedImportId = '') => {
        setLoading(true);
        setError('');
        try {
            const params = selectedImportId ? { import_id: selectedImportId } : {};
            const response = await reserveFundService.getSummary(params);
            setData(response);
            return { success: true, data: response };
        } catch (err) {
            let message = 'ไม่สามารถโหลดข้อมูลเงินสำรอง 3% ได้';
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
        (async () => {
            const list = await fetchImports();
            if (list.length > 0) {
                const latest = list[0].id;
                setImportId(latest);
                await fetchSummary(latest);
            } else {
                await fetchSummary('');
            }
        })();
    }, [fetchImports, fetchSummary]);

    const handleImportChange = useCallback((id) => {
        setImportId(id);
        fetchSummary(id);
    }, [fetchSummary]);

    return {
        imports,
        importId,
        data,
        loading,
        error,
        handleImportChange,
        refetch: () => fetchSummary(importId),
    };
};

export default useReserveFund;