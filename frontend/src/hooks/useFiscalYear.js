import { useContext } from 'react';
import FiscalYearContext from '../contexts/fiscalYearContext';

/**
 * อ่าน/เปลี่ยนปีงบประมาณที่เลือกอยู่ (ตัวเลือกอยู่บน Header)
 */
const useFiscalYear = () => {
    const context = useContext(FiscalYearContext);
    if (!context) {
        throw new Error('useFiscalYear ต้องใช้ภายใน <FiscalYearProvider>');
    }
    return context;
};

export default useFiscalYear;
