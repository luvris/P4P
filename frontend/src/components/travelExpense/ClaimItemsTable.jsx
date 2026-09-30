import { Trash2 } from 'lucide-react';
import { AMOUNT_FIELDS, AMOUNT_LABELS, formatMoney, rowTotal } from '../../utils/travelExpense';

/**
 * ตารางรายการผู้เบิก + แถวยอดรวม
 *
 * รวมเงินต่อแถวคำนวณอัตโนมัติ แก้ไขโดยตรงไม่ได้ (backend คำนวณซ้ำอีกครั้ง)
 */
const ClaimItemsTable = ({ items, totals, readOnly, onAmountChange, onRemove }) => (
    <div className="overflow-x-auto">
        <table className="w-full text-sm">
            <caption className="sr-only">รายการผู้เบิกค่าใช้จ่าย</caption>
            <thead>
                <tr className="bg-gray-50 text-left text-gray-600">
                    <th scope="col" className="px-3 py-2 font-semibold">ลำดับ</th>
                    <th scope="col" className="px-3 py-2 font-semibold">ชื่อ</th>
                    <th scope="col" className="px-3 py-2 font-semibold">นามสกุล</th>
                    {AMOUNT_FIELDS.map((field) => (
                        <th key={field} scope="col" className="px-3 py-2 text-right font-semibold">
                            {AMOUNT_LABELS[field]}
                        </th>
                    ))}
                    <th scope="col" className="px-3 py-2 text-right font-semibold">รวมเงิน</th>
                    {!readOnly && <th scope="col" className="px-3 py-2 font-semibold sr-only">จัดการ</th>}
                </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
                {items.length === 0 && (
                    <tr>
                        <td colSpan={readOnly ? 8 : 9} className="px-3 py-8 text-center text-gray-500">
                            ยังไม่มีผู้เบิกในเอกสารนี้  กด + เพิ่มผู้เบิก เพื่อเลือกบุคลากร
                        </td>
                    </tr>
                )}

                {items.map((item, index) => (
                    <tr key={item.employee_id ?? `row-${index}`}>
                        <td className="px-3 py-2 text-center text-gray-500">{index + 1}</td>
                        <td className="px-3 py-2 text-gray-800">
                            {item.first_name}
                            {item.citizen_id && (
                                <span className="block text-xs text-gray-500">
                                    เลขบัตรประชาชน: {item.citizen_id}
                                </span>
                            )}
                        </td>
                        <td className="px-3 py-2 text-gray-800">{item.last_name}</td>

                        {AMOUNT_FIELDS.map((field) => (
                            <td key={field} className="px-3 py-2 text-right">
                                {readOnly ? (
                                    <span className="text-gray-700">{formatMoney(item[field])}</span>
                                ) : (
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={item[field]}
                                        onChange={(e) => onAmountChange(index, field, e.target.value)}
                                        aria-label={`${AMOUNT_LABELS[field]} ของ ${item.first_name} ${item.last_name}`}
                                        className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm focus:border-transparent focus:outline-none focus:ring-2 focus:ring-amber-500"
                                    />
                                )}
                            </td>
                        ))}

                        <td className="px-3 py-2 text-right font-medium text-[#8B5E3C]">
                            {formatMoney(rowTotal(item))}
                        </td>

                        {!readOnly && (
                            <td className="px-3 py-2 text-center">
                                <button
                                    type="button"
                                    onClick={() => onRemove(index)}
                                    title={`ลบ ${item.first_name} ${item.last_name}`}
                                    className="rounded-lg p-1.5 text-red-600 hover:bg-red-50"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </td>
                        )}
                    </tr>
                ))}
            </tbody>
            <tfoot>
                <tr className="bg-[#FBF7EE] font-semibold text-[#8B5E3C]">
                    <td className="px-3 py-2" colSpan={3}>
                        รวม ({totals.people ?? items.length} คน)
                    </td>
                    {AMOUNT_FIELDS.map((field) => (
                        <td key={field} className="px-3 py-2 text-right">
                            {formatMoney(totals[field])}
                        </td>
                    ))}
                    <td className="px-3 py-2 text-right">{formatMoney(totals.total_amount)}</td>
                    {!readOnly && <td className="px-3 py-2" />}
                </tr>
            </tfoot>
        </table>
    </div>
);

export default ClaimItemsTable;
