import React, { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import FileDropZone from '../components/features/import/FileDropZone';
import ImportSummary from '../components/features/import/ImportSummary';
import BatchImportSummary from '../components/features/import/BatchImportSummary';
import SelectedFilePanel from '../components/features/import/SelectedFilePanel';
import PreviewTable from '../components/features/import/PreviewTable';
import TemplateDownloadButton from '../components/features/import/TemplateDownloadButton';
import ExtraColumnsPanel from '../components/features/import/ExtraColumnsPanel';
import PeriodScopePicker from '../components/features/import/PeriodScopePicker';
import { importService } from '../services/importService';
import { AlertTriangle, Users, SlidersHorizontal, FileSpreadsheet, FileDown, RotateCcw, Unlink } from 'lucide-react';
import useAuth from '../hooks/useAuth';
import useFiscalYear from '../hooks/useFiscalYear';
import { currentMonth } from '../utils/fiscalPeriod';

/** ขอบเขตของไฟล์ export — งวดเดียว หรือรวมทั้งปีงบประมาณ (ต.ค. – ก.ย.) */
const EXPORT_SCOPES = [
    { value: 'period', label: 'รายงวด' },
    { value: 'year', label: 'ทั้งปีงบประมาณ' },
];

/** ขนาดไฟล์สูงสุดต่อไฟล์ (ตรงกับที่ backend ตรวจ) */
const MAX_FILE_SIZE = 10 * 1024 * 1024;

/** จำนวนไฟล์สูงสุดต่อครั้ง — กันเผลอลากทั้งโฟลเดอร์เข้ามา */
const MAX_FILES = 12;

/** ข้อความ error จาก Laravel — ภาษาไทยอยู่ใน errors ส่วน message กลางเป็นอังกฤษ */
const errorMessage = (error) => {
    const data = error.response?.data;
    const fieldError = Object.values(data?.errors || {}).flat()[0];

    return fieldError || data?.message || 'เกิดข้อผิดพลาด';
};

/**
 * อัปโหลดไฟล์เงินเดือน — ได้ทั้งทะเบียนบุคลากรและแถวเงินเดือนจากไฟล์เดียว
 *
 * คนละชั้นหน้าเดิม (HR / การเงิน) ใช้หน้านี้ร่วมกัน เพราะไฟล์และผลลัพธ์เดียวกัน
 * อัปโหลดได้ทั้งไฟล์เดียว, หลายไฟล์ (ทีละงวด) และไฟล์ที่ครอบคลุมทั้งปีงบประมาณ
 */
const ImportPage = () => {
    const { hasRole } = useAuth();
    // การเพิ่มคอลัมน์กระทบไฟล์ต้นแบบของทุกคน จึงจำกัดไว้ที่ admin
    const canManageColumns = hasRole('admin');

    // ไฟล์ที่เลือก (เลือกหลายไฟล์ได้ = แต่ละไฟล์คือหนึ่งงวด)
    const [selectedFiles, setSelectedFiles] = useState([]);
    const [loading, setLoading] = useState(false);
    // ผลลัพธ์รายไฟล์ — เก็บทั้งหมดเพื่อสรุปตอนอัปโหลดหลายไฟล์
    const [batchResults, setBatchResults] = useState([]);
    const [showColumns, setShowColumns] = useState(false);

    // งวดที่ export — ดึงจากระบบเพื่อไม่ให้เลือกงวดที่ยังไม่มีข้อมูลจริง
    const [periods, setPeriods] = useState([]);
    const [exportPeriod, setExportPeriod] = useState('');

    // ปีงบประมาณที่จะ export — ค่าเริ่มต้นคือปีงบล่าสุดที่มีข้อมูล
    const [fiscalYears, setFiscalYears] = useState([]);
    const [exportFiscalYear, setExportFiscalYear] = useState('');
    const [exportScope, setExportScope] = useState('period');

    useEffect(() => {
        let active = true;

        importService.getPeriods()
            .then((result) => {
                if (!active) return;

                const list = result.data || [];
                const years = result.years || [];

                setPeriods(list);
                setFiscalYears(years);
                // ค่าเริ่มต้นเป็นงวดใหม่สุดที่มีข้อมูล
                setExportPeriod(list[0] ? `${list[0].period_year}-${list[0].period_month}` : '');
                setExportFiscalYear(years[0] ? String(years[0].fiscal_year) : '');
            })
            .catch(() => {
                // ไม่มีงวดให้เลือก = ยัง export ไม่ได้ ปุ่มจะไม่แสดง
                if (!active) return;

                setPeriods([]);
                setFiscalYears([]);
            });

        return () => { active = false; };
    }, []);

    // ปีงบประมาณที่เลือกอยู่บน Header ใช้เป็นค่าเริ่มต้นของการนำเข้า
    // ผู้ใช้เปลี่ยนเองในหน้านี้ได้ แล้วจะยึดค่าที่เลือกนั้นแทน
    const { fiscalYear } = useFiscalYear();
    const [fiscalYearOverride, setFiscalYearOverride] = useState(null);
    const importFiscalYear = fiscalYearOverride ?? fiscalYear;
    const [scope, setScope] = useState('month');
    const [months, setMonths] = useState([currentMonth()]);

    // ปีที่เสนอให้เลือกตอนนำเข้า — รอบปีงบที่เลือกอยู่ บวก/ลบ ปีละ 2
    const importFiscalYearOptions = [1, 0, -1, -2]
        .map((offset) => importFiscalYear + offset)
        .filter((year) => year >= 2500 && year <= 2700)
        .sort((a, b) => b - a);

    const reset = () => setBatchResults([]);

    const handleFileSelect = (picked) => {
        const incoming = Array.isArray(picked) ? picked : [picked];
        const accepted = [];
        const rejected = [];

        incoming.forEach((file) => {
            const extension = file.name.split('.').pop().toLowerCase();

            if (!['xlsx', 'xls'].includes(extension)) {
                rejected.push(`${file.name} (รองรับเฉพาะ .xlsx / .xls)`);
            } else if (file.size > MAX_FILE_SIZE) {
                rejected.push(`${file.name} (ใหญ่เกิน 10 MB)`);
            } else {
                accepted.push(file);
            }
        });

        if (rejected.length > 0) {
            toast.error(`ข้าม ${rejected.length} ไฟล์: ${rejected[0]}`);
        }

        if (accepted.length === 0) return;

        // ต่อไฟล์ใหม่ต่อท้าย ไม่ใช่แทนที่ และกันไฟล์เดิมซ้ำด้วยชื่อ+ขนาด
        const merged = [...selectedFiles];
        accepted.forEach((file) => {
            const exists = merged.some((item) => item.name === file.name && item.size === file.size);
            if (!exists) merged.push(file);
        });

        const next = merged.slice(0, MAX_FILES);
        if (merged.length > MAX_FILES) {
            toast(`เลือกได้สูงสุด ${MAX_FILES} ไฟล์ต่อครั้ง — นำเข้าเฉพาะ ${MAX_FILES} ไฟล์แรก`, { icon: '⚠️' });
        }

        setSelectedFiles(next);

        // เลือกหลายไฟล์ = ตั้งใจครอบคลุมหลายงวดอยู่แล้ว เลื่อนไปโหมดหลายงวดให้เลย
        // (โหมดงวดเดียวจะเติมงวดเดียวกันให้ทุกไฟล์ ซึ่งพลาดง่ายกว่า)
        if (next.length > 1) setScope('months');

        reset();
    };

    const handleClear = () => {
        setSelectedFiles([]);
        reset();
    };

    const handleImport = async () => {
        if (selectedFiles.length === 0) return;

        if (scope === 'months' && months.length === 0) {
            toast.error('เลือกงวดที่ไฟล์ครอบคลุมอย่างน้อย 1 งวด');
            return;
        }

        setLoading(true);
        const results = [];

        // ทีละไฟล์ — แต่ละไฟล์คือคนละงวด การรันพร้อมกันจะชนกันตอนเขียนลงฐาน
        for (const file of selectedFiles) {
            try {
                const data = await importService.uploadFile(file, {
                    scope,
                    fiscal_year: importFiscalYear,
                    // งวดเดียว = ใช้เป็นงวดสำรองของแถวที่ไม่ระบุเดือน
                    // ทั้งปีงบประมาณ = ไม่ส่งเดือนเลย ให้ระบบตรวจว่าอยู่ใน ต.ค.–ก.ย.
                    period_month: scope === 'month' ? months[0] : undefined,
                    period_months: scope === 'months' ? months : undefined,
                });

                results.push({ name: file.name, ok: true, data });
            } catch (error) {
                results.push({ name: file.name, ok: false, message: errorMessage(error) });
            }
        }

        setBatchResults(results);
        setLoading(false);

        const failed = results.filter((result) => !result.ok).length;
        const succeeded = results.length - failed;

        if (failed === 0) {
            toast.success(`นำเข้าสำเร็จ ${succeeded} ไฟล์`);
        } else if (succeeded === 0) {
            toast.error(`นำเข้าไม่สำเร็จทั้งหมด ${failed} ไฟล์`);
        } else {
            toast(`นำเข้าสำเร็จ ${succeeded} จาก ${results.length} ไฟล์ — เหลือที่ต้องแก้ ${failed} ไฟล์`, { icon: '⚠️' });
        }
    };

    // ไฟล์เดียวใช้การ์ดสรุปเดิม (มีตัวเลขครบ) หลายไฟล์ใช้ผลรายไฟล์แทน
    const singleResult = batchResults.length === 1 ? batchResults[0] : null;
    const summary = singleResult?.ok ? singleResult.data.import : null;
    const employeeResult = singleResult?.ok ? singleResult.data.employee : null;
    const unlinkedSummary = singleResult?.ok ? singleResult.data.unlinked_summary : null;
    const warning = singleResult?.ok ? singleResult.data.warning : '';
    // ตัวอย่างข้อมูลของไฟล์ล่าสุดที่นำเข้าสำเร็จ
    const previewData = [...batchResults].reverse().find((result) => result.ok)?.data?.preview || [];

    // มีงวดให้เลือกไหม — ถ้าไม่มี export ไม่ได้ การ์ดจะแสดงสถานะแทนปุ่มที่กดได้
    const selectedPeriod = periods.find(
        (p) => `${p.period_year}-${p.period_month}` === exportPeriod,
    );
    const hasPeriods = periods.length > 0 && Boolean(selectedPeriod);
    // ปีงบประมาณมาจากข้อมูลงวดเดียวกัน ถ้าไม่มีรายการปีงบให้เลือกก็ยังรายงวดอย่างเดียว
    const canExportByYear = fiscalYears.length > 0 && Boolean(exportFiscalYear);
    const exportScopeValue = exportScope === 'year' && canExportByYear ? 'year' : 'period';

    // ยังไม่มีงวดตอนเปิดหน้า ปุ่มจะยังไม่ถูกวาด จึงยังไม่ต้องมี endpoint
    const exportEndpoint = exportScopeValue === 'year'
        ? importService.exportFiscalYearUrl(Number(exportFiscalYear))
        : (selectedPeriod ? importService.exportUrl(selectedPeriod) : null);

    return (
        <div className="max-w-7xl mx-auto">
            <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">
                    นำเข้าข้อมูล
                </h2>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    {/* การ์ดดาวน์โหลดแบบฟอร์ม — ใช้เมื่อยังไม่มีไฟล์ ต้องเริ่มจากศูนย์ */}
                    <section className="flex flex-col rounded-2xl border border-[#E6D3A3] bg-white p-4">
                        <div className="flex items-start gap-2.5">
                            <FileSpreadsheet className="mt-0.5 h-5 w-5 shrink-0 text-[#C5A059]" />
                            <div className="min-w-0">
                                <h3 className="text-sm font-semibold text-gray-800">
                                    แบบฟอร์มกรอกข้อมูล
                                </h3>
                                <p className="mt-0.5 text-xs leading-relaxed text-gray-500">
                                    ไฟล์เปล่า 39 คอลัมน์ — ดาวน์โหลดไปกรอก
                                    แล้วอัปโหลดกลับเข้ามาได้เลย
                                </p>
                            </div>
                        </div>

                        {/* mt-auto ดันปุ่มลงขอบล่าง ให้การ์ดทั้งสองสูงเท่ากันและปุ่มอยู่ระดับเดียวกัน */}
                        <div className="mt-auto flex flex-col gap-2 pt-4">
                            <TemplateDownloadButton
                                endpoint="/imports/template"
                                label="ดาวน์โหลดแบบฟอร์มกรอกข้อมูล"
                                className="[&>button]:w-full [&>button]:justify-center"
                            />

                            {canManageColumns && (
                                <button
                                    type="button"
                                    onClick={() => setShowColumns((v) => !v)}
                                    className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] transition-colors hover:bg-[#F5EEDC]"
                                >
                                    <SlidersHorizontal size={15} />
                                    {showColumns ? 'ซ่อนคอลัมน์เพิ่มเติม' : 'จัดการคอลัมน์เพิ่มเติม'}
                                </button>
                            )}
                        </div>
                    </section>

                    {/* การ์ด export — ดึงข้อมูลที่มีอยู่แล้วกลับออกมาเป็นไฟล์เดียวกับแบบฟอร์ม */}
                    <section className="flex flex-col rounded-2xl border border-[#E6D3A3] bg-white p-4">
                        <div className="flex items-start gap-2.5">
                            <FileDown className="mt-0.5 h-5 w-5 shrink-0 text-[#C5A059]" />
                            <div className="min-w-0">
                                <h3 className="text-sm font-semibold text-gray-800">
                                    Export ข้อมูล
                                </h3>
                                <p className="mt-0.5 text-xs leading-relaxed text-gray-500">
                                    {hasPeriods
                                        ? 'หัวตารางเดียวกับแบบฟอร์ม แต่มีข้อมูลของงวดที่เลือกอยู่แล้ว — แก้แล้วอัปโหลดกลับได้'
                                        : 'ยังไม่มีงวดที่มีข้อมูล — อัปโหลดไฟล์เงินเดือนงวดแรกก่อนจึงจะ export ได้'}
                                </p>
                            </div>
                        </div>

                        {hasPeriods ? (
                            <div className="mt-auto flex flex-col gap-2 pt-4">
                                {/* ขอบเขต: งวดเดียว หรือทั้งปีงบประมาณ */}
                                <div className="grid grid-cols-2 gap-1 rounded-xl bg-[#F5EEDC]/60 p-1">
                                    {EXPORT_SCOPES
                                        .filter((option) => option.value !== 'year' || canExportByYear)
                                        .map((option) => (
                                            <button
                                                key={option.value}
                                                type="button"
                                                aria-pressed={exportScopeValue === option.value}
                                                onClick={() => setExportScope(option.value)}
                                                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                                                    exportScopeValue === option.value
                                                        ? 'bg-white text-[#8B5E3C] shadow-sm'
                                                        : 'text-gray-500 hover:text-gray-700'
                                                }`}
                                            >
                                                {option.label}
                                            </button>
                                        ))}
                                </div>

                                <div>
                                    <label
                                        htmlFor="export-period"
                                        className="mb-1 block text-xs font-medium text-gray-600"
                                    >
                                        {exportScopeValue === 'year' ? 'ปีงบประมาณที่จะ export' : 'งวดที่จะ export'}
                                    </label>
                                    <select
                                        id="export-period"
                                        value={exportScopeValue === 'year' ? exportFiscalYear : exportPeriod}
                                        onChange={(e) => (exportScopeValue === 'year'
                                            ? setExportFiscalYear(e.target.value)
                                            : setExportPeriod(e.target.value))}
                                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                                    >
                                        {exportScopeValue === 'year'
                                            ? fiscalYears.map((year) => (
                                                <option key={year.fiscal_year} value={String(year.fiscal_year)}>
                                                    {year.label}
                                                </option>
                                            ))
                                            : periods.map((period) => (
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
                                    endpoint={exportEndpoint}
                                    label="Export ข้อมูล"
                                    className="[&>button]:w-full [&>button]:justify-center"
                                />
                            </div>
                        ) : (
                            <div className="mt-auto pt-4">
                                <button
                                    type="button"
                                    disabled
                                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-400"
                                >
                                    ยังไม่มีข้อมูลให้ export
                                </button>
                            </div>
                        )}
                    </section>
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
                        selectedFiles={selectedFiles}
                        onClear={handleClear}
                        multiple
                    />

                    {previewData.length > 0 && (
                        <PreviewTable data={previewData} />
                    )}
                </div>

                {/* Right: ขอบเขตงวด + ไฟล์ที่เลือก + ผลลัพธ์ */}
                <div className="space-y-6">
                    {selectedFiles.length > 0 && batchResults.length === 0 && (
                        <PeriodScopePicker
                            scope={scope}
                            onScopeChange={setScope}
                            months={months}
                            onMonthsChange={setMonths}
                            fiscalYear={importFiscalYear}
                            onFiscalYearChange={setFiscalYearOverride}
                            fiscalYearOptions={importFiscalYearOptions}
                        />
                    )}

                    {selectedFiles.length > 0 && batchResults.length === 0 && (
                        <SelectedFilePanel
                            files={selectedFiles}
                            onImport={handleImport}
                            onCancel={handleClear}
                            loading={loading}
                        />
                    )}

                    {/* เริ่มนำเข้าไฟล์ใหม่ — ค่าขอบเขตงวดจะถูกนับใหม่ */}
                    {batchResults.length > 0 && (
                        <button
                            type="button"
                            onClick={handleClear}
                            className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] transition-colors hover:bg-[#F5EEDC]"
                        >
                            <RotateCcw size={15} />
                            เลือกไฟล์ใหม่
                        </button>
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
                                <Unlink className="h-4 w-4 shrink-0" />
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

                    {batchResults.length > 1 && (
                        <BatchImportSummary results={batchResults} />
                    )}
                </div>
            </div>
        </div>
    );
};

export default ImportPage;