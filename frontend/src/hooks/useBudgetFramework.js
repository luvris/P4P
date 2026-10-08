import { useState, useEffect, useCallback, useRef } from 'react';
import { budgetFrameworkService } from '../services/budgetFrameworkService';
import useFiscalYear from './useFiscalYear';

/**
 * กรอบวงเงิน P4P
 *
 * โหลดนิยามกลุ่มวิชาชีพ, คำนวณกรอบวงเงินของปีงบที่เลือกตามพารามิเตอร์ที่ผู้ใช้กรอก,
 * บันทึกกรอบ และส่งออก Excel (ทั้งแบบสดและแบบที่บันทึกไว้)
 */
const useBudgetFramework = () => {
    const { fiscalYear, refreshFiscalYears } = useFiscalYear();

    const [options, setOptions] = useState(null);
    const [params, setParams] = useState({
        cost_basis: 'total_income',
        labor_percent: '3',
        activity_ratio: '70',
        quality_ratio: '30',
        weights: {},
    });
    const [data, setData] = useState(null);
    const [savedList, setSavedList] = useState([]);
    const [positionGroups, setPositionGroups] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [savingGroups, setSavingGroups] = useState(false);
    const [error, setError] = useState('');

    // เก็บค่าล่าสุดไว้ใช้ตอน refetch โดยไม่ผูก dependency
    const stateRef = useRef({ params, fiscalYear });
    useEffect(() => {
        stateRef.current = { params, fiscalYear };
    }, [params, fiscalYear]);

    /** โหลดนิยามกลุ่ม + ค่าเริ่มต้น แล้วตั้งน้ำหนักเริ่มต้น */
    useEffect(() => {
        let active = true;

        budgetFrameworkService
            .getOptions()
            .then((response) => {
                if (!active) return;
                const payload = response?.data || {};
                setOptions(payload);

                const defaults = payload.defaults || {};
                setParams((prev) => ({
                    cost_basis: prev.cost_basis || defaults.cost_basis || 'total_income',
                    labor_percent: prev.labor_percent || String(defaults.labor_percent ?? 3),
                    activity_ratio: prev.activity_ratio || String(defaults.activity_ratio ?? 70),
                    quality_ratio: prev.quality_ratio || String(defaults.quality_ratio ?? 30),
                    weights: { ...(defaults.weights || {}), ...prev.weights },
                }));
            })
            .catch((err) => {
                if (active) setError(err.response?.data?.message || 'ไม่สามารถโหลดตัวเลือกได้');
            });

        return () => {
            active = false;
        };
    }, []);

    /** แปลงพารามิเตอร์สำหรับส่ง backend (ตัดน้ำหนักที่เป็นค่าว่างออก) */
    const buildQuery = useCallback((year, current) => {
        const query = {
            fiscal_year: year,
            cost_basis: current.cost_basis,
            labor_percent: current.labor_percent === '' ? undefined : current.labor_percent,
            activity_ratio: current.activity_ratio === '' ? undefined : current.activity_ratio,
            quality_ratio: current.quality_ratio === '' ? undefined : current.quality_ratio,
        };

        const weights = {};
        Object.entries(current.weights || {}).forEach(([code, value]) => {
            if (value !== '' && value !== null && value !== undefined && !Number.isNaN(Number(value))) {
                weights[code] = value;
            }
        });

        if (Object.keys(weights).length > 0) query.weights = weights;

        return query;
    }, []);

    const fetchPreview = useCallback(async (year = null, current = null) => {
        setLoading(true);
        setError('');
        try {
            const targetYear = year ?? stateRef.current.fiscalYear;
            const targetParams = current ?? stateRef.current.params;

            const response = await budgetFrameworkService.getPreview(buildQuery(targetYear, targetParams));
            setData(response);
            return { success: true, data: response };
        } catch (err) {
            const message =
                err.response?.data?.message ||
                (err.request ? 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้' : 'ไม่สามารถคำนวณกรอบวงเงินได้');
            setError(message);
            return { success: false, error: message };
        } finally {
            setLoading(false);
        }
    }, [buildQuery]);

    const fetchSaved = useCallback(async (year = null) => {
        try {
            const response = await budgetFrameworkService.list({ fiscal_year: year ?? stateRef.current.fiscalYear });
            setSavedList(response?.data || []);
        } catch {
            setSavedList([]);
        }
    }, []);

    // คำนวณอัตโนมัติเมื่อปีงบหรือพารามิเตอร์เปลี่ยน (หน่วงเล็กน้อยระหว่างพิมพ์)
    useEffect(() => {
        const timer = setTimeout(() => {
            fetchPreview(fiscalYear, params);
        }, 400);

        return () => clearTimeout(timer);
    }, [fiscalYear, params, fetchPreview]);

    const loadPositionGroups = useCallback(async (year = null) => {
        try {
            const response = await budgetFrameworkService.getPositionGroups({
                fiscal_year: year ?? stateRef.current.fiscalYear,
            });
            setPositionGroups(response?.data || null);
            return response?.data || null;
        } catch {
            setPositionGroups(null);
            return null;
        }
    }, []);

    /** บันทึกการจับคู่ ตำแหน่ง → กลุ่มวิชาชีพ แล้วคำนวณใหม่ */
    const savePositionGroups = useCallback(
        async (mappings) => {
            setSavingGroups(true);
            try {
                const response = await budgetFrameworkService.savePositionGroups({
                    fiscal_year: stateRef.current.fiscalYear,
                    mappings,
                });
                setPositionGroups(response?.data || null);
                await fetchPreview();
                return { success: true, message: response?.message };
            } catch (err) {
                return {
                    success: false,
                    error: err.response?.data?.message || 'ไม่สามารถบันทึกกลุ่มวิชาชีพได้',
                };
            } finally {
                setSavingGroups(false);
            }
        },
        [fetchPreview],
    );

    // โหลดรายการที่บันทึกไว้ + ตำแหน่ง/กลุ่มวิชาชีพของปีงบ
    useEffect(() => {
        fetchSaved(fiscalYear);
        loadPositionGroups(fiscalYear);
    }, [fiscalYear, fetchSaved, loadPositionGroups]);

    const setField = useCallback((key, value) => {
        setParams((prev) => ({ ...prev, [key]: value }));
    }, []);

    const setWeight = useCallback((code, value) => {
        setParams((prev) => ({ ...prev, weights: { ...prev.weights, [code]: value } }));
    }, []);

    /** บันทึกกรอบวงเงินของปีงบ (บันทึกซ้ำ = อัปเดต) */
    const save = useCallback(async ({ note } = {}) => {
        const { params: current, fiscalYear: year } = stateRef.current;

        setSaving(true);
        try {
            const payload = { ...buildQuery(year, current) };
            if (note) payload.note = note;

            // buildQuery ตัด undefined ออกไม่ได้ — ส่งเฉพาะค่าที่มีความหมาย
            Object.keys(payload).forEach((key) => {
                if (payload[key] === undefined) delete payload[key];
            });

            const response = await budgetFrameworkService.save(payload);

            await fetchSaved(year);
            await refreshFiscalYears();

            return { success: true, message: response.message, data: response.data };
        } catch (err) {
            return {
                success: false,
                error: err.response?.data?.message || 'ไม่สามารถบันทึกกรอบวงเงินได้',
            };
        } finally {
            setSaving(false);
        }
    }, [buildQuery, fetchSaved, refreshFiscalYears]);

    /** ส่งออก Excel จากค่าที่แสดงอยู่ */
    const exportExcel = useCallback(async () => {
        const { params: current, fiscalYear: year } = stateRef.current;

        const query = { ...buildQuery(year, current) };
        Object.keys(query).forEach((key) => {
            if (query[key] === undefined) delete query[key];
        });

        await budgetFrameworkService.exportExcel(query, `budget-framework-FY${year}.xlsx`);
    }, [buildQuery]);

    /** ส่งออก Excel จากกรอบวงเงินที่บันทึกไว้ */
    const exportSaved = useCallback(async (id, fiscalYearOfSaved) => {
        await budgetFrameworkService.exportSaved(id, `budget-framework-FY${fiscalYearOfSaved}.xlsx`);
    }, []);

    const savedForYear = savedList.find((item) => item.fiscal_year === fiscalYear) || null;

    return {
        fiscalYear,
        options,
        params,
        setField,
        setWeight,
        data,
        summary: data?.data || null,
        meta: data?.meta || null,
        savedForYear,
        savedList,
        positionGroups,
        loading,
        saving,
        savingGroups,
        error,
        save,
        savePositionGroups,
        exportExcel,
        exportSaved,
        refetch: () => fetchPreview(),
    };
};

export default useBudgetFramework;
