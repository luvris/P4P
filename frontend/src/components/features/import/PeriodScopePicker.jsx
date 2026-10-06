import React from 'react';
import { CalendarRange } from 'lucide-react';
import { FISCAL_MONTHS, MONTH_LABELS, calendarYearOf } from '../../../utils/fiscalPeriod';

/**
 * ตัวเลือกขอบเขตงวดตอนนำเข้า
 *
 * ไฟล์ที่มีคอลัมน์ปี/เดือนของแต่ละแถว ระบบจะใช้งวดนั้นเสมอ
 * ค่าที่เลือกที่นี่มีไว้สองกรณี:
 *   - งวดเดียว  = เติมงวดให้แถวที่ไฟล์ไม่ได้ระบุเดือน
 *   - หลายงวด/ทั้งปีงบ = ยืนยันว่าไฟล์นี้ครอบคลุมงวดที่เลือก (ระบบจะไม่เดาให้ถ้าไฟล์ไม่บอก)
 */
const SCOPES = [
    { value: 'month', label: 'งวดเดียว' },
    { value: 'months', label: 'หลายงวด' },
    { value: 'year', label: 'ทั้งปีงบประมาณ' },
];

const PeriodScopePicker = ({ scope, onScopeChange, months, onMonthsChange, fiscalYear, onFiscalYearChange, fiscalYearOptions }) => {
    const toggleMonth = (month) => {
        onMonthsChange(
            months.includes(month)
                ? months.filter((m) => m !== month)
                : [...months, month].sort((a, b) => a - b)
        );
    };

    return (
        <div className="rounded-2xl border border-[#E6D3A3] bg-white p-5">
            <div className="mb-3 flex items-start gap-2">
                <CalendarRange className="mt-0.5 h-4 w-4 shrink-0 text-[#C5A059]" />
                <div>
                    <h3 className="text-base font-semibold text-gray-700">งวดของไฟล์ (Payroll)</h3>
                    <p className="mt-0.5 text-xs text-gray-500">
                        ไฟล์ที่ระบุปี/เดือนในแต่ละแถว ระบบจะใช้ค่านั้นของแถวนั้นเสมอ
                        ตัวเลือกนี้ใช้กับแถวที่ไม่ได้ระบุ และใช้ตรวจว่าไฟล์ตรงกับงวดที่เลือก
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-3 gap-1 rounded-xl bg-[#F5EEDC]/60 p-1">
                {SCOPES.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={scope === option.value}
                        onClick={() => onScopeChange(option.value)}
                        className={`rounded-lg px-1.5 py-1.5 text-[13px] font-medium leading-tight transition-colors ${
                            scope === option.value
                                ? 'bg-white text-[#8B5E3C] shadow-sm'
                                : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            {scope === 'month' && (
                <div className="mt-3">
                    <label htmlFor="import-period-month" className="mb-1 block text-sm font-medium text-gray-700">
                        งวดเดือน
                    </label>
                    <select
                        id="import-period-month"
                        value={months[0] ?? ''}
                        onChange={(e) => onMonthsChange([Number(e.target.value)])}
                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                    >
                        {FISCAL_MONTHS.map((month) => (
                            <option key={month} value={month}>
                                {MONTH_LABELS[month]} {calendarYearOf(fiscalYear, month)}
                            </option>
                        ))}
                    </select>
                    <p className="mt-2 text-xs text-gray-500">
                        แถวที่ไม่ได้ระบุเดือนจะถูกบันทึกเป็นงวดนี้
                    </p>
                </div>
            )}

            {scope === 'months' && (
                <div className="mt-3">
                    <label htmlFor="import-months-fiscal-year" className="mb-1 block text-sm font-medium text-gray-700">
                        ปีงบประมาณที่ไฟล์นี้อยู่
                    </label>
                    <select
                        id="import-months-fiscal-year"
                        value={fiscalYear}
                        onChange={(e) => onFiscalYearChange(Number(e.target.value))}
                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                    >
                        {fiscalYearOptions.map((year) => (
                            <option key={year} value={year}>{year}</option>
                        ))}
                    </select>

                    <p className="mb-1 mt-3 text-sm font-medium text-gray-700">งวดที่ไฟล์นี้ครอบคลุม (เลือกได้หลายเดือน)</p>
                    <div className="grid grid-cols-6 gap-1.5">
                        {FISCAL_MONTHS.map((month) => {
                            const active = months.includes(month);

                            return (
                                <button
                                    key={month}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => toggleMonth(month)}
                                    className={`rounded-lg border px-2 py-1.5 text-xs font-medium transition-colors ${
                                        active
                                            ? 'border-[#8B5E3C] bg-[#F5EEDC] text-[#8B5E3C]'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-[#C5A059]'
                                    }`}
                                >
                                    {MONTH_LABELS[month]}
                                </button>
                            );
                        })}
                    </div>
                    <p className="mt-2 text-xs text-gray-500">
                        เลือกแล้ว {months.length} งวด — ทุกงวดในไฟล์ต้องอยู่ในรายการนี้
                        ไม่งั้นระบบจะหยุดและบอกงวดที่ไม่ตรง
                    </p>
                </div>
            )}

            {scope === 'year' && (
                <div className="mt-3">
                    <label htmlFor="import-fiscal-year" className="mb-1 block text-sm font-medium text-gray-700">
                        ปีงบประมาณที่ไฟล์นี้ครอบคลุม
                    </label>
                    <select
                        id="import-fiscal-year"
                        value={fiscalYear}
                        onChange={(e) => onFiscalYearChange(Number(e.target.value))}
                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                    >
                        {fiscalYearOptions.map((year) => (
                            <option key={year} value={year}>{year}</option>
                        ))}
                    </select>
                    <p className="mt-2 text-xs text-gray-500">
                        ปีงบประมาณหนึ่งครอบคลุม ต.ค. {fiscalYear - 1} – ก.ย. {fiscalYear}
                        ทุกงวดในไฟล์ต้องอยู่ในช่วงนี้
                    </p>
                </div>
            )}
        </div>
    );
};

export default PeriodScopePicker;