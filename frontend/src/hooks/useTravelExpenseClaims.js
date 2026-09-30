import { useCallback, useEffect, useState } from 'react';
import { travelExpenseClaimService } from '../services/travelExpenseClaimService';
import useFiscalYear from './useFiscalYear';

/**
 * รายการใบเบิกค่าใช้จ่ายของปีงบประมาณที่เลือกบน Header
 */
const useTravelExpenseClaims = () => {
    const { fiscalYear } = useFiscalYear();

    const [claims, setClaims] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [filters, setFilters] = useState({
        claim_month: '',
        status: '',
        search: '',
        page: 1,
        per_page: 10,
    });

    const fetchClaims = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const params = { fiscal_year: fiscalYear, page: filters.page, per_page: filters.per_page };
            if (filters.claim_month) params.claim_month = filters.claim_month;
            if (filters.status) params.status = filters.status;
            if (filters.search) params.search = filters.search;

            const response = await travelExpenseClaimService.getClaims(params);
            setClaims(response.data || []);
            setMeta({
                current_page: response.current_page,
                last_page: response.last_page,
                per_page: response.per_page,
                total: response.total,
                from: response.from,
                to: response.to,
            });
        } catch (err) {
            setError(err.response?.data?.message || 'ไม่สามารถโหลดรายการใบเบิกได้');
            setClaims([]);
            setMeta(null);
        } finally {
            setLoading(false);
        }
    }, [fiscalYear, filters]);

    useEffect(() => {
        (async () => {
            await fetchClaims();
        })();
    }, [fetchClaims]);

    /** เปลี่ยน filter — reset กลับหน้าแรกยกเว้นการเปลี่ยนหน้า */
    const updateFilters = useCallback((next, resetPage = true) => {
        setFilters((prev) => ({ ...prev, ...next, ...(resetPage ? { page: 1 } : {}) }));
    }, []);

    const runAction = useCallback(async (action) => {
        try {
            const response = await action();
            await fetchClaims();
            return { success: true, message: response?.message };
        } catch (err) {
            return {
                success: false,
                error: err.response?.data?.message || 'ไม่สามารถดำเนินการได้',
            };
        }
    }, [fetchClaims]);

    const confirmClaim = useCallback(
        (id) => runAction(() => travelExpenseClaimService.confirm(id)),
        [runAction],
    );

    const cancelClaim = useCallback(
        (id, reason) => runAction(() => travelExpenseClaimService.cancel(id, reason)),
        [runAction],
    );

    return {
        fiscalYear,
        claims,
        meta,
        loading,
        error,
        filters,
        updateFilters,
        refetch: fetchClaims,
        confirmClaim,
        cancelClaim,
    };
};

export default useTravelExpenseClaims;
