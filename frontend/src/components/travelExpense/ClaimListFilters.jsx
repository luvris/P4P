import { useState } from 'react';
import { Search } from 'lucide-react';
import { FISCAL_MONTHS, MONTH_LABELS, calendarYearOf } from '../../utils/fiscalPeriod';

/** แถบกรอง: ค้นหา + เดือนที่เบิก + สถานะ (ปีงบมาจาก Header) */
const ClaimListFilters = ({ fiscalYear, filters, onChange }) => {
    const [searchInput, setSearchInput] = useState(filters.search || '');

    const handleSubmit = (e) => {
        e.preventDefault();
        onChange({ search: searchInput });
    };

    return (
        <form onSubmit={handleSubmit} className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap gap-3">
                <div className="relative min-w-[240px] flex-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        type="text"
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        placeholder="ค้นหาเลขที่เอกสาร หรือชื่อผู้เบิก"
                        className="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-3 text-sm focus:border-transparent focus:outline-none focus:ring-2 focus:ring-amber-500"
                    />
                </div>

                <select
                    value={filters.claim_month}
                    onChange={(e) => onChange({ claim_month: e.target.value })}
                    aria-label="เดือนที่เบิก"
                    className="min-w-[170px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                >
                    <option value="">เดือนที่เบิก: ทั้งหมด</option>
                    {FISCAL_MONTHS.map((month) => (
                        <option key={month} value={month}>
                            {MONTH_LABELS[month]} {calendarYearOf(fiscalYear, month)}
                        </option>
                    ))}
                </select>

                <select
                    value={filters.status}
                    onChange={(e) => onChange({ status: e.target.value })}
                    aria-label="สถานะเอกสาร"
                    className="min-w-[150px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                >
                    <option value="">สถานะ: ทั้งหมด</option>
                    <option value="draft">ร่าง</option>
                    <option value="confirmed">ยืนยันแล้ว</option>
                    <option value="cancelled">ยกเลิก</option>
                </select>

                <button
                    type="submit"
                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50"
                >
                    ค้นหา
                </button>
            </div>
        </form>
    );
};

export default ClaimListFilters;
