import React from 'react';

const PreviewTable = ({ data = [] }) => {
  if (data.length === 0) return null;

  // Column ที่ต้องการแสดง
  const columns = [
    { key: 'employee_type', label: 'ประเภท' },
    { key: 'first_name', label: 'ชื่อ' },
    { key: 'last_name', label: 'นามสกุล' },
    { key: 'citizen_id', label: 'เลขบัตรประชาชน' },
    { key: 'bank_account', label: 'เลขที่บัญชี' },
    { key: 'salary', label: 'เงินเดือน' },
    { key: 'net_income', label: 'รับจริง' },
  ];

  // Format ตัวเลข
  const formatNumber = (value) => {
    if (value === null || value === undefined || value === '') return '-';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return value;
    return new Intl.NumberFormat('th-TH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num);
  };

  return (
    <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-gray-700">
          ตัวอย่างข้อมูล
        </h3>
        <span className="text-xs text-gray-500">
          แสดง {data.length} แถวแรก
        </span>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[#C5A059] text-white">
              <th className="px-3 py-2 text-left font-medium">#</th>
              {columns.map((col) => (
                <th
                  key={col.key}
                  className="px-3 py-2 text-left font-medium whitespace-nowrap"
                >
                  {col.label}
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
                    key={col.key}
                    className="px-3 py-2 text-gray-700 whitespace-nowrap"
                  >
                    {col.key === 'salary' || col.key === 'net_income'
                      ? formatNumber(row[col.key])
                      : row[col.key] || '-'}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Footer */}
      <div className="mt-4 text-xs text-gray-500 text-center">
        * แสดงตัวอย่างเพียง 10 แถวแรก — ข้อมูลจริงจะถูกนำเข้าทั้งหมด
      </div>
    </div>
  );
};

export default PreviewTable;