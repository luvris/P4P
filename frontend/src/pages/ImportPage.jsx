import React, { useState } from 'react';
import toast from 'react-hot-toast';
import FileDropZone from '../components/features/import/FileDropZone';
import ImportSummary from '../components/features/import/ImportSummary';
import SelectedFilePanel from '../components/features/import/SelectedFilePanel';
import PreviewTable from '../components/features/import/PreviewTable';
import { importService } from '../services/importService';
import { AlertTriangle, Link2Off } from 'lucide-react';
import useFiscalYear from '../hooks/useFiscalYear';
import { FISCAL_MONTHS, MONTH_LABELS, calendarYearOf, currentMonth } from '../utils/fiscalPeriod';

const ImportPage = () => {
    const [selectedFile, setSelectedFile] = useState(null);
    const [loading, setLoading] = useState(false);
    const [summary, setSummary] = useState(null);
    const [previewData, setPreviewData] = useState([]);
    const [warning, setWarning] = useState('');
    const [linkWarnings, setLinkWarnings] = useState([]);
    const [unlinkedSummary, setUnlinkedSummary] = useState(null);

    // งวดของไฟล์ payroll — ใช้คำนวณเงินสำรองรายปี (เริ่มที่เดือนปัจจุบัน)
    const { fiscalYear } = useFiscalYear();
    const [periodMonth, setPeriodMonth] = useState(currentMonth);

    const handleFileSelect = (file) => {
        const validExtensions = ['xlsx', 'xls', 'txt'];
        const extension = file.name.split('.').pop().toLowerCase();

        if (!validExtensions.includes(extension)) {
            toast.error('รองรับเฉพาะไฟล์ .xlsx, .xls, .txt');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            toast.error('ขนาดไฟล์ต้องไม่เกิน 10 MB');
            return;
        }

        setSelectedFile(file);
        setSummary(null);
        setPreviewData([]);
        setWarning('');
        setLinkWarnings([]);
    };

    const handleClear = () => {
        setSelectedFile(null);
        setSummary(null);
        setPreviewData([]);
        setWarning('');
        setLinkWarnings([]);
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
            setPreviewData(result.preview || []);
            setWarning(result.warning || '');
            setLinkWarnings(result.link_warnings || []);
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
            {/* Header ของหน้า (optional — Header หลักมีอยู่แล้ว) */}
            <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">
                    นำเข้าข้อมูลการเงิน
                </h2>
                <p className="text-gray-500 text-sm">
                    รองรับไฟล์ Excel (.xlsx) และ Text (.txt)
                </p>
            </div>

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
                                ปีงบประมาณ {fiscalYear} — ใช้ในการคำนวณเงินสำรองรายปี
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
                                จึงจะถูกนับ — กรุณาส่งไฟล์นี้ไปที่เมนู “นำเข้าข้อมูลบุคลากร” อีกครั้ง หรือแก้เลขบัตรในไฟล์ให้ถูกต้อง
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

                    {linkWarnings.length > 0 && (
                        <div className="rounded-2xl border border-red-200 bg-red-50 p-4">
                            <div className="flex items-center gap-2 text-sm font-medium text-red-800">
                                <Link2Off className="h-4 w-4 shrink-0" />
                                <span>
                                    พบ {linkWarnings.length.toLocaleString()} แถวที่เลขบัตรประชาชนไม่ตรงกับทะเบียนบุคลากร
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-red-700">
                                คนเหล่านี้จะไม่ถูกนับในเงินสำรองและไม่รู้สังกัด กรุณาตรวจสอบก่อนสรุปยอด
                            </p>
                            <ul className="mt-3 max-h-64 space-y-2 overflow-y-auto">
                                {linkWarnings.map((item, i) => (
                                    <li
                                        key={i}
                                        className="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs text-gray-700"
                                    >
                                        <span className="mr-1 font-medium text-red-700">
                                            แถว {item.row ?? '-'}
                                        </span>
                                        {item.error}
                                    </li>
                                ))}
                            </ul>
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