import { useCallback, useEffect, useState } from 'react';
import { travelExpenseClaimService } from '../services/travelExpenseClaimService';
import { calendarYearOf } from '../utils/fiscalPeriod';

/**
 * สรุปผลการเบิกค่าใช้จ่าย — ดริลดาวน์ ภารกิจ → กลุ่มงาน → งาน
 *
 * ตัวกรองทุกตัว (ช่วงเดือนที่เบิก, ภารกิจ/กลุ่มงาน/งาน, สถานะ)
 * ส่งไปที่ query เดียวบน backend สรุปและตารางจัดอันดับ
 * จึงคำนวณจากข้อมูลชุดเดียวกันเสมอ
 */
const DEFAULT_FILTERS = {
    level: 'duty',      // duty | group | work
    dutyId: null,
    groupId: null,
    workId: null,
    periodFrom: '',     // เดือนเริ่ม (1-12) — '' = ทั้งหมด
    periodTo: '',       // เดือนสุดท้าย (1-12) — '' = ทั้งหมด
    includeDraft: false,
};

/** เดือนที่เบิก → วันที่ 1 ของเดือนนั้น (ค.ศ.) สำหรับ claim_period */
const periodValue = (fiscalYear, month) =>
    month
        ? `${calendarYearOf(fiscalYear, Number(month)) - 543}-${String(month).padStart(2, '0')}-01`
        : null;

const buildParams = (fiscalYear, filters) => {
    const params = { fiscal_year: fiscalYear, level: filters.level };

    if (filters.dutyId) params.duty_id = filters.dutyId;
    if (filters.groupId) params.group_id = filters.groupId;
    if (filters.workId) params.work_id = filters.workId;
    if (filters.periodFrom) params.period_from = periodValue(fiscalYear, filters.periodFrom);
    if (filters.periodTo) params.period_to = periodValue(fiscalYear, filters.periodTo);
    if (filters.includeDraft) params.include_draft = true;

    return params;
};

const useClaimSummary = (fiscalYear) => {
    const [filters, setFilters] = useState(DEFAULT_FILTERS);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let active = true;
        (async () => {
            setLoading(true);
            setError('');
            try {
                const response = await travelExpenseClaimService.getSummary(
                    buildParams(fiscalYear, filters),
                );
                if (active) setData(response.data || null);
            } catch (err) {
                if (active) {
                    setError(err.response?.data?.message || 'ไม่สามารถโหลดสรุปผลการเบิกค่าใช้จ่ายได้');
                    setData(null);
                }
            } finally {
                if (active) setLoading(false);
            }
        })();
        return () => {
            active = false;
        };
    }, [fiscalYear, filters]);

    /**
     * เปลี่ยนตัวกรองพร้อมรักษาความสัมพันธ์ของระดับ:
     * เลือกภารกิจ → ลงไปดูกลุ่มงานของภารกิจนั้น (ล้างกลุ่มงาน/งานเดิม)
     * เลือกกลุ่มงาน → ลงไปดูงานของกลุ่มงานนั้น (ล้างงานเดิม)
     */
    const updateFilters = useCallback((patch) => {
        setFilters((prev) => {
            const next = { ...prev, ...patch };

            if (patch.dutyId !== undefined) {
                next.groupId = null;
                next.workId = null;
                next.level = patch.dutyId ? 'group' : 'duty';
            }
            if (patch.groupId !== undefined) {
                next.workId = null;
                next.level = patch.groupId ? 'work' : next.dutyId ? 'group' : 'duty';
            }
            if (patch.workId !== undefined) {
                next.level = 'work';
            }

            return next;
        });
    }, []);

    /** คลิกแถวหมวดเพื่อลงลึก ("ไม่ระบุสังกัด" ลงลึกไม่ได้) */
    const drillDown = useCallback((bucket) => {
        if (!bucket?.id) return;

        setFilters((prev) => {
            if (prev.level === 'duty') {
                return { ...prev, dutyId: bucket.id, groupId: null, workId: null, level: 'group' };
            }
            if (prev.level === 'group') {
                return { ...prev, groupId: bucket.id, workId: null, level: 'work' };
            }
            return prev;
        });
    }, []);

    /** ย้อนกลับตาม breadcrumb — ทุกครั้งคำนวณใหม่จากตัวกรองปัจจุบัน */
    const goToLevel = useCallback((target) => {
        setFilters((prev) => {
            if (target === 'duty') {
                return {
                    ...DEFAULT_FILTERS,
                    periodFrom: prev.periodFrom,
                    periodTo: prev.periodTo,
                    includeDraft: prev.includeDraft,
                };
            }
            if (target === 'group') {
                return { ...prev, level: 'group', groupId: null, workId: null };
            }
            if (target === 'work') {
                return { ...prev, level: 'work', workId: null };
            }
            return prev;
        });
    }, []);

    return {
        filters,
        updateFilters,
        drillDown,
        goToLevel,
        data,
        loading,
        error,
    };
};

export default useClaimSummary;
