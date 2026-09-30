/**
 * งวดของปีงบประมาณไทย เรียงตามรอบปีงบ (ต.ค. → ก.ย.)
 */
export const FISCAL_MONTHS = [10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9];

export const MONTH_LABELS = {
    1: 'ม.ค.', 2: 'ก.พ.', 3: 'มี.ค.', 4: 'เม.ย.',
    5: 'พ.ค.', 6: 'มิ.ย.', 7: 'ก.ค.', 8: 'ส.ค.',
    9: 'ก.ย.', 10: 'ต.ค.', 11: 'พ.ย.', 12: 'ธ.ค.',
};

/** ปีปฏิทิน (พ.ศ.) ของงวดเดือนนั้น ภายในปีงบที่ระบุ */
export const calendarYearOf = (fiscalYear, month) =>
    month >= 10 ? fiscalYear - 1 : fiscalYear;

/** เดือนปัจจุบัน (1-12) ใช้เป็นงวดเริ่มต้นตอนเปิดหน้า */
export const currentMonth = () => new Date().getMonth() + 1;
