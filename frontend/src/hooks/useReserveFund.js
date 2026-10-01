import { useState, useEffect, useCallback, useRef } from 'react';
import { reserveFundService } from '../services/reserveFundService';
import useFiscalYear from './useFiscalYear';

/**
 * เงินสำรองรายปี — 1 ปีงบประมาณ = 1 ผลลัพธ์
 *
 * โหลดสรุปของปีงบที่เลือกจาก Header, คำนวณตามเปอร์เซ็นต์ที่กรอก,
 * บันทึกร่าง และยืนยัน/ยกเลิกการยืนยันยอดรายปี
 */
const useReserveFund = () => {
    const { fiscalYear, refreshFiscalYears } = useFiscalYear();

    // ไม่มีค่าเริ่มต้น — ผู้ใช้ต้องกรอกเปอร์เซ็นต์เอง
    const [percent, setPercent] = useState('');
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    // เก็บค่าล่าสุดไว้ใช้ตอน refetch โดยไม่ต้องผูก dependency
    const stateRef = useRef({ percent: '', fiscalYear });
    useEffect(() => {
        stateRef.current = { percent, fiscalYear };
    }, [percent, fiscalYear]);

    const fetchSummary = useCallback(async (selectedPercent = undefined, selectedFiscalYear = null) => {
        setLoading(true);
        setError('');
        try {
            const pct = selectedPercent ?? stateRef.current.percent;
            const year = selectedFiscalYear ?? stateRef.current.fiscalYear;

            const params = {};
            if (pct !== '' && pct !== null && pct !== undefined) params.percent = pct;
            if (year) params.fiscal_year = year;

            const response = await reserveFundService.getAnnual(params);
            setData(response);
            return { success: true, data: response };
        } catch (err) {
            let message = 'ไม่สามารถโหลดข้อมูลเงินสำรองได้';
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

    /**
     * โหลดสรุปของปีงบ — ถ้าปีนั้นมีผลบันทึกไว้แล้ว ใช้เปอร์เซ็นต์ที่บันทึกมาแสดง
     * (ไม่ใช่ค่า default — เป็นค่าที่ผู้ใช้เคยกรอกและกดบันทึกไว้เอง)
     */
    const loadSummary = useCallback(async (selectedFiscalYear = null) => {
        const currentPercent = stateRef.current.percent;
        const year = selectedFiscalYear ?? stateRef.current.fiscalYear;

        const first = await fetchSummary(currentPercent, year);
        if (!first.success) return first;

        const savedPercent = first.data?.summary?.saved?.percent;
        if (savedPercent === null || savedPercent === undefined) return first;
        if (Number(currentPercent) === Number(savedPercent)) return first;

        setPercent(String(savedPercent));
        return fetchSummary(savedPercent, year);
    }, [fetchSummary]);

    // โหลดครั้งแรก
    useEffect(() => {
        loadSummary(stateRef.current.fiscalYear);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // เปลี่ยนปีงบประมาณจาก Header — โหลดผลของปีนั้น (ข้ามปีที่โหลดไปแล้วตอน mount)
    const loadedYearRef = useRef(fiscalYear);
    useEffect(() => {
        if (loadedYearRef.current === fiscalYear) return;
        loadedYearRef.current = fiscalYear;
        loadSummary(fiscalYear);
    }, [fiscalYear, loadSummary]);

    /**
     * คำนวณใหม่ด้วยเปอร์เซ็นต์ที่กรอก — ค่าว่าง/นอกช่วง 0-100 ไม่ยิง request
     */
    const applyPercent = useCallback((value) => {
        const numeric = Number(value);
        if (value === '' || Number.isNaN(numeric) || numeric < 0 || numeric > 100) {
            return;
        }
        fetchSummary(numeric);
    }, [fetchSummary]);

    /**
     * บันทึกผลการคำนวณรายปี (สถานะ draft)
     */
    const saveCalculation = useCallback(async ({ note } = {}) => {
        const { percent: currentPercent, fiscalYear: currentFiscal } = stateRef.current;
        const numeric = Number(currentPercent);

        if (currentPercent === '' || Number.isNaN(numeric)) {
            return { success: false, error: 'กรุณาระบุเปอร์เซ็นต์ก่อนบันทึก' };
        }

        setSaving(true);
        try {
            const payload = { percent: numeric, fiscal_year: currentFiscal };
            if (note) payload.note = note;

            const response = await reserveFundService.saveAnnual(payload);

            await fetchSummary(numeric, currentFiscal);
            await refreshFiscalYears();

            return { success: true, data: response.data, message: response.message };
        } catch (err) {
            let message = 'ไม่สามารถบันทึกผลการคำนวณได้';
            if (err.response) {
                message = err.response.data?.message || `เกิดข้อผิดพลาด (${err.response.status})`;
            } else if (err.request) {
                message = 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้';
            }
            return { success: false, error: message };
        } finally {
            setSaving(false);
        }
    }, [fetchSummary, refreshFiscalYears]);

    /**
     * ยืนยัน / ยกเลิกการยืนยันยอดรายปี
     */
    const setAnnualConfirmation = useCallback(async (calculationId, confirmed) => {
        if (!calculationId) {
            return { success: false, error: 'กรุณาบันทึกผลการคำนวณของปีนี้ก่อน' };
        }

        setSaving(true);
        try {
            const response = confirmed
                ? await reserveFundService.confirmCalculation(calculationId)
                : await reserveFundService.unconfirmCalculation(calculationId);

            await fetchSummary();

            return { success: true, data: response.data, message: response.message };
        } catch (err) {
            let message = confirmed ? 'ไม่สามารถยืนยันรายปีได้' : 'ไม่สามารถยกเลิกการยืนยันได้';
            if (err.response) {
                message = err.response.data?.message || `เกิดข้อผิดพลาด (${err.response.status})`;
            } else if (err.request) {
                message = 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้';
            }
            return { success: false, error: message };
        } finally {
            setSaving(false);
        }
    }, [fetchSummary]);

    return {
        fiscalYear,
        percent,
        setPercent,
        applyPercent,
        data,
        summary: data?.summary || null,
        duties: data?.data?.duties || [],
        loading,
        saving,
        error,
        saveCalculation,
        setAnnualConfirmation,
        refetch: () => fetchSummary(),
    };
};

export default useReserveFund;
