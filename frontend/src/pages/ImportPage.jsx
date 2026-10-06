import React, { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import FileDropZone from '../components/features/import/FileDropZone';
import ImportSummary from '../components/features/import/ImportSummary';
import SelectedFilePanel from '../components/features/import/SelectedFilePanel';
import PreviewTable from '../components/features/import/PreviewTable';
import TemplateDownloadButton from '../components/features/import/TemplateDownloadButton';
import ExtraColumnsPanel from '../components/features/import/ExtraColumnsPanel';
import { importService } from '../services/importService';
import { AlertTriangle, Users, SlidersHorizontal } from 'lucide-react';
import useAuth from '../hooks/useAuth';
import useFiscalYear from '../hooks/useFiscalYear';
import { FISCAL_MONTHS, MONTH_LABELS, calendarYearOf, currentMonth } from '../utils/fiscalPeriod';

/**
 * อัปโหลดไฟล์เงินเดือน — ได้ทั้งทะเบียนบุคลากรและแถวเงินเดือนจากไฟล์เดียว
 *
 * คนละชั้นหน้าเดิม (HR / การเงิน) ใช้หน้านี้ร่วมกัน เพราะไฟล์และผลลัพธ์เดียวกัน
 */
const ImportPage = () => {
    const { hasRole } = useAuth();
    // การเพิ่มคอลัมน์กระทบไฟล์ต้นแบบของทุกคน จึงจำกัดไว้ที่ admin
    const canManageColumns = hasRole('admin');

    const [selectedFile, setSelectedFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [summary, setSummary] = useState(null);
    const [employeeResult, setEmployeeResult] = useState(null);
    const [previewData, setPreviewData] = useState([]);
    const [warning, setWarning] = useState('');
    const [unlinkedSummary, setUnlinkedSummary] = useState(null);
    const [showColumns, setShowColumns] = useState(false);

    // งวดที่ export — ดึงจากระบบเพื่อไม่ให้เลือกงวดที่ยังไม่มีข้อมูลจริง
    const [periods, setPeriods] = useState([]);
    const [exportPeriod, setExportPeriod] = useState('');

    useEffect(() => {
        let active = true;

        importService.getPeriods()
            .then((result) => {
                if (!active) return;

                const list = result.data || [];
                setPeriods(list);
                // ค่าเริ่มต้นเป็นงวดใหม่สุดที่มีข้อมูล
                setExportPeriod(list[0] ? `${list[0].period_year}-${list[0].period_month}` : '');
            })
            .catch(() => {
                // ไม่มีงวดให้เลือก = ยัง export ไม่ได้ ปุ่มจะไม่แสดง
                if (active) setPeriods([]);
            });

        return () => { active = false; };
    }, []);

    // งวดของไฟล์ payroll — ใช้คำนวณเงินสำรองรายปี (เริ่มที่เดือนปัจจุ่น)
    const { fiscalYear } = useFiscalYear();
    const [periodMonth, setPeriodMonth] = useState(currentMonth);

    const reset = () => {
        setSummary(null);
        setEmployeeResult(null);
        setPreviewData([]);
        setWarning('');
        setUnlinkedSummary(null);
    };

    const handleFileSelect = (file) => {
        const validExtensions = ['xlsx', 'xls'];
        const extension = file.name.split('.').pop().toLowerCase();

        if (!validExtensions.includes(extension)) {
            toast.error('รองรับเฉพาะไฟล์ .xlsx หรือ .xls');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            toast.error('ขนาดไฟล์ต้องไม่เกิน 10 MB');
            return;
        }

        setSelectedFile(file);
        reset();
    };

    const handleClear = () => {
        setSelectedFile(null);
        reset();
    };

    const handleImport = async () => {
        if (!selectedFile) return;

        setLoading(true);
        try {
            const result = await importService.uploadFile(selectedFile, {
                fiscal_year: fiscalYear,
                period_month: Number(periodMonth),
            });

            setSummary(result.import);
            setEmployeeResult(result.employee || null);
            setPreviewData(result.preview || []);
            setWarning(result.warning || '');
            setUnlinkedSummary(result.unlinked_summary || null);

            if (result.warning) {
                toast(result.warning, { icon: '⚠️' });
            } else {
                toast.success('นำเข้าข้อมูลสำเร็จ!');
            }
        } catch (error) {
            const message = error.response?.data?.message || 'เกิดข้อผิดพลาด';
            toast.error(message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="max-w-7xl mx-auto">
            <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">
                    นำเข้าข้อมูล
                </h2>
                <p className="text-gray-500 text-sm">
                    ไฟล์เงินเดือน 39 คอลัมน์ — อัปโหลดครั้งเดียวได้ทั้งทะเบียนบุคลากร
                    และแถวเงินเดือนรายงวด
                </p>
                <div className="mt-3 flex flex-wrap items-start gap-2">
                    <TemplateDownloadButton
                        endpoint="/imports/template"
                        label="ดาวน์โหลดแบบฟอร์มกรอกข้อมูล"
                        hint="ไฟล์ต้นแบบ 39 คอลัมน์ — กรอกแล้วอัปโหลดกลับเข้ามาได้เลย"
                    />

                    {periods.length > 0 && exportPeriod && (
                        <div className="flex flex-wrap items-end gap-2">
                            <div>
                                <label
                                    htmlFor="export-period"
                                    className="mb-1 block text-xs font-medium text-gray-600"
                                >
                                    งวดที่จะ export
                                </label>
                                <select
                                    id="export-period"
                                    value={exportPeriod}
                                    onChange={(e) => setExportPeriod(e.target.value)}
                                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                                >
                                    {periods.map((period) => (
                                        <option
                                            key={`${period.period_year}-${period.period_month}`}
                                            value={`${period.period_year}-${period.period_month}`}
                                        >
                                            {period.label} ({period.rows} คน)
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <TemplateDownloadButton
                                endpoint={importService.exportUrl(periods.find(
                                    (p) => `${p.period_year}-${p.period_month}` === exportPeriod,
                                ) || periods[0])}
                                label="Export ข้อมูล"
                                hint="หัวตารางเดียวกับแบบฟอร์ม แต่มีข้อมูลของงวดที่เลือกอยู่แล้ว — แก้แล้วอัปโหลดกลับได้"
                            />
                        </div>
                    )}

                    {canManageColumns && (
                        <button
                            type="button"
                            onClick={() => setShowColumns((v) => !v)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] transition-colors hover:bg-[#F5EEDC]"
                        >
                            <SlidersHorizontal size={15} />
                            {showColumns ? 'ซ่อนคอลัมน์เพิ่มเติม' : 'จัดการคอลัมน์เพิ่มเติม'}
                        </button>
                    )}
                </div>
            </div>

            {canManageColumns && showColumns && (
                <div className="mb-6">
                    <ExtraColumnsPanel />
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Left: Drop Zone + Preview Table */}
                <div className="lg:col-span-2 space-y-6">
                    <FileDropZone
                        onFileSelect={handleFileSelect}
                        selectedFile={selectedFile}
                        onClear={handleClear}
                    />

                    {previewData.length > 0 && (
                        <PreviewTable data={previewData} />
                    )}
                </div>

                {/* Right: Summary + Selected File */}
                <div className="space-y-6">
                    {selectedFile && !summary && (
                        <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
                            <h3 className="text-lg font-semibold text-gray-700 mb-4">
                                งวดของไฟล์ (Payroll)
                            </h3>
                            <label
                                htmlFor="import-period-month"
                                className="block text-sm font-medium text-gray-700 mb-2"
                            >
                                งวดเดือน
                            </label>
                            <select
                                id="import-period-month"
                                value={periodMonth}
                                onChange={(e) => setPeriodMonth(e.target.value)}
                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                            >
                                {FISCAL_MONTHS.map((month) => (
                                    <option key={month} value={month}>
                                        {MONTH_LABELS[month]} {calendarYearOf(fiscalYear, month)}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-2 text-xs text-gray-500">
                                ไฟล์ที่มีคอลัมน์ปี/เดือนจะใช้งวดของแต่ละแถวจริง
                                ค่านี้เป็นเพียงงวดสำรอง
                            </p>
                        </div>
                    )}

                    {selectedFile && !summary && (
                        <SelectedFilePanel
                            file={selectedFile}
                            onImport={handleImport}
                            onCancel={handleClear}
                            loading={loading}
                        />
                    )}

                    {warning && (
                        <div className="flex items-start gap-2 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{warning}</span>
                        </div>
                    )}

                    {employeeResult && (
                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                            <div className="flex items-center gap-2 text-sm font-medium text-emerald-900">
                                <Users className="h-4 w-4 shrink-0" />
                                <span>
                                    ทะเบียนบุคลากร: เพิ่มใหม่ {employeeResult.inserted.toLocaleString()}
                                    {' / '}อัปเดต {employeeResult.updated.toLocaleString()} คน
                                </span>
                            </div>
                        </div>
                    )}

                    {unlinkedSummary && unlinkedSummary.rows > 0 && (
                        <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4">
                            <div className="flex items-center gap-2 text-sm font-medium text-amber-900">
                                <AlertTriangle className="h-4 w-4 shrink-0" />
                                <span>
                                    เงิน {unlinkedSummary.total_income.toLocaleString('th-TH', { minimumFractionDigits: 2 })} บาท
                                    ของ {unlinkedSummary.rows.toLocaleString()} คน-งวด
                                    ยังไม่ถูกนับในฐานเงินสำรอง
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-amber-800">
                                ข้อมูลถูกนำเข้าเรียบร้อยแล้ว แต่ต้องมีเลขบัตรประชาชนที่ตรงกับทะเบียนบุคลากร
                                จึงจะถูกนับ — กรุณาตรวจสอบเลขบัตรในไฟล์ให้ถูกต้อง แล้วอัปโหลดไฟล์เดิมซ้ำอีกครั้ง
                            </p>
                            {unlinkedSummary.samples?.length > 0 && (
                                <details className="mt-2">
                                    <summary className="cursor-pointer text-xs font-medium text-amber-900">
                                        ดูรายชื่อ ({unlinkedSummary.rows.toLocaleString()} รายการ)
                                    </summary>
                                    <ul className="mt-2 max-h-56 space-y-1 overflow-y-auto">
                                        {unlinkedSummary.samples.map((item, i) => (
                                            <li
                                                key={i}
                                                className="rounded-lg border border-amber-200 bg-white px-3 py-1.5 text-xs text-gray-700"
                                            >
                                                {item.first_name} {item.last_name}
                                                <span className="ml-2 text-gray-500">
                                                    {Number(item.total_income).toLocaleString('th-TH', { minimumFractionDigits: 2 })} บาท
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </div>
                    )}

                    {summary && (
                        <ImportSummary summary={summary} />
                    )}
                </div>
            </div>
        </div>
    );
};

export default ImportPage;