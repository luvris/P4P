import React from 'react';

/**
 * ตาราง preview ข้อมูล payroll
 *
 * ไฟล์มี 2 รูปแบบ:
 *  - รูปแบบใหม่ (39 คอลัมน์) มีงวดต่อแถว, คำนำหน้า, ตำแหน่ง, ยอดรวมรายรับทั้งหมด
 *  - รูปแบบเดิม มีรับจริงแทนยอดรวมรายรับ
 *
 * จึงเลือกคอลัมน์จากข้อมูลจริงในแถวแรก ไม่ใช่คอลัมน์ตายตัว เพื่อให้ทั้งสองรูปแบบแสดงถูก
 */

const THAI_MONTHS = {
  1: 'ม.ค.', 2: 'ก.พ.', 3: 'มี.ค.', 4: 'เม.ย.',
  5: 'พ.ค.', 6: 'มิ.ย.', 7: 'ก.ค.', 8: 'ส.ค.',
  9: 'ก.ย.', 10: 'ต.ค.', 11: 'พ.ย.', 12: 'ธ.ค.',
};

const MONEY_FIELDS = new Set([
  'salary', 'salary_deduction', 'project_budget', 'regular_allowance',
  'living_allowance', 'no_medical_service', 'position_allowance', 'overtime',
  'night_shift_budget', 'night_shift_maintenance', 'p4p_monthly', 'covid_allowance',
  'p4p_quality_project', 'other_income', 'total_direct_income', 'total_income',
  'total_indirect_income', 'medical_treatment', 'education_allowance',
  'meal_allowance', 'housing_allowance', 'transport_allowance', 'other_expenses',
  'project_cost', 'social_security_employer', 'provident_fund',
  'total_deduction', 'net_income', 'payroll',
]);

const COLUMN_LABELS = {
  seq_number: 'ลำดับที่',
  period_year: 'ปี (พ.ศ.)',
  fiscal_year: 'ปีงบประมาณ',
  period_month: 'งวดเดือน',
  prefix: 'คำนำหน้า',
  first_name: 'ชื่อ',
  last_name: 'นามสกุล',
  employee_type: 'ประเภท',
  position_name: 'ตำแหน่ง',
  position_number: 'ตำแหน่งเลขที่',
  citizen_id: 'เลขบัตรประชาชน',
  bank_account: 'เลขที่บัญชี',
  salary: 'เงินเดือน',
  salary_deduction: 'ตกเบิก',
  project_budget: 'ง.บ.ส.ก.',
  regular_allowance: 'ปจต.',
  living_allowance: 'ค่าครองชีพ',
  no_medical_service: 'ไม่ทำเวชฯ',
  position_allowance: 'พตส.',
  overtime: 'ค่า OT',
  night_shift_budget: 'บ่าย-ดึก เงินงบประมาณ',
  night_shift_maintenance: 'บ่าย-ดึก เงินบำรุง',
  p4p_monthly: 'P4P ประจำเดือน',
  covid_allowance: 'ค่าตอบแทน COVID-19',
  p4p_quality_project: 'P4P โครงการคุณภาพ',
  other_income: 'รายได้อื่น',
  total_direct_income: 'รวมรายรับทางตรง',
  medical_treatment: 'ค่ารักษา',
  education_allowance: 'ค่าเล่าเรียน',
  meal_allowance: 'ค่าเบี้ยเลี้ยง',
  housing_allowance: 'ค่าเช่าที่พัก',
  transport_allowance: 'ค่าพาหนะ',
  other_expenses: 'ค่าใช้จ่ายอื่น ๆ',
  project_cost: 'ต้นทุนจัดโครงการ',
  social_security_employer: 'ประกันสังคม นายจ้าง',
  provident_fund: 'กองทุนสำรองเลี้ยงชีพ',
  total_indirect_income: 'รวมรายรับทางอ้อม',
  total_income: 'ยอดรวมรายรับทั้งหมด',
  total_deduction: 'รวมรายจ่าย',
  net_income: 'รับจริง',
  note: 'หมายเหตุ',
};

