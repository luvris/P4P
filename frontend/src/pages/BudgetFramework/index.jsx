import { useState } from 'react';
import {
    Loader2,
    Save,
    Download,
    Percent,
    Wallet,
    Landmark,
    Users,
    AlertTriangle,
    CheckCircle2,
    CalendarClock,
    FileSpreadsheet,
    Info,
} from 'lucide-react';
import useBudgetFramework from '../../hooks/useBudgetFramework';
import PositionGroupEditor from './PositionGroupEditor';

const formatMoney = (value) =>
    new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value ?? 0);

const formatNumber = (value) => new Intl.NumberFormat('th-TH').format(value ?? 0);

const formatWeight = (value) =>
    new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value ?? 0);

const formatPercent = (value) =>
    new Intl.NumberFormat('th-TH', { maximumFractionDigits: 2 }).format(value ?? 0);

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

const BudgetFrameworkPage = () => {
    const {
        fiscalYear,
        options,
        params,
        setField,
        setWeight,
        summary,
        savedForYear,
        positionGroups,
        loading,
        saving,
        savingGroups,
        error,
        save,
        savePositionGroups,
        exportExcel,
        exportSaved,
    } = useBudgetFramework();

    const [note, setNote] = useState('');
    const [status, setStatus] = useState(null);
    const [exporting, setExporting] = useState(false);

    const groupOptions = options?.groups || positionGroups?.groups || [];
    const positions = positionGroups?.positions || [];

    const groups = summary?.groups || [];
    const total = summary?.total || null;
    const activeStatus = status?.fiscalYear === fiscalYear ? status : null;

    const unclassified =
        groups.find((group) => group.code === 'unclassified' && group.headcount > 0) || null;

    const ratioSum = Number(params.activity_ratio || 0) + Number(params.quality_ratio || 0);
    const ratioMismatch = Math.abs(ratioSum - 100) > 0.001;

    const monthsPresent = summary?.months_present ?? 0;
    const asOfLabel = summary?.as_of_label;

    const weightOf = (group) => {
        const value = params.weights?.[group.code];
        return value === undefined || value === null || value === '' ? group.weight : value;
    };

    const handleSave = async () => {
        setStatus(null);
        const result = await save({ note });
        setStatus({
            fiscalYear,
            type: result.success ? 'success' : 'error',
            message: result.success ? result.message || 'บันทึกสำเร็จ' : result.error,
        });
    };

    const handleExport = async () => {
        setStatus(null);
        setExporting(true);
        try {
            await exportExcel();
        } catch (err) {
            setStatus({ fiscalYear, type: 'error', message: err.message || 'ไม่สามารถส่งออก Excel ได้' });
        } finally {
            setExporting(false);
        }
    };

    const handleExportSaved = async () => {
        if (!savedForYear) return;
        setStatus(null);
        setExporting(true);
        try {
            await exportSaved(savedForYear.id, savedForYear.fiscal_year);
        } catch (err) {
            setStatus({ fiscalYear, type: 'error', message: err.message || 'ไม่สามารถส่งออก Excel ได้' });
        } finally {
            setExporting(false);
        }
    };

    const cards = [
        {
            label: 'ค่าแรง (Labor cost) ต่อเดือน',
            value: summary?.labor_cost?.monthly,
            icon: Wallet,
            tone: 'bg-blue-100 text-blue-700',
        },
        {
            label: 'ค่าแรง (Labor cost) ต่อปี',
            value: summary?.labor_cost?.annual,
            icon: Landmark,
            tone: 'bg-amber-100 text-amber-700',
        },
        {
            label: `จ่าย P4P ต่อปี (${formatPercent(summary?.labor_percent)}%)`,
            value: summary?.p4p_annual,
            icon: Percent,
            tone: 'bg-green-100 text-green-700',
        },
        {
            label: `วงเงินตามปริมาณงาน (${formatPercent(summary?.activity_ratio)}%)`,
            value: summary?.activity_budget,
            icon: FileSpreadsheet,
            tone: 'bg-[#F5EEDC] text-[#8B5E3C]',
        },
        {
            label: `วงเงินเพื่อการพัฒนาคุณภาพ (${formatPercent(summary?.quality_ratio)}%)`,
            value: summary?.quality_budget,
            icon: Users,
            tone: 'bg-purple-100 text-purple-700',
        },
    ];

    return (
        <div className="max-w-7xl mx-auto space-y-4">
            {/* Header */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">กรอบวงเงิน P4P</h2>
                    <p className="text-gray-500 text-sm">
                        คำนวณจากค่าแรงจริงในไฟล์เงินเดือน × ร้อยละที่กำหนด แล้วเฉลี่ยวงเงินตามสัดส่วนวิชาชีพ
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={saving || loading || !summary || total?.headcount === 0}
                        className="inline-flex items-center gap-2 rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] hover:bg-[#FBF7EE] disabled:opacity-50"
                    >
                        {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                        บันทึกกรอบวงเงิน
                    </button>
                    <button
                        type="button"
                        onClick={handleExport}
                        disabled={exporting || loading || !summary || total?.headcount === 0}
                        className="inline-flex items-center gap-2 rounded-lg bg-[#8B5E3C] px-3 py-2 text-sm font-medium text-white hover:bg-[#6B4F3A] disabled:opacity-50"
                    >
                        {exporting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Download className="w-4 h-4" />}
                        ส่งออก Excel
                    </button>
                    {savedForYear && (
                        <button
                            type="button"
                            onClick={handleExportSaved}
                            disabled={exporting}
                            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                            title="ส่งออกจากยอดที่บันทึกไว้ (ไม่เปลี่ยนตามค่าที่แก้บนหน้าจอ)"
                        >
                            <Download className="w-4 h-4" />
                            ส่งออกฉบับที่บันทึก
                        </button>
                    )}
                </div>
            </div>

            {/* Control card */}
            <div className="rounded-xl border border-gray-200 bg-white p-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">ปีงบประมาณ</label>
                        <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                            <CalendarClock className="h-4 w-4 shrink-0 text-gray-400" />
                            <span className="font-medium">ปีงบประมาณ {fiscalYear}</span>
                            <span className="ml-auto text-xs text-gray-500">
                                ต.ค. {fiscalYear - 1} – ก.ย. {fiscalYear}
                            </span>
                        </div>
                    </div>

                    <div>
                        <label htmlFor="cost-basis" className="block text-sm font-medium text-gray-700 mb-2">
                            ฐานค่าแรง (Labor cost)
                        </label>
                        <select
                            id="cost-basis"
                            value={params.cost_basis}
                            onChange={(e) => setField('cost_basis', e.target.value)}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        >
                            {(options?.cost_bases || []).map((basis) => (
                                <option key={basis.value} value={basis.value}>
                                    {basis.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label htmlFor="labor-percent" className="block text-sm font-medium text-gray-700 mb-2">
                            ร้อยละของค่าแรง (%)
                        </label>
                        <div className="relative">
                            <Percent className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                id="labor-percent"
                                type="number"
                                inputMode="decimal"
                                min="0"
                                max="100"
                                step="0.01"
                                value={params.labor_percent}
                                onChange={(e) => setField('labor_percent', e.target.value)}
                                className="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">หมายเหตุ</label>
                        <input
                            type="text"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            maxLength={1000}
                            placeholder="หมายเหตุ (ไม่บังคับ)"
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        />
                    </div>
                </div>

                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label htmlFor="activity-ratio" className="block text-sm font-medium text-gray-700 mb-2">
                            วงเงินตามปริมาณงาน (Activity) %
                        </label>
                        <input
                            id="activity-ratio"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            value={params.activity_ratio}
                            onChange={(e) => setField('activity_ratio', e.target.value)}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        />
                    </div>
                    <div>
                        <label htmlFor="quality-ratio" className="block text-sm font-medium text-gray-700 mb-2">
                            วงเงินเพื่อการพัฒนาคุณภาพ (Quality) %
                        </label>
                        <input
                            id="quality-ratio"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            value={params.quality_ratio}
                            onChange={(e) => setField('quality_ratio', e.target.value)}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">สถานะการบันทึก</label>
                        <div
                            className={`rounded-lg border px-3 py-2 text-sm ${
                                savedForYear
                                    ? 'border-green-200 bg-green-50 text-green-700'
                                    : 'border-gray-200 bg-gray-50 text-gray-700'
                            }`}
                        >
                            {savedForYear ? (
                                <>
                                    <p className="font-medium">บันทึกไว้แล้ว</p>
                                    <p className="mt-0.5 text-xs">
                                        {formatPercent(savedForYear.labor_percent)}% ·{' '}
                                        {formatDate(savedForYear.saved_at)}
                                    </p>
                                </>
                            ) : (
                                'ยังไม่ได้บันทึกกรอบวงเงินของปีงบนี้'
                            )}
                        </div>
                    </div>
                </div>

                {ratioMismatch && (
                    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
                        <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                        <span>
                            สัดส่วน Activity + Quality = {formatPercent(ratioSum)}% (ปกติต้องรวมได้ 100%)
                        </span>
                    </div>
                )}

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

            {/* Summary cards */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {cards.map((card) => {
                    const Icon = card.icon;
                    return (
                        <div key={card.label} className="rounded-xl border border-gray-200 bg-white p-4">
                            <div className={`rounded-lg p-2.5 ${card.tone} inline-flex`}>
                                <Icon className="w-5 h-5" />
                            </div>
                            <p className="mt-2 text-xs text-gray-500">{card.label}</p>
                            <p className="text-lg font-semibold text-gray-800">
                                {loading ? (
                                    <span className="inline-block w-20 h-6 bg-gray-200 rounded animate-pulse" />
                                ) : (
                                    <>
                                        {formatMoney(card.value)}
                                        <span className="text-xs font-normal text-gray-500 ml-1">บาท</span>
                                    </>
                                )}
                            </p>
                        </div>
                    );
                })}
            </div>

            {/* Data completeness */}
            {!loading && summary && (
                <div
                    className={`rounded-lg border px-4 py-3 text-sm flex items-start gap-2 ${
                        monthsPresent >= 12
                            ? 'border-green-200 bg-green-50 text-green-700'
                            : 'border-amber-200 bg-amber-50 text-amber-800'
                    }`}
                >
                    {monthsPresent >= 12 ? (
                        <CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0" />
                    ) : (
                        <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                    )}
                    <span>
                        ข้อมูลค่าแรงของปีงบ {fiscalYear}: {monthsPresent}/12 งวด
                        {asOfLabel ? ` · คิดค่าแรง ณ ${asOfLabel}` : ''}
                        {monthsPresent < 12 && ' — ค่าแรงต่อปีคำนวณจากค่าเฉลี่ยต่อเดือนของงวดที่มีข้อมูล'}
                    </span>
                </div>
            )}

            {/* Unclassified */}
            {unclassified && (
                <div className="rounded-xl border border-amber-300 bg-amber-50 p-4">
                    <div className="flex items-center gap-2 text-sm font-semibold text-amber-900">
                        <AlertTriangle className="w-4 h-4 shrink-0" />
                        <span>มีตำแหน่งที่จับคู่กลุ่มวิชาชีพไม่ได้ {formatNumber(unclassified.headcount)} คน</span>
                    </div>
                    <p className="mt-1 text-xs text-amber-800">
                        ตำแหน่งเหล่านี้จะไม่ได้รับวงเงินเฉลี่ย (สัดส่วนเริ่มต้น 0) — ตรวจชื่อตำแหน่งในทะเบียนบุคลากร
                        หรือกำหนดสัดส่วนเองในตารางด้านล่าง
                    </p>
                    <ul className="mt-2 flex flex-wrap gap-2">
                        {(unclassified.positions || []).map((position) => (
                            <li
                                key={position.name}
                                className="rounded-full border border-amber-200 bg-white px-2 py-0.5 text-xs text-amber-900"
                            >
                                {position.name} ({position.count})
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Table */}
            <div className="rounded-xl border border-gray-200 bg-white p-4">
                <div className="flex items-center gap-2 mb-3">
                    <Info className="w-4 h-4 text-gray-400" />
                    <p className="text-sm text-gray-600">
                        ประมาณการ อัตราค่าตอบแทนต่อคน ตามสัดส่วนวิชาชีพ — ปรับ «สัดส่วน» ต่อกลุ่มได้ แล้วเงินจะเฉลี่ยใหม่ทันที
                    </p>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-gray-50 text-left text-gray-600">
                                <th scope="col" className="px-3 py-2 font-semibold">กลุ่มวิชาชีพ</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-right">จำนวนคน</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-center bg-yellow-100">สัดส่วน</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-right">รวมสัดส่วน</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-right">รวมเงิน/ปี</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-right">รวมเงิน/เดือน</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-right">เฉลี่ย/คน/ปี</th>
                                <th scope="col" className="px-3 py-2 font-semibold text-right">เฉลี่ย/คน/เดือน</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {loading && !summary ? (
                                <tr>
                                    <td colSpan={8} className="px-3 py-8 text-center text-gray-400">
                                        <span className="inline-flex items-center gap-2">
                                            <Loader2 className="w-4 h-4 animate-spin" />
                                            กำลังคำนวณ...
                                        </span>
                                    </td>
                                </tr>
                            ) : (
                                groups.map((group, index) => (
                                    <tr key={group.code} className="hover:bg-gray-50 align-top">
                                        <td className="px-3 py-2 text-gray-700">
                                            <div className="flex gap-2">
                                                <span className="text-gray-400 shrink-0">{index + 1}</span>
                                                <div>
                                                    <p className="font-medium">{group.name}</p>
                                                    {group.examples && (
                                                        <p className="text-xs text-gray-400">เช่น {group.examples}</p>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            {formatNumber(group.headcount)}
                                        </td>
                                        <td className="px-3 py-2 bg-yellow-50">
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={weightOf(group)}
                                                onChange={(e) => setWeight(group.code, e.target.value)}
                                                aria-label={`สัดส่วน ${group.name}`}
                                                className="w-20 rounded-lg border border-gray-300 bg-white px-2 py-1 text-right text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            {formatWeight(group.weighted)}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            {formatMoney(group.amount_year)}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            {formatMoney(group.amount_month)}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            {formatMoney(group.avg_year)}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            {formatMoney(group.avg_month)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                        {total && (
                            <tfoot>
                                <tr className="bg-[#FBF7EE] font-semibold text-[#8B5E3C]">
                                    <td className="px-3 py-2">รวม</td>
                                    <td className="px-3 py-2 text-right">{formatNumber(total.headcount)}</td>
                                    <td className="px-3 py-2 text-center">{formatWeight(total.weight)}</td>
                                    <td className="px-3 py-2 text-right">{formatWeight(total.weighted)}</td>
                                    <td className="px-3 py-2 text-right">{formatMoney(total.amount_year)}</td>
                                    <td className="px-3 py-2 text-right">{formatMoney(total.amount_month)}</td>
                                    <td className="px-3 py-2 text-right">{formatMoney(total.avg_year)}</td>
                                    <td className="px-3 py-2 text-right">{formatMoney(total.avg_month)}</td>
                                </tr>
                                <tr className="text-[#8B5E3C]">
                                    <td className="px-3 py-2" colSpan={3}>
                                        รวมเงิน P4P ต่อหน่วย (KPI)
                                    </td>
                                    <td className="px-3 py-2 text-right" colSpan={5}>
                                        {formatMoney(summary?.unit_rate_month)} บาท / หน่วย / เดือน
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>

            <PositionGroupEditor
                positions={positions}
                groupOptions={groupOptions}
                periodLabel={positionGroups?.period_label}
                saving={savingGroups}
                onSave={savePositionGroups}
            />
        </div>
    );
};

export default BudgetFrameworkPage;
