import { formatMoney } from '../../utils/travelExpense';

/**
 * ตารางจัดอันดับบุคคลตามยอดเงินเบิกรวม (มาก → น้อย)
 * ภายใต้ขอบเขตและตัวกรองเดียวกันกับสรุปยอด — รวมบุคคลด้วยรหัสบุคคล ไม่ใช่ชื่อ
 */
const ClaimRankingTable = ({ ranking }) => (
    <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div className="border-b border-gray-200 px-4 py-3">
            <h3 className="text-sm font-semibold text-gray-800">
                จัดอันดับผู้เบิกตามยอดเงินเบิกรวม
            </h3>
        </div>

        <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
                <thead>
                    <tr className="bg-gray-50 text-left text-gray-600">
                        <th className="w-16 px-4 py-2.5 font-medium">อันดับ</th>
                        <th className="px-4 py-2.5 font-medium">ชื่อ-สกุล</th>
                        <th className="px-4 py-2.5 font-medium">รหัสบุคลากร</th>
                        <th className="px-4 py-2.5 text-right font-medium">จำนวนใบเบิก</th>
                        <th className="px-4 py-2.5 text-right font-medium">ยอดเบิกรวม (บาท)</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {ranking.map((person) => (
                        <tr key={person.person_key} className="hover:bg-gray-50">
                            <td className="px-4 py-3 tabular-nums text-gray-700">{person.rank}</td>
                            <td className="px-4 py-3 font-medium text-gray-800">{person.name}</td>
                            <td className="px-4 py-3 tabular-nums text-gray-600">{person.pid || '-'}</td>
                            <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                {person.claim_count.toLocaleString('th-TH')}
                            </td>
                            <td className="px-4 py-3 text-right tabular-nums text-gray-900">
                                {formatMoney(person.total_amount)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    </div>
);

export default ClaimRankingTable;