/**
 * ลำดับคอลัมน์ที่แสดง — คัดจากข้อมูลจริงในแถวแรก
 * คอลัมน์สำคัญขึ้นก่อน ที่เหลือเรียงตามลำดับที่ปรากฏในข้อมูล
 */
const COLUMN_ORDER = [
  'seq_number', 'period_year', 'fiscal_year', 'period_month',
  'prefix', 'first_name', 'last_name', 'employee_type',
  'position_name', 'position_number', 'citizen_id', 'bank_account',
  'salary', 'salary_deduction', 'project_budget', 'regular_allowance',
  'living_allowance', 'no_medical_service', 'position_allowance', 'overtime',
  'night_shift_budget', 'night_shift_maintenance',
  'p4p_monthly', 'covid_allowance', 'p4p_quality_project', 'other_income',
  'total_direct_income',
  'medical_treatment', 'education_allowance', 'meal_allowance',
  'housing_allowance', 'transport_allowance', 'other_expenses', 'project_cost',
  'social_security_employer', 'provident_fund', 'total_indirect_income',
  'total_income', 'total_deduction', 'net_income', 'note',
];

const pickColumns = (firstRow) => {
  if (!firstRow) return [];

  const present = new Set(Object.keys(firstRow));
  const ordered = COLUMN_ORDER.filter((key) => present.has(key));

  // คอลัมน์ที่ไม่รู้จักในลำดับหลัก (เช่นไฟล์เก่าที่มีฟิลด์เพิ่ม) แต่มีค่าจริง → แสดงต่อท้าย
  Object.keys(firstRow).forEach((key) => {
    if (!ordered.includes(key) && !key.startsWith('_')) {
      ordered.push(key);
    }
  });

  return ordered;
};

const formatNumber = (value) => {
  if (value === null || value === undefined || value === '') return '-';

  const num = typeof value === 'string' ? parseFloat(value) : value;
  if (Number.isNaN(num)) return String(value);

  return new Intl.NumberFormat('th-TH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(num);
};

const formatCell = (row, key) => {
  const value = row[key];

  if (value === null || value === undefined || value === '') return '-';

  if (key === 'period_month') {
    return THAI_MONTHS[value] ?? String(value);
  }

  if (MONEY_FIELDS.has(key)) {
    return formatNumber(value);
  }

  return String(value);
};

const PreviewTable = ({ data = [] }) => {
  if (data.length === 0) return null;

  const columns = pickColumns(data[0]);
  const isNumeric = (key) => MONEY_FIELDS.has(key);

  return (
    <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-gray-700">ตัวอย่างข้อมูล</h3>
        <span className="text-xs text-gray-500">แสดง {data.length} แถวแรก</span>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[#C5A059] text-white">
              <th className="px-3 py-2 text-left font-medium">#</th>
              {columns.map((col) => (
                <th key={col} className="px-3 py-2 text-left font-medium whitespace-nowrap">
                  {COLUMN_LABELS[col] || col}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.map((row, rowIndex) => (
              <tr
                key={rowIndex}
                className={`border-b border-gray-100 ${
                  rowIndex % 2 === 0 ? 'bg-white' : 'bg-[#FDFBF7]'
                } hover:bg-[#F5EEDC]/30 transition-colors`}
              >
                <td className="px-3 py-2 text-gray-500">{rowIndex + 1}</td>
                {columns.map((col) => (
                  <td
                    key={col}
                    className={`px-3 py-2 whitespace-nowrap text-gray-700 ${
                      isNumeric(col) ? 'text-right tabular-nums' : ''
                    }`}
                  >
                    {formatCell(row, col)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-4 text-xs text-gray-500 text-center">
        * แสดงตัวอย่างเพียง 10 แถวแรก — ข้อมูลจริงจะถูกนำเข้าทั้งหมด
      </div>
    </div>
  );
};

export default PreviewTable;