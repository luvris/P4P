import React from 'react';
import { FileText, CheckCircle, XCircle, Copy, MinusCircle } from 'lucide-react';

const ImportSummary = ({ summary }) => {
  const cards = [
    {
      label: 'จำนวนรายการทั้งหมด',
      value: summary?.total_rows || 0,
      unit: 'รายการ',
      icon: FileText,
      color: 'blue',
    },
    {
      label: 'พร้อมนำเข้า',
      value: summary?.success_rows || 0,
      unit: 'รายการ',
      icon: CheckCircle,
      color: 'green',
    },
    {
      label: 'ข้อมูลผิดพลาด',
      value: summary?.error_rows || 0,
      unit: 'รายการ',
      icon: XCircle,
      color: 'red',
    },
    {
      label: 'ซ้ำรายการซ้ำ',
      value: summary?.duplicate_rows || 0,
      unit: 'รายการ',
      icon: Copy,
      color: 'yellow',
    },
  ];

  // แถวรวมยอด/แถวที่ไม่มีเลขบัตรประชาชน — แสดงเฉพาะเมื่อมี เพื่อไม่ให้รกหน้าจอ
  if (Number(summary?.skipped_rows) > 0) {
    cards.push({
      label: 'ข้าม (ไม่ใช่บุคลากร)',
      value: summary.skipped_rows,
      unit: 'รายการ',
      icon: MinusCircle,
      color: 'gray',
    });
  }

  const colorClasses = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-green-50 text-green-600',
    red: 'bg-red-50 text-red-600',
    yellow: 'bg-yellow-50 text-yellow-600',
    gray: 'bg-gray-100 text-gray-600',
  };

  return (
    <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
      <h3 className="text-lg font-semibold text-gray-700 mb-4">
        สรุปผลการนำเข้า
      </h3>
      
      <div className="grid grid-cols-2 gap-4">
        {cards.map((card, i) => {
          const Icon = card.icon;
          return (
            <div key={i} className="border border-gray-100 rounded-xl p-4">
              <div className="flex items-center gap-2 mb-2">
                <div className={`w-8 h-8 rounded-lg flex items-center justify-center ${colorClasses[card.color]}`}>
                  <Icon size={16} />
                </div>
                <span className="text-xs text-gray-500">{card.label}</span>
              </div>
              <div className="text-2xl font-bold text-gray-700">
                {card.value.toLocaleString()}
              </div>
              <div className="text-xs text-gray-400 mt-1">{card.unit}</div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default ImportSummary;