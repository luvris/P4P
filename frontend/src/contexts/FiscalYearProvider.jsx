import { useCallback, useEffect, useMemo, useState } from 'react';
import FiscalYearContext from './fiscalYearContext';
import { currentFiscalYear, isValidFiscalYear } from '../utils/fiscalYear';
import { fiscalYearService } from '../services/fiscalYearService';

const STORAGE_KEY = 'fiscalYear';

const readStored = () => {
    const stored = Number(localStorage.getItem(STORAGE_KEY));
    return isValidFiscalYear(stored) ? stored : currentFiscalYear();
};

export const FiscalYearProvider = ({ children }) => {
    const [fiscalYear, setFiscalYearState] = useState(readStored);
    // ปีงบที่มีข้อมูลจริงในระบบ + ปีงบปัจจุบัน (ตัวเลือกใน dropdown)
    const [fiscalYearOptions, setFiscalYearOptions] = useState([]);

    useEffect(() => {
        localStorage.setItem(STORAGE_KEY, String(fiscalYear));
    }, [fiscalYear]);

    const refreshFiscalYears = useCallback(async () => {
        // ยังไม่ login ก็ไม่ต้องยิง request (state เริ่มต้นเป็นลิสต์ว่างอยู่แล้ว)
        // ตรวจจาก 'user' เพราะระบบยืนยันตัวตนด้วย session cookie ไม่มี token ใน localStorage
        if (!localStorage.getItem('user')) {
            return [];
        }

        try {
            const response = await fiscalYearService.getFiscalYears();
            const years = (response.data || [])
                .map(Number)
                .filter(isValidFiscalYear)
                .sort((a, b) => b - a);
            setFiscalYearOptions(years);
            return years;
        } catch (err) {
            console.error('Failed to fetch fiscal years:', err);
            setFiscalYearOptions([]);
            return [];
        }
    }, []);

    useEffect(() => {
        (async () => {
            await refreshFiscalYears();
        })();
    }, [refreshFiscalYears]);

    const setFiscalYear = useCallback((year) => {
        const numeric = Number(year);
        if (!isValidFiscalYear(numeric)) return;
        setFiscalYearState(numeric);
    }, []);

    const value = useMemo(
        () => ({ fiscalYear, setFiscalYear, fiscalYearOptions, refreshFiscalYears }),
        [fiscalYear, setFiscalYear, fiscalYearOptions, refreshFiscalYears],
    );

    return <FiscalYearContext.Provider value={value}>{children}</FiscalYearContext.Provider>;
};

export default FiscalYearProvider;
