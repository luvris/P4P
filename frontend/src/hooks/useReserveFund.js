import { useState, useEffect, useCallback, useRef } from 'react';
import { reserveFundService } from '../services/reserveFundService';
import useFiscalYear from './useFiscalYear';
import { currentMonth } from '../utils/fiscalPeriod';

const useReserveFund = () => {
    // ปีงบประมาณมาจากตัวเลือกบน Header (ใช้ร่วมกันทั้งแอป)
    const { fiscalYear, refreshFiscalYears } = useFiscalYear();

    const [imports, setImports] = useState([]);
    const [importId, setImportId] = useState('');
    // ไม่มีค่าเริ่มต้น — ผู้ใช้ต้องกรอกเปอร์เซ็นต์เอง
    const [percent, setPercent] = useState('');
    // งวด payroll ที่กำลังทำงานอยู่ (1-12) เริ่มที่เดือนปัจจุบัน
    const [periodMonth, setPeriodMonth] = useState(currentMonth);
    const [accumulated, setAccumulated] = useState(null);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    // เก็บค่าล่าสุดไว้ใช้ตอน refetch โดยไม่ต้องผูก dependency
    const stateRef = useRef({ importId: '', percent: '', fiscalYear, periodMonth: currentMonth() });
    useEffect(() => {
        stateRef.current = { importId, percent, fiscalYear, periodMonth };
    }, [importId, percent, fiscalYear, periodMonth]);

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

    const fetchSummary = useCallback(async (selectedImportId = '', selectedPercent = '', selectedFiscalYear = null, selectedPeriodMonth = null) => {
        setLoading(true);
        setError('');
        try {
            const params = {};
            if (selectedImportId) params.import_id = selectedImportId;
            if (selectedPercent !== '' && selectedPercent !== null && selectedPercent !== undefined) {
                params.percent = selectedPercent;
            }
            const year = selectedFiscalYear ?? stateRef.current.fiscalYear;
            if (year) params.fiscal_year = year;

            const month = selectedPeriodMonth ?? stateRef.current.periodMonth;
            if (month) params.period_month = month;

            const response = await reserveFundService.getSummary(params);
            setData(response);
            setAccumulated(response.accumulated || null);
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
     * โหลดสรุปของปีงบ + งวด + ชุดข้อมูลที่เลือก
     * ถ้างวดนั้นมีผลการคำนวณบันทึกไว้ ให้ดึงเปอร์เซ็นต์ที่บันทึกมาแสดงผลด้วย
     * (ไม่ใช่ default percentage — เป็นค่าที่ผู้ใช้เคยกรอกและกดบันทึกไว้เอง)
     * ถ้างวดนั้นยังไม่มีผลบันทึก จะคงเปอร์เซ็นต์ที่ผู้ใช้กรอกอยู่ไว้
     */
    const loadSummary = useCallback(async (selectedImportId, selectedFiscalYear, selectedPeriodMonth = null) => {
        const currentPercent = stateRef.current.percent;
        const month = selectedPeriodMonth ?? stateRef.current.periodMonth;

        const first = await fetchSummary(selectedImportId, currentPercent, selectedFiscalYear, month);
        if (!first.success) return first;

        const savedPercent = first.data?.summary?.saved?.percent;
        if (savedPercent === null || savedPercent === undefined) return first;
        if (Number(currentPercent) === Number(savedPercent)) return first;

        setPercent(String(savedPercent));
        return fetchSummary(selectedImportId, savedPercent, selectedFiscalYear, month);
    }, [fetchSummary]);

    useEffect(() => {
        (async () => {
            const list = await fetchImports();
            const latest = list.length > 0 ? list[0].id : '';
            if (latest) setImportId(latest);
            await loadSummary(latest, stateRef.current.fiscalYear);
        })();
    }, [fetchImports, loadSummary]);

    const handleImportChange = useCallback((id) => {
        setImportId(id);
        loadSummary(id, stateRef.current.fiscalYear);
    }, [loadSummary]);

    /**
     * เปลี่ยนงวด payroll — โหลดผลการคำนวณที่บันทึกไว้ของงวดนั้น
     */
    const handlePeriodMonthChange = useCallback((month) => {
        const numeric = Number(month);
        if (!Number.isInteger(numeric) || numeric < 1 || numeric > 12) return;
        setPeriodMonth(numeric);
        loadSummary(stateRef.current.importId, stateRef.current.fiscalYear, numeric);
    }, [loadSummary]);

    /**
     * เปลี่ยนปีงบประมาณจาก Header — โหลดผลการคำนวณที่บันทึกไว้ของปีนั้น
     * (ข้ามปีที่โหลดไปแล้ว เพื่อไม่ยิงซ้ำตอน mount)
     */
    const loadedYearRef = useRef(fiscalYear);
    useEffect(() => {
        if (loadedYearRef.current === fiscalYear) return;
        loadedYearRef.current = fiscalYear;
        loadSummary(stateRef.current.importId, fiscalYear);
    }, [fiscalYear, loadSummary]);

    /**
     * คำนวณใหม่ด้วยเปอร์เซ็นต์ที่กรอก
     * ค่าว่างหรือนอกช่วง 0-100 จะไม่ยิง request
     */
    const applyPercent = useCallback((value) => {
        const numeric = Number(value);
        if (value === '' || Number.isNaN(numeric) || numeric < 0 || numeric > 100) {
            return;
        }
        fetchSummary(stateRef.current.importId, numeric);
    }, [fetchSummary]);

    /**
     * บันทึกผลการคำนวณของงวด payroll ภายใต้ปีงบประมาณ (สถานะ draft)
     */
    const saveCalculation = useCallback(async ({ fiscalYear: overrideYear, note } = {}) => {
        const {
            importId: currentImportId,
            percent: currentPercent,
            fiscalYear: currentFiscal,
            periodMonth: currentPeriod,
        } = stateRef.current;
        const numeric = Number(currentPercent);

        if (currentPercent === '' || Number.isNaN(numeric)) {
            return { success: false, error: 'กรุณาระบุเปอร์เซ็นต์ก่อนบันทึก' };
        }

        const targetYear = overrideYear || currentFiscal;

        setSaving(true);
        try {
            const payload = { percent: numeric, period_month: currentPeriod };
            if (currentImportId) payload.import_id = currentImportId;
            if (targetYear) payload.fiscal_year = targetYear;
            if (note) payload.note = note;

            const response = await reserveFundService.saveCalculation(payload);

            // โหลดสรุปใหม่เพื่อให้สถานะ "บันทึกแล้ว" อัปเดต
            await fetchSummary(currentImportId, numeric, targetYear, currentPeriod);

            // ปีงบที่เพิ่งบันทึกต้องปรากฏใน dropdown บน Header
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
     * ยืนยัน / ยกเลิกการยืนยันงวด — ยอดสะสมนับเฉพาะงวดที่ยืนยันแล้ว
     */
    const setPeriodConfirmation = useCallback(async (calculationId, confirmed) => {
        if (!calculationId) {
            return { success: false, error: 'กรุณาบันทึกผลการคำนวณของงวดนี้ก่อน' };
        }

        setSaving(true);
        try {
            const response = confirmed
                ? await reserveFundService.confirmCalculation(calculationId)
                : await reserveFundService.unconfirmCalculation(calculationId);

            const { importId: id, percent: pct, fiscalYear: year, periodMonth: month } = stateRef.current;
            await fetchSummary(id, pct, year, month);

            return { success: true, data: response.data, message: response.message };
        } catch (err) {
            let message = confirmed ? 'ไม่สามารถยืนยันงวดนี้ได้' : 'ไม่สามารถยกเลิกการยืนยันได้';
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
        imports,
        importId,
        percent,
        setPercent,
        applyPercent,
        fiscalYear,
        periodMonth,
        handlePeriodMonthChange,
        accumulated,
        data,
        loading,
        saving,
        error,
        handleImportChange,
        saveCalculation,
        setPeriodConfirmation,
        refetch: () => fetchSummary(
            stateRef.current.importId,
            stateRef.current.percent,
            stateRef.current.fiscalYear,
            stateRef.current.periodMonth,
        ),
    };
};

export default useReserveFund;