import { useMemo, useState } from 'react';
import {
    Loader2,
    Landmark,
    Wallet,
    Users,
    FileSpreadsheet,
    Download,
    AlertTriangle,
    Percent,
    Save,
    CalendarCheck,
    CalendarClock,
    CheckCircle2,
    RotateCcw,
} from 'lucide-react';
import {
    ResponsiveContainer,
    PieChart,
    Pie,
    Cell,
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
} from 'recharts';
import useReserveFund from '../../hooks/useReserveFund';

const formatMoney = (value) =>
    new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value ?? 0);

const formatNumber = (value) => new Intl.NumberFormat('th-TH').format(value ?? 0);

/** แสดงเปอร์เซ็นต์แบบไม่มีทศนิยมเกินจำเป็น เช่น 3, 3.5, 2.75 */
const formatPercent = (value) =>
    new Intl.NumberFormat('th-TH', { maximumFractionDigits: 2 }).format(value ?? 0);

const formatCompact = (value) =>
    new Intl.NumberFormat('th-TH', {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value ?? 0);

const formatDate = (value) => {
    if (!value) return '-';
    return new Date(value).toLocaleString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const PIE_COLORS = [
    '#8B5E3C',
    '#C5A059',
    '#A67B5B',
    '#D6B56D',
    '#6B4F3A',
    '#C89F6D',
    '#9C7A5A',
    '#E0C48A',
];

const GROUP_BY_OPTIONS = [
    { value: 'duty', label: 'ภารกิจ' },
    { value: 'group', label: 'กลุ่มงาน' },
    { value: 'work', label: 'งาน' },
];

const GROUP_BY_LABELS = {
    duty: 'ภารกิจ',
    group: 'กลุ่มงาน',
    work: 'งาน',
};

const ChartTooltip = ({ active, payload, label }) => {
    if (!active || !payload || payload.length === 0) return null;
    return (
        <div className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-sm">
            {label && <p className="mb-1 font-semibold text-gray-700">{label}</p>}
            {payload.map((entry, idx) => (
                <p key={idx} className="text-gray-600">
                    <span
                        className="inline-block h-2 w-2 rounded-full mr-1"
                        style={{ backgroundColor: entry.color || entry.payload?.fill }}
                    />
                    {entry.name}: <span className="font-medium">{formatMoney(entry.value)} บาท</span>
                </p>
            ))}
        </div>
    );
};

/**
 * รวมยอดตามระดับที่เลือก (ภารกิจ / กลุ่มงาน / งาน) จากโครง duties → works
 */
const aggregateBy = (duties, level) => {
    if (level === 'duty') {
        return duties.map((duty) => ({
            name: duty.name,
            income_base: Number(duty.income_base) || 0,
            reserve: Number(duty.reserve_amount) || 0,
        }));
    }

    const map = new Map();
    duties.forEach((duty) => {
        (duty.works || []).forEach((work) => {
            const name =
                level === 'group'
                    ? work.group_name || 'ไม่ระบุกลุ่มงาน'
                    : work.name || 'ไม่ระบุงาน';

            if (!map.has(name)) {
                map.set(name, { name, income_base: 0, reserve: 0 });
            }
            const item = map.get(name);
            item.income_base += Number(work.income_base) || 0;
            item.reserve += Number(work.reserve_amount) || 0;
        });
    });

    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name, 'th'));
};

