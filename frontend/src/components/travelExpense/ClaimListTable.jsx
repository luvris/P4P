import StatusBadge from './StatusBadge';
import ClaimRowActions from './ClaimRowActions';
import { formatMoney, formatDateTime } from '../../utils/travelExpense';

const COLUMNS = [
    { label: 'เลขที่เอกสาร' },
    { label: 'เดือนที่เบิก' },
    { label: 'ผู้เบิก (คน)', align: 'text-right' },
    { label: 'ยอดรวม (บาท)', align: 'text-right' },
    { label: 'สถานะ' },
    { label: 'ผู้สร้าง' },
    { label: 'วันที่สร้าง' },
    { label: 'จัดการ' },
];

/** ตารางรายการใบเบิก */
const ClaimListTable = ({ claims, loading, fiscalYear, onConfirm, onCancel, onExport }) => (
    <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table className="w-full text-sm">
            <caption className="sr-only">
                รายการใบเบิกค่าใช้จ่ายปีงบประมาณ {fiscalYear}
            </caption>
            <thead>
                <tr className="bg-gray-50 text-left text-gray-600">
                    {COLUMNS.map((column) => (
                        <th
                            key={column.label}
                            scope="col"
                            className={`px-4 py-3 font-semibold ${column.align || ''}`}
                        >
                            {column.label}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
                {loading && (
                    <tr>
                        <td colSpan={COLUMNS.length} className="px-4 py-8 text-center text-gray-500">
                            กำลังโหลดข้อมูล...
                        </td>
                    </tr>
                )}

                {!loading && claims.length === 0 && (
                    <tr>
                        <td colSpan={COLUMNS.length} className="px-4 py-10 text-center text-gray-500">
                            ยังไม่มีใบเบิกในปีงบประมาณ {fiscalYear} กด สร้างใบเบิก เพื่อเริ่มต้น
                        </td>
                    </tr>
                )}

                {!loading && claims.map((claim) => (
                    <tr key={claim.id} className="hover:bg-amber-50/40">
                        <td className="px-4 py-3 font-medium text-gray-800">{claim.document_no}</td>
                        <td className="px-4 py-3 text-gray-600">{claim.period_label || '-'}</td>
                        <td className="px-4 py-3 text-right text-gray-600">{claim.items_count}</td>
                        <td className="px-4 py-3 text-right text-gray-700">
                            {formatMoney(claim.total_amount)}
                        </td>
                        <td className="px-4 py-3"><StatusBadge status={claim.status} /></td>
                        <td className="px-4 py-3 text-gray-600">{claim.created_by_name || '-'}</td>
                        <td className="px-4 py-3 text-gray-600">{formatDateTime(claim.created_at)}</td>
                        <td className="px-4 py-3">
                            <ClaimRowActions
                                claim={claim}
                                onConfirm={onConfirm}
                                onCancel={onCancel}
                                onExport={onExport}
                            />
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    </div>
);

export default ClaimListTable;
