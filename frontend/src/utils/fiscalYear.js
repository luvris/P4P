/**
 * ปีงบประมาณไทยปัจจุบัน (เริ่ม 1 ต.ค. → ต.ค.–ธ.ค. นับเป็นปีถัดไป)
 */
export const currentFiscalYear = () => {
    const now = new Date();
    const year = now.getFullYear() + 543;
    return now.getMonth() + 1 >= 10 ? year + 1 : year;
};

/** ช่วงปีงบประมาณที่ระบบรับได้ (ตรงกับ validation ฝั่ง backend) */
export const MIN_FISCAL_YEAR = 2500;
export const MAX_FISCAL_YEAR = 2700;

export const isValidFiscalYear = (year) =>
    Number.isInteger(year) && year >= MIN_FISCAL_YEAR && year <= MAX_FISCAL_YEAR;
