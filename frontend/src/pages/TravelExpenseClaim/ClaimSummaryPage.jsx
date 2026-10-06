import { ChevronRight } from 'lucide-react';
import ClaimSummaryFilters from '../../components/travelExpense/ClaimSummaryFilters';
import ClaimSummaryTable from '../../components/travelExpense/ClaimSummaryTable';
import ClaimRankingTable from '../../components/travelExpense/ClaimRankingTable';
import useClaimSummary from '../../hooks/useClaimSummary';
import useFiscalYear from '../../hooks/useFiscalYear';
import useLookups from '../../hooks/useLookups';
import { formatMoney } from '../../utils/travelExpense';

/**
 * หน้าสรุปผลการเบิกค่าใช้จ่าย — ดูยอดค่าใช้จ่ายแยกตาม ภารกิจ → กลุ่มงาน → งาน
 * และผู้เบิกที่มียอดเงินรวมสูงที่สุดของแต่ละระดับ
 *
 * ทุกครั้งที่เปลี่ยนตัวกรองหรือลงลึก/ย้อนกลับ ข้อมูลถูกคำนวณใหม่ทั้งหมด
 * จาก query เดียวตามตัวกรองปัจจุบัน — สรุปและตารางจัดอันดับใช้ชุดข้อมูลเดียวกัน
 */
const ClaimSummaryPage = () => {
    const { fiscalYear } = useFiscalYear();
    const { lookups } = useLookups();

    const { filters, updateFilters, drillDown, goToLevel, data, loading, error } =
        useClaimSummary(fiscalYear);

    const levelLabels = { duty: 'ภารกิจ', group: 'กลุ่มงาน', work: 'งาน' };
    const totals = data?.totals ?? { total_amount: 0, claim_count: 0, claimant_count: 0 };
    const buckets = data?.buckets ?? [];
    const ranking = data?.ranking ?? [];

    const hasData = !loading && !error && (buckets.length > 0 || ranking.length > 0);

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-600">
                ปีงบประมาณ <span className="font-semibold text-gray-800">{fiscalYear}</span>
            </p>

            {/* Breadcrumb ดริลดาวน์ */}
            <nav className="flex flex-wrap items-center gap-1 text-sm" aria-label="ลำดับชั้นข้อมูล">
                <button
                    type="button"
                    onClick={() => goToLevel('duty')}
                    className={`rounded px-1.5 py-0.5 font-medium hover:bg-gray-100 ${
                        filters.level === 'duty' ? 'text-gray-900' : 'text-amber-700'
                    }`}
                >
                    ภารกิจทั้งหมด
                </button>

                {filters.dutyId && (
                    <>
                        <ChevronRight className="h-4 w-4 text-gray-400" aria-hidden="true" />
                        <button
                            type="button"
                            onClick={() => goToLevel('group')}
                            className={`rounded px-1.5 py-0.5 font-medium hover:bg-gray-100 ${
                                filters.level === 'group' ? 'text-gray-900' : 'text-amber-700'
                            }`}
                        >
                            {lookups.duties.find((d) => d.id === filters.dutyId)?.name || 'ภารกิจ'}
                        </button>
                    </>
                )}

                {filters.groupId && (
                    <>
                        <ChevronRight className="h-4 w-4 text-gray-400" aria-hidden="true" />
                        <button
                            type="button"
                            onClick={() => goToLevel('work')}
                            className={`rounded px-1.5 py-0.5 font-medium hover:bg-gray-100 ${
                                filters.level === 'work' ? 'text-gray-900' : 'text-amber-700'
                            }`}
                        >
                            {(lookups.duties.find((d) => d.id === filters.dutyId)?.groups || [])
                                .find((g) => g.id === filters.groupId)?.name || 'กลุ่มงาน'}
                        </button>
                    </>
                )}
            </nav>

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            <ClaimSummaryFilters
                fiscalYear={fiscalYear}
                filters={filters}
                onChange={updateFilters}
                duties={lookups.duties || []}
            />

            {/* ยอดรวมของขอบเขตที่กำลังดู */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="rounded-xl border border-gray-200 bg-white p-4">
                    <p className="text-xs text-gray-500">ยอดเบิกรวม{filters.level !== 'duty' ? `ของ${levelLabels[filters.level]}` : ''} (บาท)</p>
                    <p className="mt-1 text-xl font-semibold tabular-nums text-gray-900">
                        {formatMoney(totals.total_amount)}
                    </p>
                </div>
                <div className="rounded-xl border border-gray-200 bg-white p-4">
                    <p className="text-xs text-gray-500">จำนวนใบเบิก</p>
                    <p className="mt-1 text-xl font-semibold tabular-nums text-gray-900">
                        {totals.claim_count.toLocaleString('th-TH')}
                    </p>
                </div>
                <div className="rounded-xl border border-gray-200 bg-white p-4">
                    <p className="text-xs text-gray-500">จำนวนผู้เบิก (คน)</p>
                    <p className="mt-1 text-xl font-semibold tabular-nums text-gray-900">
                        {totals.claimant_count.toLocaleString('th-TH')}
                    </p>
                </div>
            </div>

            {loading ? (
                <div className="rounded-xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500">
                    กำลังโหลดข้อมูล...
                </div>
            ) : hasData ? (
                <>
                    <ClaimSummaryTable
                        level={filters.level}
                        buckets={buckets}
                        onDrillDown={drillDown}
                    />
                    <ClaimRankingTable ranking={ranking} />
                </>
            ) : (
                <div className="rounded-xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500">
                    ไม่พบข้อมูลตามเงื่อนไขที่เลือก
                </div>
            )}
        </div>
    );
};

export default ClaimSummaryPage;
