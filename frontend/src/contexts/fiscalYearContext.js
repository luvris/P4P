import { createContext } from 'react';

/**
 * Context ของปีงบประมาณที่เลือกอยู่ — ใช้ร่วมกันทั้งแอป
 * แยกไฟล์ออกจาก Provider เพื่อให้ fast refresh ทำงานได้
 */
const FiscalYearContext = createContext(null);

export default FiscalYearContext;
