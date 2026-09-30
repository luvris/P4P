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
    CalendarClock,
    CheckCircle2,
    RotateCcw,
    PiggyBank,
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
import useFiscalYear from '../../hooks/useFiscalYear';
import { FISCAL_MONTHS, MONTH_LABELS, calendarYearOf } from '../../utils/fiscalPeriod';

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

const ReserveFundPage = () => {
    const {
        imports,
        importId,
        percent,
        setPercent,
        applyPercent,
        fiscalYear,
        periodMonth,
        handlePeriodMonthChange,
        accumulated,
        data,
        loading,
        saving,
        error,
        handleImportChange,
        saveCalculation,
        setPeriodConfirmation,
    } = useReserveFund();

    const { fiscalYearOptions } = useFiscalYear();
    const hasSavedYears = fiscalYearOptions.length > 0;

    const [saveNote, setSaveNote] = useState('');
    // ผูกข้อความสถานะกับปีงบ + งวดที่บันทึก เพื่อให้หายเองเมื่อสลับ
    const [saveStatus, setSaveStatus] = useState(null);
    const activeStatus =
        saveStatus?.fiscalYear === fiscalYear && saveStatus?.periodMonth === periodMonth
            ? saveStatus
            : null;

    const duties = useMemo(() => data?.data?.duties || [], [data]);
    const summary = data?.summary || null;
    const savedInfo = summary?.saved || null;
    const isConfirmed = savedInfo?.status === 'confirmed';

    const reportStatus = (result, fallbackError) =>
        setSaveStatus({
            fiscalYear,
            periodMonth,
            type: result.success ? 'success' : 'error',
            message: result.success
                ? result.message || 'ดำเนินการสำเร็จ'
                : result.error || fallbackError,
        });

    const handleSave = async () => {
        setSaveStatus(null);
        const result = await saveCalculation({ note: saveNote });
        reportStatus(result, 'ไม่สามารถบันทึกผลการคำนวณได้');
    };

    const handleToggleConfirm = async () => {
        setSaveStatus(null);
        const result = await setPeriodConfirmation(savedInfo?.id, !isConfirmed);
        reportStatus(result, 'ไม่สามารถเปลี่ยนสถานะการยืนยันได้');
    };


    // เปอร์เซ็นต์ที่ใช้คำนวณผลลัพธ์ชุดที่แสดงอยู่ (มาจาก backend)
    const appliedPercent = summary?.percent ?? percent;
    const percentLabel = formatPercent(appliedPercent);

    const [groupBy, setGroupBy] = useState('duty');

    const chartData = useMemo(() => {
        if (groupBy === 'duty') {
            return duties.map((duty) => ({
                id: duty.id,
                name: duty.name,
                income_base: duty.income_base,
                value: duty.reserve_amount,
            }));
        }

        const map = new Map();
        duties.forEach((duty) => {
            duty.works.forEach((work) => {
                const key =
                    groupBy === 'group'
                        ? `group:${work.group_id ?? work.group_name}`
                        : `work:${work.id ?? work.name}`;
                const name =
                    groupBy === 'group'
                        ? work.group_name || 'ไม่ระบุกลุ่มงาน'
                        : work.name || 'ไม่ระบุงาน';

                if (!map.has(key)) {
                    map.set(key, { id: key, name, income_base: 0, value: 0 });
                }
                const item = map.get(key);
                item.income_base += work.income_base;
                item.value += work.reserve_amount;
            });
        });

        return Array.from(map.values()).sort((a, b) =>
            a.name.localeCompare(b.name, 'th'),
        );
    }, [duties, groupBy]);

    const unassignedCount = useMemo(() => {
        const duty = duties.find((d) => d.id == null);
        return duty ? duty.employee_count : 0;
    }, [duties]);

    const exportCsv = () => {
        const incomeHeader = 'ฐานรายรับ (บาท)';
        const reserveHeader = `เงินสำรอง ${percentLabel}% (บาท)`;

        const rows = [
            [`ปีงบประมาณ ${fiscalYear}`, `งวด ${MONTH_LABELS[periodMonth]} ${calendarYearOf(fiscalYear, periodMonth)}`, '', '', '', ''],
            ['ภารกิจ', 'กลุ่มงาน', 'งาน', incomeHeader, reserveHeader, 'จำนวน (คน)'],
        ];

        duties.forEach((duty) => {
            duty.works.forEach((work) => {
                rows.push([
                    duty.name,
                    work.group_name,
                    work.name,
                    work.income_base.toFixed(2),
                    work.reserve_amount.toFixed(2),
                    work.employee_count,
                ]);
            });
            rows.push([
                `รวมภารกิจ: ${duty.name}`,
                '',
                '',
                duty.income_base.toFixed(2),
                duty.reserve_amount.toFixed(2),
                duty.employee_count,
            ]);
        });

        if (summary) {
            rows.push([
                'รวมทั้งหมด',
                '',
                '',
                summary.total_income_base.toFixed(2),
                summary.total_reserve.toFixed(2),
                summary.total_employees,
            ]);
        }

        // ยอดสะสมของปีงบ (เฉพาะงวดที่ยืนยันแล้ว)
        if (accumulated?.periods?.length > 0) {
            rows.push(['', '', '', '', '', '']);
            rows.push([`เงินสำรองสะสม ปีงบประมาณ ${fiscalYear} (งวดที่ยืนยันแล้ว)`, '', '', '', '', '']);
            rows.push(['งวด', 'เปอร์เซ็นต์ (%)', '', incomeHeader, 'เงินสำรอง (บาท)', 'จำนวน (คน)']);

            accumulated.periods.forEach((period) => {
                rows.push([
                    `${period.period_label} ${period.period_year}`,
                    period.percent,
                    '',
                    period.total_income_base.toFixed(2),
                    period.total_reserve.toFixed(2),
                    period.total_employees,
                ]);
            });

            rows.push([
                'รวมสะสม',
                '',
                '',
                accumulated.total_income_base.toFixed(2),
                accumulated.total_reserve.toFixed(2),
                '',
            ]);
        }

        const csv = rows
            .map((row) =>
                row
                    .map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`)
                    .join(','),
            )
            .join('\r\n');

        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `reserve-fund-${fiscalYear}-m${periodMonth}-${percentLabel}percent-${importId || 'latest'}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    };

    return (
        <div className="max-w-7xl mx-auto space-y-4">
            {/* Header */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between print:mb-2 print:gap-1">
                <div>
                    <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">
                        คำนวณเงินสำรอง
                    </h2>
                    <p className="text-gray-500 text-sm">
                        คำนวณจากฐานรายรับ = เงินเดือน + ล่วงเวลา (OT) + เงินประจำตำแหน่ง + เงิน P4P
                    </p>
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={saving || loading || percent === '' || isConfirmed}
                        title={isConfirmed ? 'งวดนี้ยืนยันแล้ว ต้องยกเลิกการยืนยันก่อนแก้ไข' : undefined}
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
                        title={!savedInfo ? 'บันทึกผลการคำนวณของงวดนี้ก่อน' : undefined}
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
                        {isConfirmed ? 'ยกเลิกการยืนยันงวด' : 'ยืนยันงวดนี้'}
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

            {/* Import selector + เปอร์เซ็นต์ที่ใช้คำนวณ + ปีงบประมาณที่บันทึก */}
            <div className="rounded-xl border border-gray-200 bg-white p-4 print:hidden">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label
                            htmlFor="payroll-import"
                            className="block text-sm font-medium text-gray-700 mb-2"
                        >
                            เลือกชุดข้อมูล Payroll (Import)
                        </label>
                        <select
                            id="payroll-import"
                            value={importId}
                            onChange={(e) => handleImportChange(e.target.value)}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        >
                            <option value="">— เลือกชุดข้อมูล —</option>
                            {imports.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.file_name || `Import #${item.id}`} · {formatDate(item.created_at)}
                                </option>
                            ))}
                        </select>

                        {summary?.imported_at && (
                            <p className="mt-2 text-xs text-gray-500">
                                ข้อมูลล่าสุด: {formatDate(summary.imported_at)}
                            </p>
                        )}
                    </div>

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
                                    aria-describedby="reserve-percent-help"
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
                        <p id="reserve-percent-help" className="mt-2 text-xs text-gray-500">
                            กรอกได้ตั้งแต่ 0–100 (รองรับทศนิยม) กำลังแสดงผลที่ {percentLabel}%
                        </p>
                    </div>

                    <div>
                        <label
                            htmlFor="reserve-period-month"
                            className="block text-sm font-medium text-gray-700 mb-2"
                        >
                            งวด Payroll (เดือน)
                        </label>
                        <select
                            id="reserve-period-month"
                            value={periodMonth}
                            onChange={(e) => handlePeriodMonthChange(e.target.value)}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        >
                            {FISCAL_MONTHS.map((month) => (
                                <option key={month} value={month}>
                                    {MONTH_LABELS[month]} {calendarYearOf(fiscalYear, month)}
                                </option>
                            ))}
                        </select>
                        <p className="mt-2 text-xs text-gray-500">
                            ปีงบประมาณ {fiscalYear} = ต.ค. {fiscalYear - 1} ถึง ก.ย. {fiscalYear}
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
                                {hasSavedYears
                                    ? 'เปลี่ยนได้ที่แถบด้านบน'
                                    : 'บันทึกแล้วจะเลือกปีได้ที่แถบด้านบน'}
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
                        <p className="mt-2 text-xs text-gray-500">
                            {savedInfo
                                ? `งวด ${savedInfo.period_label || '-'}: บันทึกไว้ที่ ${formatPercent(savedInfo.percent)}%`
                                    + ` · ${isConfirmed ? 'ยืนยันแล้ว' : 'ร่าง (ยังไม่นับในยอดสะสม)'}`
                                : 'ยังไม่มีผลการคำนวณที่บันทึกไว้สำหรับงวดนี้'}
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

            {/* เงินสำรองสะสมของปีงบประมาณ — รวมเฉพาะงวดที่ยืนยันแล้ว */}
            <div className="rounded-xl border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="rounded-lg p-2.5 bg-[#FBF7EE] text-[#8B5E3C] shrink-0">
                            <PiggyBank className="w-5 h-5" />
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">
                                เงินสำรองสะสม ปีงบประมาณ {fiscalYear}
                            </p>
                            <p className="text-2xl font-semibold text-[#8B5E3C]">
                                {formatMoney(accumulated?.total_reserve)} บาท
                            </p>
                        </div>
                    </div>
                    <div className="text-right text-xs text-gray-500">
                        <p>
                            ยืนยันแล้ว{' '}
                            <span className="font-semibold text-gray-700">
                                {formatNumber(accumulated?.confirmed_periods)}
                            </span>
                            {' / '}
                            {formatNumber(accumulated?.total_periods ?? 12)} งวด
                        </p>
                        <p className="mt-1">
                            ฐานรายรับสะสม {formatMoney(accumulated?.total_income_base)} บาท
                        </p>
                    </div>
                </div>

                {accumulated?.periods?.length > 0 ? (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                งวดที่ยืนยันแล้วในปีงบประมาณ {fiscalYear}
                            </caption>
                            <thead>
                                <tr className="bg-gray-50 text-left text-gray-600">
                                    <th scope="col" className="px-4 py-2 font-semibold">งวด</th>
                                    <th scope="col" className="px-4 py-2 font-semibold text-right">%</th>
                                    <th scope="col" className="px-4 py-2 font-semibold text-right">ฐานรายรับ (บาท)</th>
                                    <th scope="col" className="px-4 py-2 font-semibold text-right">เงินสำรอง (บาท)</th>
                                    <th scope="col" className="px-4 py-2 font-semibold text-right">จำนวน (คน)</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {accumulated.periods.map((period) => (
                                    <tr key={period.id}>
                                        <td className="px-4 py-2 text-gray-700">
                                            {period.period_label} {period.period_year}
                                        </td>
                                        <td className="px-4 py-2 text-right text-gray-700">
                                            {formatPercent(period.percent)}
                                        </td>
                                        <td className="px-4 py-2 text-right text-gray-700">
                                            {formatMoney(period.total_income_base)}
                                        </td>
                                        <td className="px-4 py-2 text-right font-medium text-[#8B5E3C]">
                                            {formatMoney(period.total_reserve)}
                                        </td>
                                        <td className="px-4 py-2 text-right text-gray-700">
                                            {formatNumber(period.total_employees)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="bg-[#FBF7EE] font-semibold text-[#8B5E3C]">
                                    <td className="px-4 py-2">รวมสะสม</td>
                                    <td className="px-4 py-2" />
                                    <td className="px-4 py-2 text-right">
                                        {formatMoney(accumulated.total_income_base)}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        {formatMoney(accumulated.total_reserve)}
                                    </td>
                                    <td className="px-4 py-2" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                ) : (
                    <p className="mt-4 text-sm text-gray-500">
                        ยังไม่มีงวดที่ยืนยันในปีงบประมาณนี้ — บันทึกผลการคำนวณของงวด แล้วกด «ยืนยันงวดนี้»
                        เพื่อนำยอดเข้าสะสม
                    </p>
                )}
            </div>

            {!loading && unassignedCount > 0 && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start gap-2 print:hidden">
                    <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                    <span>
                        มีบุคลากร{' '}
                        <span className="font-semibold">{formatNumber(unassignedCount)}</span> คน
                        ที่ยังไม่ระบุตำแหน่ง/ภารกิจ/กลุ่มงาน/งาน จึงถูกนับรวมในกลุ่ม «ไม่ระบุภารกิจ»
                        กรุณาอัปเดตข้อมูลในหน้ารายชื่อบุคลากร
                    </span>
                </div>
            )}

            {/* Summary Stats */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-green-100 text-green-700 shrink-0">
                        <Wallet className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">ฐานรายรับรวม</p>
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
                        <p className="text-xs text-gray-500 truncate">เงินสำรอง {percentLabel}%</p>
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
                duties.length > 0 && (
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
                                                dataKey="value"
                                                nameKey="name"
                                                innerRadius={50}
                                                outerRadius={90}
                                                paddingAngle={2}
                                            >
                                                {chartData.map((entry, idx) => (
                                                    <Cell
                                                        key={idx}
                                                        fill={PIE_COLORS[idx % PIE_COLORS.length]}
                                                    />
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
                                    เปรียบเทียบฐานรายรับกับเงินสำรอง {percentLabel}% ตาม{GROUP_BY_LABELS[groupBy]}
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
                                                dataKey="value"
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
                    <h3 className="font-semibold text-gray-800">รายละเอียดจำแนกตามภารกิจ / กลุ่มงาน / งาน</h3>
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
                                    <th className="px-4 py-3 font-semibold">ภารกิจ / กลุ่มงาน / งาน</th>
                                    <th className="px-4 py-3 font-semibold text-right">ฐานรายรับ (บาท)</th>
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
    return (
        <>
            {duty.works.map((work, wIdx) => (
                <tr key={wIdx} className={wIdx === 0 ? 'bg-[#FBF7EE]' : undefined}>
                    <td className="px-4 py-3">
                        <div className="font-semibold text-gray-800">
                            {duty.name}
                        </div>
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