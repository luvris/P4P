import { ChevronRight } from 'lucide-react';
import { formatMoney } from '../../utils/travelExpense';

/**
 * ตารางหมวดย่อยของระดับที่กำลังดู (ภารกิจ / กลุ่มงาน / งาน)
 *
 * แต่ละแถวแสดงยอดเบิกรวม จำนวนใบเบิก จำนวนผู้เบิก
 * และผู้เบิกสูงสุดของหมวดนั้น (เสมอกันแสดงทุกคน)
 * คลิกแถวเพื่อลงลึกไปดูหมวดย่อยของแถวนั้น
 */
const ClaimSummaryTable = ({ level, buckets, onDrillDown }) => {
    const levelLabels = { duty: 'ภารกิจ', group: 'กลุ่มงาน', work: 'งาน' };

    return (
        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div className="border-b border-gray-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-gray-800">
                    สรุปตาม{levelLabels[level]}
                </h3>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="bg-gray-50 text-left text-gray-600">
                            <th className="px-4 py-2.5 font-medium">{levelLabels[level]}</th>
                            <th className="px-4 py-2.5 text-right font-medium">ยอดเบิกรวม (บาท)</th>
                            <th className="px-4 py-2.5 text-right font-medium">จำนวนใบเบิก</th>
                            <th className="px-4 py-2.5 text-right font-medium">จำนวนผู้เบิก</th>
                            <th className="px-4 py-2.5 font-medium">ผู้เบิกสูงสุด</th>
                            <th className="w-8 px-2 py-2.5" aria-hidden="true" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {buckets.map((bucket) => {
                            const canDrill = Boolean(bucket.id) && level !== 'work';

                            return (
                                <tr
                                    key={bucket.id ?? 'unassigned'}
                                    className={`${canDrill ? 'cursor-pointer hover:bg-amber-50' : ''} transition-colors`}
                                    onClick={() => canDrill && onDrillDown(bucket)}
                                >
                                    <td className="px-4 py-3 font-medium text-gray-800">
                                        {bucket.name}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-gray-900">
                                        {formatMoney(bucket.total_amount)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                        {bucket.claim_count.toLocaleString('th-TH')}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-gray-700">
                                        {bucket.claimant_count.toLocaleString('th-TH')}
                                    </td>
                                    <td className="px-4 py-3">
                                        {bucket.top_spenders.length === 0 ? (
                                            <span className="text-gray-400">-</span>
                                        ) : (
                                            <div className="space-y-0.5">
                                                {bucket.top_spenders.map((person) => (
                                                    <div key={person.person_key} className="flex flex-wrap items-baseline gap-x-2">
                                                        <span className="font-medium text-gray-800">{person.name}</span>
                                                        <span className="tabular-nums text-gray-600">
                                                            {formatMoney(person.total_amount)}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-2 py-3 text-right">
                                        {canDrill && (
                                            <ChevronRight className="ml-auto h-4 w-4 text-gray-400" aria-hidden="true" />
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default ClaimSummaryTable;