const ReserveFundPage = () => {
    const {
        fiscalYear,
        percent,
        setPercent,
        applyPercent,
        summary,
        duties,
        loading,
        saving,
        error,
        saveCalculation,
        setAnnualConfirmation,
    } = useReserveFund();

    const [saveNote, setSaveNote] = useState('');
    // ผูกข้อความสถานะกับปีงบที่บันทึก เพื่อให้หายเองเมื่อสลับปี
    const [saveStatus, setSaveStatus] = useState(null);
    const activeStatus = saveStatus?.fiscalYear === fiscalYear ? saveStatus : null;

    const months = useMemo(() => summary?.months || [], [summary]);
    // งวดล่าสุดที่มีข้อมูล — ใช้เป็นภาพจำนวนบุคลากร ณ งวดล่าสุด (ไม่ใช่ผลรวมทุกงวด)
    const lastMonthWithData = useMemo(
        () => [...months].reverse().find((month) => month.has_data) || null,
        [months],
    );
    const monthsPresent = summary?.months_present ?? 0;
    const savedInfo = summary?.saved || null;
    const isConfirmed = savedInfo?.status === 'confirmed';

    const reportStatus = (result, fallbackError) =>
        setSaveStatus({
            fiscalYear,
            type: result.success ? 'success' : 'error',
            message: result.success ? result.message || 'ดำเนินการสำเร็จ' : result.error || fallbackError,
        });

    const handleSave = async () => {
        setSaveStatus(null);
        const result = await saveCalculation({ note: saveNote });
        reportStatus(result, 'ไม่สามารถบันทึกผลการคำนวณได้');
    };

    const handleToggleConfirm = async () => {
        setSaveStatus(null);
        const result = await setAnnualConfirmation(savedInfo?.id, !isConfirmed);
        reportStatus(result, 'ไม่สามารถเปลี่ยนสถานะการยืนยันได้');
    };

    const appliedPercent = summary?.percent ?? (percent === '' ? null : Number(percent));
    const percentLabel = appliedPercent === null ? '-' : formatPercent(appliedPercent);

    const [groupBy, setGroupBy] = useState('duty');
    const chartData = useMemo(() => aggregateBy(duties, groupBy), [duties, groupBy]);

    const exportCsv = () => {
        const incomeHeader = 'ฐานรายรับ (บาท)';
        const reserveHeader = `เงินสำรอง ${percentLabel}% (บาท)`;

        const rows = [
            [
                `ปีงบประมาณ ${fiscalYear}`,
                `ต.ค. ${fiscalYear - 1} – ก.ย. ${fiscalYear}`,
                `ข้อมูล ${monthsPresent}/12 งวด`,
                '',
                '',
                '',
            ],
            ['งวด', 'ชุดข้อมูล', 'ฐานรายรับ (บาท)', 'เงินสำรอง (บาท)', 'จำนวน (คน)', 'สถานะ'],
        ];

        // รายงวด
        months.forEach((month) => {
            rows.push([
                `${month.period_label} ${month.period_year}`,
                month.import_name || '-',
                month.has_data ? Number(month.income_base).toFixed(2) : '',
                month.total_reserve != null ? Number(month.total_reserve).toFixed(2) : '',
                month.has_data ? month.total_employees : '',
                month.has_data ? 'มีข้อมูล' : 'ขาด',
            ]);
        });

        rows.push([
            'รวมทั้งปี',
            '',
            Number(summary?.total_income_base || 0).toFixed(2),
            Number(summary?.total_reserve || 0).toFixed(2),
            summary?.total_employees ?? '',
            '',
        ]);

        // รายละเอียดตามภารกิจ/กลุ่มงาน/งาน
        rows.push(['', '', '', '', '', '']);
        rows.push(['ภารกิจ', 'กลุ่มงาน', 'งาน', incomeHeader, reserveHeader, 'จำนวน (คน)']);

        duties.forEach((duty) => {
            (duty.works || []).forEach((work) => {
                rows.push([
                    duty.name,
                    work.group_name,
                    work.name,
                    Number(work.income_base).toFixed(2),
                    Number(work.reserve_amount ?? 0).toFixed(2),
                    work.employee_count,
                ]);
            });
            rows.push([
                `รวมภารกิจ: ${duty.name}`,
                '',
                '',
                Number(duty.income_base).toFixed(2),
                Number(duty.reserve_amount ?? 0).toFixed(2),
                duty.employee_count,
            ]);
        });

        const csv = rows
            .map((row) =>
                row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','),
            )
            .join('\r\n');

        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `reserve-fund-annual-${fiscalYear}-${percentLabel}percent.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    const hasData = (summary?.total_employees ?? 0) > 0;

    return (
        <div className="max-w-7xl mx-auto space-y-4">
            {/* Header */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between print:mb-2 print:gap-1">
                <div>
                    <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">คำนวณเงินสำรอง</h2>
                    <p className="text-gray-500 text-sm">
                        คำนวณจากฐานรายรับ = เงินเดือน + ล่วงเวลา (OT) + เงินประจำตำแหน่ง + เงิน P4P
                    </p>
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={saving || loading || percent === '' || isConfirmed}
                        title={isConfirmed ? 'ยืนยันแล้ว ต้องยกเลิกการยืนยันก่อนแก้ไข' : undefined}
                        className="inline-flex items-center gap-2 rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] hover:bg-[#FBF7EE] disabled:opacity-50"
                    >
                        {saving ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                        ) : (
                            <Save className="w-4 h-4" />
                        )}
                        บันทึกผลการคำนวณ
                    </button>
                    <button
                        type="button"
                        onClick={handleToggleConfirm}
                        disabled={saving || loading || !savedInfo}
                        title={!savedInfo ? 'บันทึกผลการคำนวณของปีนี้ก่อน' : undefined}
                        className={`inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium disabled:opacity-50 ${
                            isConfirmed
                                ? 'border border-gray-300 text-gray-600 hover:bg-gray-50'
                                : 'border border-green-600 text-green-700 hover:bg-green-50'
                        }`}
                    >
                        {isConfirmed ? (
                            <RotateCcw className="w-4 h-4" />
                        ) : (
                            <CheckCircle2 className="w-4 h-4" />
                        )}
                        {isConfirmed ? 'ยกเลิกการยืนยันรายปี' : 'ยืนยันรายปี'}
                    </button>
                    <button
                        type="button"
                        onClick={exportCsv}
                        className="inline-flex items-center gap-2 rounded-lg bg-[#8B5E3C] px-3 py-2 text-sm font-medium text-white hover:bg-[#6B4F3A]"
                    >
                        <Download className="w-4 h-4" />
                        ส่งออก Excel
                    </button>
                </div>
            </div>

            {/* Control card */}
            <div className="rounded-xl border border-gray-200 bg-white p-4 print:hidden">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label
                            htmlFor="reserve-percent"
                            className="block text-sm font-medium text-gray-700 mb-2"
                        >
                            เปอร์เซ็นต์ที่ใช้คำนวณ (%)
                        </label>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyPercent(percent);
                            }}
                            className="flex gap-2"
                        >
                            <div className="relative flex-1">
                                <Percent className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                <input
                                    id="reserve-percent"
                                    type="number"
                                    inputMode="decimal"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={percent}
                                    onChange={(e) => setPercent(e.target.value)}
                                    onBlur={() => applyPercent(percent)}
                                    className="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={loading}
                                className="shrink-0 rounded-lg bg-[#8B5E3C] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[#75492C] disabled:opacity-50"
                            >
                                คำนวณ
                            </button>
                        </form>
                        <p className="mt-2 text-xs text-gray-500">
                            กรอกได้ตั้งแต่ 0–100 (รองรับทศนิยม) กำลังแสดงผลที่ {percentLabel}%
                        </p>
                    </div>

                    <div>
                        <label
                            htmlFor="reserve-save-note"
                            className="block text-sm font-medium text-gray-700 mb-2"
                        >
                            ปีงบประมาณที่บันทึกผล
                        </label>
                        <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                            <CalendarClock className="h-4 w-4 shrink-0 text-gray-400" />
                            <span className="font-medium">ปีงบประมาณ {fiscalYear}</span>
                            <span className="ml-auto text-xs text-gray-500">
                                ต.ค. {fiscalYear - 1} – ก.ย. {fiscalYear}
                            </span>
                        </div>
                        <input
                            id="reserve-save-note"
                            type="text"
                            value={saveNote}
                            onChange={(e) => setSaveNote(e.target.value)}
                            maxLength={1000}
                            placeholder="หมายเหตุ (ไม่บังคับ)"
                            className="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            สถานะผลการคำนวณรายปี
                        </label>
                        <div
                            className={`rounded-lg border px-3 py-2 text-sm ${
                                isConfirmed
                                    ? 'border-green-200 bg-green-50 text-green-700'
                                    : savedInfo
                                        ? 'border-amber-200 bg-amber-50 text-amber-800'
                                        : 'border-gray-200 bg-gray-50 text-gray-700'
                            }`}
                        >
                            {savedInfo ? (
                                <>
                                    <p className="font-medium">
                                        {isConfirmed ? 'ยืนยันแล้ว' : 'ร่าง (ยังไม่ยืนยัน)'}
                                    </p>
                                    <p className="mt-0.5 text-xs">
                                        บันทึกไว้ที่ {formatPercent(savedInfo.percent)}% ·{' '}
                                        {formatDate(savedInfo.saved_at)}
                                    </p>
                                </>
                            ) : (
                                'ยังไม่มีผลการคำนวณที่บันทึกไว้สำหรับปีงบนี้'
                            )}
                        </div>
                        <p className="mt-2 text-xs text-gray-500">
                            บันทึกร่างก่อน แล้วกด «ยืนยันรายปี» เพื่อล็อกยอดทางการ
                        </p>
                    </div>
                </div>

                {activeStatus && (
                    <div
                        role="status"
                        className={`mt-4 rounded-lg px-4 py-3 text-sm ${
                            activeStatus.type === 'error'
                                ? 'border border-red-200 bg-red-50 text-red-700'
                                : 'border border-green-200 bg-green-50 text-green-700'
                        }`}
                    >
                        {activeStatus.message}
                    </div>
                )}
            </div>

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            {/* ความครบของข้อมูลรายเดือน + ยอดรายปี */}
            <div className="rounded-xl border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="rounded-lg p-2.5 bg-[#FBF7EE] text-[#8B5E3C] shrink-0">
                            <CalendarCheck className="w-5 h-5" />
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">
                                ข้อมูลรายเดือนของปีงบประมาณ {fiscalYear}
                            </p>
                            <p className="text-2xl font-semibold text-[#8B5E3C]">
                                {loading ? (
                                    <span className="inline-block w-24 h-7 bg-gray-200 rounded animate-pulse" />
                                ) : (
                                    <>
                                        {formatNumber(monthsPresent)}
                                        <span className="text-base font-normal text-gray-500">
                                            {' '}
                                            / 12 งวด
                                        </span>
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                    <div className="text-right text-xs text-gray-500">
                        <p>
                            ฐานรายรับสะสม{' '}
                            <span className="font-semibold text-gray-700">
                                {formatMoney(summary?.total_income_base)}
                            </span>{' '}
                            บาท
                        </p>
                        <p className="mt-1">
                            เงินสำรอง {percentLabel}%{' '}
                            <span className="font-semibold text-gray-700">
                                {formatMoney(summary?.total_reserve)}
                            </span>{' '}
                            บาท
                        </p>
                    </div>
                </div>

                {!loading && monthsPresent < 12 && (
                    <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
                        <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                        <span>
                            ยังขาดอีก {12 - monthsPresent} งวด ยอดรายปีจะต่ำกว่าความจริง
                            ควรนำเข้า payroll งวดที่ขาดก่อนยืนยัน
                        </span>
                    </div>
                )}

                {!loading && monthsPresent === 12 && (
                    <div className="mt-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 flex items-start gap-2">
                        <CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0" />
                        <span>ข้อมูลครบ 12/12 งวด — คำนวณและยืนยันยอดรายปีได้ครบถ้วน</span>
                    </div>
                )}

                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <caption className="sr-only">งวดรายเดือนของปีงบประมาณ {fiscalYear}</caption>
                        <thead>
                            <tr className="bg-gray-50 text-left text-gray-600">
                                <th scope="col" className="px-4 py-2 font-semibold">
                                    งวด
                                </th>
                                <th scope="col" className="px-4 py-2 font-semibold">
                                    ชุดข้อมูล
                                </th>
                                <th scope="col" className="px-4 py-2 font-semibold text-right">
                                    ฐานรายรับ (บาท)
                                </th>
                                <th scope="col" className="px-4 py-2 font-semibold text-right">
                                    จำนวน (คน)
                                </th>
                                <th scope="col" className="px-4 py-2 font-semibold text-right">
                                    สถานะ
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {loading ? (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-gray-400">
                                        <span className="inline-flex items-center gap-2">
                                            <Loader2 className="w-4 h-4 animate-spin" />
                                            กำลังโหลดข้อมูล...
                                        </span>
                                    </td>
                                </tr>
                            ) : (
                                months.map((month) => (
                                    <tr key={month.period_month} className="hover:bg-gray-50">
                                        <td className="px-4 py-2 text-gray-700">
                                            {month.period_label} {month.period_year}
                                        </td>
                                        <td
                                            className={`px-4 py-2 ${
                                                month.has_data ? 'text-gray-700' : 'text-gray-400'
                                            }`}
                                        >
                                            {month.has_data ? (
                                                <>
                                                    {month.import_name}
                                                    {month.period_source === 'inferred' && (
                                                        <span
                                                            className="ml-2 rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] text-gray-500"
                                                            title="ยังไม่ได้ระบุงวดของไฟล์ ระบบอนุมานจากวันที่อัปโหลด"
                                                        >
                                                            อนุมานจากวันที่อัปโหลด
                                                        </span>
                                                    )}
                                                </>
                                            ) : (
                                                '— ไม่มีข้อมูล —'
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-right text-gray-700">
                                            {month.has_data ? formatMoney(month.income_base) : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right text-gray-700">
                                            {month.has_data ? formatNumber(month.total_employees) : '—'}
                                        </td>
                                        <td className="px-4 py-2 text-right">
                                            {month.has_data ? (
                                                <span className="inline-flex rounded-full bg-green-50 border border-green-200 px-2 py-0.5 text-xs font-medium text-green-700">
                                                    มีข้อมูล
                                                </span>
                                            ) : (
                                                <span className="inline-flex rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-xs font-medium text-amber-700">
                                                    ขาด
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                        <tfoot>
                            <tr className="bg-[#FBF7EE] print:hidden">
                                <td colSpan={5} className="px-4 py-3">
                                    <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1">
                                        <span className="text-sm font-semibold text-[#8B5E3C]">
                                            รวมทั้งปี ({monthsPresent}/12 งวด)
                                        </span>
                                        <span className="text-xs text-gray-600">
                                            ฐานรายรับ{' '}
                                            <span className="font-semibold text-gray-800">
                                                {formatMoney(summary?.total_income_base)}
                                            </span>{' '}
                                            บาท · จำนวนบุคลากร{' '}
                                            <span className="font-semibold text-gray-800">
                                                {formatNumber(lastMonthWithData?.total_employees)}
                                            </span>{' '}
                                            คน (งวดล่าสุด)
                                        </span>
                                        <span className="text-sm font-semibold text-[#8B5E3C]">
                                            เงินสำรอง {percentLabel}% (คิดครั้งเดียวจากยอดรวม) ={' '}
                                            {formatMoney(summary?.total_reserve)} บาท
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {/* Summary Stats */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-green-100 text-green-700 shrink-0">
                        <Wallet className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">ฐานรายรับรายปี</p>
                        <p className="text-2xl font-semibold text-gray-800">
                            {loading ? (
                                <span className="inline-block w-20 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatMoney(summary?.total_income_base)}
                                    <span className="text-sm font-normal text-gray-500 ml-1">บาท</span>
                                </>
                            )}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-amber-100 text-amber-700 shrink-0">
                        <Landmark className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">
                            เงินสำรอง {percentLabel}%
                        </p>
                        <p className="text-2xl font-semibold text-[#8B5E3C]">
                            {loading ? (
                                <span className="inline-block w-20 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatMoney(summary?.total_reserve)}
                                    <span className="text-sm font-normal text-gray-500 ml-1">บาท</span>
                                </>
                            )}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-blue-100 text-blue-700 shrink-0">
                        <Users className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">จำนวนบุคลากร</p>
                        <p className="text-2xl font-semibold text-gray-800">
                            {loading ? (
                                <span className="inline-block w-14 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatNumber(summary?.total_employees)}
                                    <span className="text-sm font-normal text-gray-500 ml-1">คน</span>
                                </>
                            )}
                        </p>
                    </div>
                </div>
            </div>

            {/* Charts */}
            {loading ? (
                <div className="flex items-center justify-center py-16 text-gray-400 gap-2">
                    <Loader2 className="w-5 h-5 animate-spin" />
                    <span>กำลังโหลดข้อมูล...</span>
                </div>
            ) : (
                hasData && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                            <h3 className="font-semibold text-gray-800">กราฟสรุป</h3>
                            <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1">
                                {GROUP_BY_OPTIONS.map((opt) => (
                                    <button
                                        key={opt.value}
                                        type="button"
                                        onClick={() => setGroupBy(opt.value)}
                                        className={`px-3 py-1.5 text-sm font-medium rounded-md transition-colors ${
                                            groupBy === opt.value
                                                ? 'bg-[#8B5E3C] text-white'
                                                : 'text-gray-600 hover:text-gray-800'
                                        }`}
                                    >
                                        {opt.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 print:grid-cols-1">
                            <div className="rounded-xl border border-gray-200 bg-white p-4">
                                <h4 className="font-semibold text-gray-800 mb-4">
                                    สัดส่วนเงินสำรอง {percentLabel}% ตาม{GROUP_BY_LABELS[groupBy]}
                                </h4>
                                <div className="h-72">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={chartData}
                                                dataKey="reserve"
                                                nameKey="name"
                                                innerRadius={50}
                                                outerRadius={90}
                                                paddingAngle={2}
                                            >
                                                {chartData.map((entry, idx) => (
                                                    <Cell key={idx} fill={PIE_COLORS[idx % PIE_COLORS.length]} />
                                                ))}
                                            </Pie>
                                            <Tooltip content={<ChartTooltip />} />
                                            <Legend
                                                formatter={(value) => (
                                                    <span className="text-xs text-gray-600">{value}</span>
                                                )}
                                            />
                                        </PieChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>

                            <div className="rounded-xl border border-gray-200 bg-white p-4">
                                <h4 className="font-semibold text-gray-800 mb-4">
                                    เปรียบเทียบฐานรายรับกับเงินสำรอง {percentLabel}% ตาม
                                    {GROUP_BY_LABELS[groupBy]}
                                </h4>
                                <div className="h-72">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart
                                            data={chartData}
                                            layout="vertical"
                                            margin={{ top: 0, right: 16, left: 8, bottom: 0 }}
                                        >
                                            <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                                            <XAxis type="number" tickFormatter={formatCompact} />
                                            <YAxis
                                                type="category"
                                                dataKey="name"
                                                width={140}
                                                tick={{ fontSize: 12, fill: '#4B5563' }}
                                            />
                                            <Tooltip content={<ChartTooltip />} />
                                            <Legend
                                                formatter={(value) => (
                                                    <span className="text-xs text-gray-600">{value}</span>
                                                )}
                                            />
                                            <Bar
                                                dataKey="income_base"
                                                name="ฐานรายรับ"
                                                fill="#C5A059"
                                                radius={[0, 4, 4, 0]}
                                            />
                                            <Bar
                                                dataKey="reserve"
                                                name={`สำรอง ${percentLabel}%`}
                                                fill="#8B5E3C"
                                                radius={[0, 4, 4, 0]}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        </div>
                    </div>
                )
            )}

            {/* Breakdown Table */}
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden print:border-none">
                <div className="px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                    <FileSpreadsheet className="w-4 h-4 text-[#8B5E3C]" />
                    <h3 className="font-semibold text-gray-800">
                        รายละเอียดจำแนกตามภารกิจ / กลุ่มงาน / งาน
                    </h3>
                </div>

                {loading ? (
                    <div className="flex items-center justify-center py-16 text-gray-400 gap-2">
                        <Loader2 className="w-5 h-5 animate-spin" />
                        <span>กำลังโหลดข้อมูล...</span>
                    </div>
                ) : duties.length === 0 ? (
                    <div className="py-16 text-center text-gray-500 text-sm">
                        ยังไม่มีข้อมูล Payroll สำหรับคำนวณ
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th className="px-4 py-3 font-semibold">
                                        ภารกิจ / กลุ่มงาน / งาน
                                    </th>
                                    <th className="px-4 py-3 font-semibold text-right">
                                        ฐานรายรับ (บาท)
                                    </th>
                                    <th className="px-4 py-3 font-semibold text-right">
                                        เงินสำรอง {percentLabel}% (บาท)
                                    </th>
                                    <th className="px-4 py-3 font-semibold text-right">จำนวน (คน)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {duties.map((duty, idx) => (
                                    <DutyRows key={duty.id ?? idx} duty={duty} />
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="bg-[#8B5E3C] text-white font-semibold">
                                    <td className="px-4 py-3">รวมทั้งหมด</td>
                                    <td className="px-4 py-3 text-right">
                                        {formatMoney(summary?.total_income_base)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {formatMoney(summary?.total_reserve)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {formatNumber(summary?.total_employees)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
};

const DutyRows = ({ duty }) => {
    const works = duty.works || [];

    return (
        <>
            {works.map((work, wIdx) => (
                <tr key={wIdx} className={wIdx === 0 ? 'bg-[#FBF7EE]' : undefined}>
                    <td className="px-4 py-3">
                        <div className="font-semibold text-gray-800">{duty.name}</div>
                        <div className="text-xs text-gray-500 pl-4">
                            {work.group_name}
                            <span className="mx-1">·</span>
                            {work.name}
                        </div>
                    </td>
                    <td className="px-4 py-3 text-right text-gray-700">
                        {formatMoney(work.income_base)}
                    </td>
                    <td className="px-4 py-3 text-right font-medium text-[#8B5E3C]">
                        {formatMoney(work.reserve_amount)}
                    </td>
                    <td className="px-4 py-3 text-right text-gray-700">
                        {formatNumber(work.employee_count)}
                    </td>
                </tr>
            ))}
            {/* Subtotal row per duty */}
            <tr className="bg-gray-50 border-t border-gray-200">
                <td className="px-4 py-2 text-xs font-semibold text-gray-500 pl-8">
                    รวมภารกิจ: {duty.name}
                </td>
                <td className="px-4 py-2 text-right font-semibold text-gray-700">
                    {formatMoney(duty.income_base)}
                </td>
                <td className="px-4 py-2 text-right font-semibold text-[#8B5E3C]">
                    {formatMoney(duty.reserve_amount)}
                </td>
                <td className="px-4 py-2 text-right font-semibold text-gray-700">
                    {formatNumber(duty.employee_count)}
                </td>
            </tr>
        </>
    );
};

export default ReserveFundPage;
