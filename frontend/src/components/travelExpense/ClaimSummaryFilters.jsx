import { useMemo } from 'react';
import { FISCAL_MONTHS, MONTH_LABELS, calendarYearOf } from '../../utils/fiscalPeriod';

const selectClass = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm';

/** ตัวกรองช่วง "เดือนที่เบิก" — ระบบกรองด้วย claim_period (ไม่มีวันที่เดินทางจริง) */
const MonthRangeSelects = ({ fiscalYear, filters, onChange }) => (
    <>
        <select
            value={filters.periodFrom}
            onChange={(e) => onChange({ periodFrom: e.target.value })}
            aria-label="เดือนที่เบิก จาก"
            className={`min-w-[150px] ${selectClass}`}
        >
            <option value="">เดือนที่เบิก: ทั้งหมด</option>
            {FISCAL_MONTHS.map((month) => (
                <option key={month} value={month}>
                    {MONTH_LABELS[month]} {calendarYearOf(fiscalYear, month)}
                </option>
            ))}
        </select>

        <select
            value={filters.periodTo}
            onChange={(e) => onChange({ periodTo: e.target.value })}
            aria-label="ถึงเดือน"
            className={`min-w-[150px] ${selectClass}`}
        >
            <option value="">ถึงเดือน: ทั้งหมด</option>
            {FISCAL_MONTHS.map((month) => (
                <option key={month} value={month}>
                    {MONTH_LABELS[month]} {calendarYearOf(fiscalYear, month)}
                </option>
            ))}
        </select>
    </>
);

/** แถวตัวเลือก: ภารกิจ → กลุ่มงาน (dependent) → งาน (dependent) */
const DutyGroupWorkSelects = ({ duties, filters, onChange }) => {
    const groups = useMemo(
        () => duties.find((d) => String(d.id) === String(filters.dutyId))?.groups || [],
        [duties, filters.dutyId],
    );

    const works = useMemo(
        () => groups.find((g) => String(g.id) === String(filters.groupId))?.works || [],
        [groups, filters.groupId],
    );

    return (
        <>
            <select
                value={filters.dutyId ?? ''}
                onChange={(e) => onChange({ dutyId: e.target.value ? Number(e.target.value) : null })}
                aria-label="ภารกิจ"
                className={`min-w-[160px] ${selectClass}`}
            >
                <option value="">ภารกิจ: ทั้งหมด</option>
                {duties.map((duty) => (
                    <option key={duty.id} value={duty.id}>{duty.name}</option>
                ))}
            </select>

            <select
                value={filters.groupId ?? ''}
                onChange={(e) => onChange({ groupId: e.target.value ? Number(e.target.value) : null })}
                aria-label="กลุ่มงาน"
                className={`min-w-[160px] ${selectClass}`}
                disabled={!filters.dutyId}
            >
                <option value="">{filters.dutyId ? 'กลุ่มงาน: ทั้งหมด' : 'เลือกภารกิจก่อน'}</option>
                {groups.map((group) => (
                    <option key={group.id} value={group.id}>{group.name}</option>
                ))}
            </select>

            <select
                value={filters.workId ?? ''}
                onChange={(e) => onChange({ workId: e.target.value ? Number(e.target.value) : null })}
                aria-label="งาน"
                className={`min-w-[160px] ${selectClass}`}
                disabled={!filters.groupId}
            >
                <option value="">{filters.groupId ? 'งาน: ทั้งหมด' : 'เลือกกลุ่มงานก่อน'}</option>
                {works.map((work) => (
                    <option key={work.id} value={work.id}>{work.name}</option>
                ))}
            </select>
        </>
    );
};

/** แถบตัวกรองของหน้าสรุปผลการเบิกค่าใช้จ่าย */
const ClaimSummaryFilters = ({ fiscalYear, filters, onChange, duties }) => (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
        <div className="flex flex-wrap items-center gap-3">
            <MonthRangeSelects fiscalYear={fiscalYear} filters={filters} onChange={onChange} />
            <DutyGroupWorkSelects duties={duties} filters={filters} onChange={onChange} />

            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                <input
                    type="checkbox"
                    checked={filters.includeDraft}
                    onChange={(e) => onChange({ includeDraft: e.target.checked })}
                    className="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                />
                รวมใบเบิกร่าง
            </label>
        </div>
    </div>
);

export default ClaimSummaryFilters;
