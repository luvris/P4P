import { useMemo, useState } from 'react';
import {
    Loader2,
    Landmark,
    Wallet,
    Users,
    FileSpreadsheet,
    Printer,
    Download,
    AlertTriangle,
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
    const { imports, importId, data, loading, error, handleImportChange } = useReserveFund();

    const duties = useMemo(() => data?.data?.duties || [], [data]);
    const summary = data?.summary || null;

    const [groupBy, setGroupBy] = useState('duty');

    const chartData = useMemo(() => {
        if (groupBy === 'duty') {
            return duties.map((duty) => ({
                id: duty.id,
                name: duty.name,
                net_income: duty.net_income,
                value: duty.reserve_3_percent,
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
                    map.set(key, { id: key, name, net_income: 0, value: 0 });
                }
                const item = map.get(key);
                item.net_income += work.net_income;
                item.value += work.reserve_3_percent;
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
        const rows = [
            ['ภารกิจ', 'กลุ่มงาน', 'งาน', 'รายได้สุทธิ (บาท)', 'สำรอง 3% (บาท)', 'จำนวน (คน)'],
        ];

        duties.forEach((duty) => {
            duty.works.forEach((work) => {
                rows.push([
                    duty.name,
                    work.group_name,
                    work.name,
                    work.net_income.toFixed(2),
                    work.reserve_3_percent.toFixed(2),
                    work.employee_count,
                ]);
            });
            rows.push([
                `รวมภารกิจ: ${duty.name}`,
                '',
                '',
                duty.net_income.toFixed(2),
                duty.reserve_3_percent.toFixed(2),
                duty.employee_count,
            ]);
        });

        if (summary) {
            rows.push([
                'รวมทั้งหมด',
                '',
                '',
                summary.total_net_income.toFixed(2),
                summary.total_reserve.toFixed(2),
                summary.total_employees,
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
        link.download = `reserve-fund-3percent-${importId || 'latest'}.csv`;
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
                        คำนวณเงินสำรอง 3%
                    </h2>
                    <p className="text-gray-500 text-sm">
                        สำรองค่าใช้จ่ายโครงการ 3% จากรายได้สุทธิ (Net Income) ในตาราง Payroll
                    </p>
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        <Printer className="w-4 h-4" />
                        พิมพ์งาน
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

            {/* Import selector */}
            <div className="rounded-xl border border-gray-200 bg-white p-4 print:hidden">
                <label className="block text-sm font-medium text-gray-700 mb-2">
                    เลือกชุดข้อมูล Payroll (Import)
                </label>
                <div className="flex flex-col sm:flex-row gap-3 sm:items-center">
                    <select
                        value={importId}
                        onChange={(e) => handleImportChange(e.target.value)}
                        className="w-full sm:max-w-xs rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                    >
                        <option value="">— เลือกชุดข้อมูล —</option>
                        {imports.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.file_name || `Import #${item.id}`} · {formatDate(item.created_at)}
                            </option>
                        ))}
                    </select>

                    {summary?.imported_at && (
                        <span className="text-xs text-gray-500">
                            ข้อมูลล่าสุด: {formatDate(summary.imported_at)}
                        </span>
                    )}
                </div>
            </div>

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

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
                        <p className="text-xs text-gray-500 truncate">รายได้สุทธิรวม (Net Income)</p>
                        <p className="text-2xl font-semibold text-gray-800">
                            {loading ? (
                                <span className="inline-block w-20 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatMoney(summary?.total_net_income)}
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
                        <p className="text-xs text-gray-500 truncate">เงินสำรอง 3%</p>
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
                                    สัดส่วนเงินสำรอง 3% ตาม{GROUP_BY_LABELS[groupBy]}
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
                                    เปรียบเทียบรายได้สุทธิกับเงินสำรอง 3% ตาม{GROUP_BY_LABELS[groupBy]}
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
                                                dataKey="net_income"
                                                name="รายได้สุทธิ"
                                                fill="#C5A059"
                                                radius={[0, 4, 4, 0]}
                                            />
                                            <Bar
                                                dataKey="value"
                                                name="สำรอง 3%"
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
                                    <th className="px-4 py-3 font-semibold text-right">รายได้สุทธิ (บาท)</th>
                                    <th className="px-4 py-3 font-semibold text-right">สำรอง 3% (บาท)</th>
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
                                        {formatMoney(summary?.total_net_income)}
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
                        {formatMoney(work.net_income)}
                    </td>
                    <td className="px-4 py-3 text-right font-medium text-[#8B5E3C]">
                        {formatMoney(work.reserve_3_percent)}
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
                    {formatMoney(duty.net_income)}
                </td>
                <td className="px-4 py-2 text-right font-semibold text-[#8B5E3C]">
                    {formatMoney(duty.reserve_3_percent)}
                </td>
                <td className="px-4 py-2 text-right font-semibold text-gray-700">
                    {formatNumber(duty.employee_count)}
                </td>
            </tr>
        </>
    );
};

export default ReserveFundPage;