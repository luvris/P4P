import { useState } from 'react';
import Pagination from './Pagination';
import { formatMoney } from '../../utils/travelExpense';

/** จำนวนแถวต่อหน้าของตารางจัดอันดับ */
const PAGE_SIZE = 10;

/**
 * ตารางจัดอันดับบุคคลตามยอดเงินเบิกรวม (มาก → น้อย)
 * ภายใต้ขอบเขตและตัวกรองเดียวกันกับสรุปยอด — รวมบุคคลด้วยรหัสบุคคล ไม่ใช่ชื่อ
 *
 * แสดงทีละ 10 แถว เลขอันดับมาจาก backend (1..N) จึงต่อเนื่องข้ามหน้า
 * เปลี่ยนตัวกรอง → ข้อมูลชุดใหม่ → ผู้เรียกเปลี่ยน key ของ component เพื่อ remount
 * จึงกลับหน้าแรกเสมอโดยไม่ต้องใช้ effect (กัน stale page เมื่อข้อมูลหดลง มี safePage clamp ซ้ำ)
 */
const ClaimRankingTable = ({ ranking }) => {
    const [page, setPage] = useState(1);

    const total = ranking.length;
    const lastPage = Math.max(1, Math.ceil(total / PAGE_SIZE));

    const safePage = Math.min(page, lastPage);
    const rows = ranking.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

    const meta = {
        current_page: safePage,
        last_page: lastPage,
        total,
        from: total === 0 ? 0 : (safePage - 1) * PAGE_SIZE + 1,
        to: Math.min(safePage * PAGE_SIZE, total),
    };

    return (
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
                        {rows.map((person) => (
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

            {total > 0 && (
                <div className="border-t border-gray-200 px-4 py-3">
                    <Pagination meta={meta} onPageChange={setPage} />
                </div>
            )}
        </div>
    );
};

export default ClaimRankingTable;
