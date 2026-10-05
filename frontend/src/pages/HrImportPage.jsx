import React, { useState } from 'react';
import toast from 'react-hot-toast';
import { AlertTriangle, FileText, ArrowLeft, CheckCircle2, Upload } from 'lucide-react';
import HrFileDropZone from '../components/features/import/HrFileDropZone';
import DutyAssignmentImportPanel from '../components/features/import/DutyAssignmentImportPanel';
import { hrImportService } from '../services/hrImportService';

/**
 * เดือนแบบย่อ → ชื่อเดือนภาษาไทย (คอลัมน์ "เดือน" ในไฟล์รูปแบบใหม่เป็นตัวเลข 1-12)
 */
const THAI_MONTHS = [
    '',
    'ม.ค.',
    'ก.พ.',
    'มี.ค.',
    'เม.ย.',
    'พ.ค.',
    'มิ.ย.',
    'ก.ค.',
    'ส.ค.',
    'ก.ย.',
    'ต.ค.',
    'พ.ย.',
    'ธ.ค.',
];

const monthLabel = (month) => THAI_MONTHS[Number(month)] || '-';

/**
 * ป้ายชื่อชุดข้อมูลที่ผูกกับเลขแถว
 */
const TYPE_LABELS = {
    employee: 'ข้อมูลบุคลากร',
    employment: 'ประวัติการจ้างงาน',
};

/**
 * แปลงรายการปัญหาที่ backend ส่งมาให้เป็นรูปแบบเดียว
 * รองรับทั้ง { row, error } / { row, message, type } และ string แบบเดิม
 */
const formatIssue = (issue) => {
    if (typeof issue === 'string') {
        return { row: null, type: null, message: issue };
    }
    if (!issue || typeof issue !== 'object') {
        return { row: null, type: null, message: '' };
    }
    return {
        row: issue.row ?? null,
        type: issue.type ?? null,
        message: issue.error ?? issue.message ?? '',
    };
};

/**
 * หนึ่งบรรทัดของรายการแจ้งเตือน/ข้อผิดพลาด
 */
const IssueLine = ({ issue, className }) => {
    const { row, type, message } = formatIssue(issue);
    const typeLabel = TYPE_LABELS[type] || type;

    return (
        <div className={className}>
            {row !== null && (
                <span className="font-medium">
                    แถวที่ {row}
                    {typeLabel ? ` (${typeLabel})` : ''}:{' '}
                </span>
            )}
            {message || 'ไม่ระบุรายละเอียด'}
        </div>
    );
};

/**
 * ส่วนนำเข้า "ข้อมูลบุคลากร" (ของเดิม — flow และ service ไม่เปลี่ยน)
 */
const HrEmployeeImportSection = () => {
    const [selectedFile, setSelectedFile] = useState(null);
    const [previewing, setPreviewing] = useState(false);
    const [preview, setPreview] = useState(null); // { preview: [], warnings: [], warning_count, total_rows }
    const [importing, setImporting] = useState(false);
    const [summary, setSummary] = useState(null);
    const [warnings, setWarnings] = useState([]);

    const handleFileSelect = (file) => {
        const extension = file.name.split('.').pop().toLowerCase();

        if (!['xlsx', 'xls'].includes(extension)) {
            toast.error('รองรับเฉพาะไฟล์ .xlsx หรือ .xls');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            toast.error('ขนาดไฟล์ต้องไม่เกิน 10 MB');
            return;
        }

        setSelectedFile(file);
        setPreview(null);
        setSummary(null);
        setWarnings([]);
    };

    const handleClear = () => {
        setSelectedFile(null);
        setPreview(null);
        setSummary(null);
        setWarnings([]);
    };

    const handlePreview = async () => {
        if (!selectedFile) return;

        setPreviewing(true);
        try {
            const result = await hrImportService.preview(selectedFile);
            setPreview(result);
            toast.success('อ่านข้อมูลไฟล์สำเร็จ');
        } catch (error) {
            const message = error.response?.data?.message || 'เกิดข้อผิดพลาดในการอ่านไฟล์';
            toast.error(message);
        } finally {
            setPreviewing(false);
        }
    };

    const handleImport = async () => {
        if (!selectedFile) return;

        setImporting(true);
        try {
            const result = await hrImportService.store(selectedFile);
            setSummary(result.summary);
            setWarnings(result.warnings || []);
            toast.success('นำเข้าข้อมูลบุคลากรสำเร็จ');
        } catch (error) {
            const message = error.response?.data?.message || 'เกิดข้อผิดพลาดในการนำเข้า';
            toast.error(message);
        } finally {
            setImporting(false);
        }
    };

    const handleReset = () => {
        handleClear();
    };

    const formatNumber = (value) => {
        if (value === null || value === undefined || value === '') return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return value;
        return new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num);
    };

    // หลัง import เสร็จ แสดงผลสรุป
    if (summary) {
        return (
            <div className="max-w-4xl mx-auto">
                <div className="bg-white rounded-2xl border border-[#E6D3A3] p-8">
                    <div className="flex items-center justify-center mb-6">
                        <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                            <CheckCircle2 size={32} className="text-green-600" />
                        </div>
                    </div>

                    <h2 className="text-2xl font-bold text-center text-gray-800 mb-2">
                        นำเข้าข้อมูลบุคลากรสำเร็จ
                    </h2>
                    <p className="text-center text-gray-500 mb-8">
                        ไฟล์ {selectedFile?.name}
                    </p>

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">ทั้งหมด</div>
                            <div className="text-2xl font-bold text-gray-700">{summary.total || 0}</div>
                        </div>
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">เพิ่มใหม่</div>
                            <div className="text-2xl font-bold text-blue-600">{summary.inserted || 0}</div>
                        </div>
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">อัปเดต</div>
                            <div className="text-2xl font-bold text-green-600">{summary.updated || 0}</div>
                        </div>
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">ข้าม/ผิดพลาด</div>
                            <div className="text-2xl font-bold text-red-600">
                                {(summary.skipped || 0) + (summary.errors || 0)}
                            </div>
                        </div>
                    </div>

                    {warnings.length > 0 && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
                            <h3 className="font-semibold text-yellow-700 mb-2">
                                คำเตือน ({warnings.length})
                            </h3>
                            <div className="max-h-48 overflow-y-auto space-y-1">
                                {warnings.map((w, i) => (
                                    <IssueLine key={i} issue={w} className="text-sm text-yellow-700" />
                                ))}
                            </div>
                        </div>
                    )}

                    <button
                        onClick={handleReset}
                        className="w-full py-3 bg-[#C5A059] hover:bg-[#B8924F] text-white rounded-xl font-medium transition-colors"
                    >
                        นำเข้าไฟล์อื่นต่อ
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Left */}
                <div className="lg:col-span-2 space-y-6">
                    <HrFileDropZone
                        onFileSelect={handleFileSelect}
                        selectedFile={selectedFile}
                        onClear={handleClear}
                    />

                    {selectedFile && !preview && !previewing && (
                        <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
                            <div className="flex items-center gap-3 mb-4">
                                <FileText size={20} className="text-[#8B5E3C]" />
                                <h3 className="text-lg font-semibold text-gray-700">
                                    {selectedFile.name}
                                </h3>
                            </div>
                            <button
                                onClick={handlePreview}
                                disabled={previewing}
                                className="w-full py-3 bg-[#C5A059] hover:bg-[#B8924F] text-white rounded-xl font-medium transition-colors disabled:opacity-50"
                            >
                                {previewing ? 'กำลังอ่านไฟล์...' : 'ดูตัวอย่างข้อมูล'}
                            </button>
                        </div>
                    )}

                    {preview && (
                        <div className="space-y-6">
                            {/* Warnings */}
                            {preview.warning_count > 0 && (
                                <div className="bg-yellow-50 border border-yellow-200 rounded-2xl p-4">
                                    <div className="flex items-center gap-2 mb-2">
                                        <AlertTriangle size={18} className="text-yellow-600" />
                                        <h4 className="font-semibold text-yellow-700">
                                            คำเตือน ({preview.warning_count} รายการ)
                                        </h4>
                                    </div>
                                    <div className="max-h-40 overflow-y-auto space-y-1">
                                        {preview.warnings.map((w, i) => (
                                            <IssueLine key={i} issue={w} className="text-sm text-yellow-700" />
                                        ))}
                                    </div>
                                </div>
                            )}

{/* ตารางตัวอย่าง — คอลัมน์ตามไฟล์เงินเดือนรูปแบบใหม่ */}
                            {preview.preview?.rows?.length > 0 && (
                                <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
                                    <div className="flex items-center justify-between mb-4">
                                        <h3 className="text-lg font-semibold text-gray-700">
                                            📋 ข้อมูลบุคลากร (งวดล่าสุด)
                                        </h3>
                                        <span className="text-xs text-gray-500">
                                            แสดง {preview.preview.rows.length} แถวแรก (ทั้งหมด{' '}
                                            {preview.preview.total_rows} คน)
                                        </span>
                                    </div>

                                    <div className="flex flex-wrap gap-4 mb-4 text-sm">
                                        <span className="text-gray-600">
                                            เพิ่มใหม่{' '}
                                            <span className="font-semibold text-blue-600">
                                                {preview.preview.new_count}
                                            </span>
                                        </span>
                                        <span className="text-gray-600">
                                            อัปเดต{' '}
                                            <span className="font-semibold text-green-600">
                                                {preview.preview.update_count}
                                            </span>
                                        </span>
                                    </div>

                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="bg-[#C5A059] text-white">
                                                    <th className="px-3 py-2 text-left font-medium">#</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">ลำดับที่</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">งวดเดือน</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">คำนำหน้า</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">ชื่อ</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">นามสกุล</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">ประเภท</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">ตำแหน่ง</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">ตำแหน่งเลขที่</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">เลขบัตรประชาชน</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">เลขที่บัญชี</th>
                                                    <th className="px-3 py-2 text-right font-medium whitespace-nowrap">เงินเดือน</th>
                                                    <th className="px-3 py-2 text-left font-medium whitespace-nowrap">การดำเนินการ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {preview.preview.rows.map((row, rowIndex) => (
                                                    <tr
                                                        key={row.citizen_id || rowIndex}
                                                        className={`border-b border-gray-100 ${
                                                            rowIndex % 2 === 0 ? 'bg-white' : 'bg-[#FDFBF7]'
                                                        } hover:bg-[#F5EEDC]/30 transition-colors`}
                                                    >
                                                        <td className="px-3 py-2 text-gray-500">{rowIndex + 1}</td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.seq_number || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {monthLabel(row.period_month)}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.prefix || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.first_name || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.last_name || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.employee_type || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.position_name || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.position_number || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.citizen_id || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-gray-700 whitespace-nowrap">
                                                            {row.bank_account || '-'}
                                                        </td>
                                                        <td className="px-3 py-2 text-right text-gray-700 whitespace-nowrap">
                                                            {formatNumber(row.latest_salary)}
                                                        </td>
                                                        <td className="px-3 py-2 whitespace-nowrap">
                                                            <span
                                                                className={`px-2 py-0.5 rounded-full text-xs ${
                                                                    row.action === 'update'
                                                                        ? 'bg-green-100 text-green-700'
                                                                        : 'bg-blue-100 text-blue-700'
                                                                }`}
                                                            >
                                                                {row.action === 'update' ? 'อัปเดต' : 'เพิ่มใหม่'}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="mt-4 text-xs text-gray-500 text-center">
                                        * แสดงตัวอย่างเพียง 10 แถวแรก — ไฟล์รวมหลายงวด
                                        ระบบจะใช้ข้อมูลงวดล่าสุดต่อคนทั้งหมด ({preview.preview.total_rows} คน)
                                    </div>
                                </div>
                            )}

                            {/* กรณีไม่มีข้อมูลเลย */}
                            {(preview.preview?.rows?.length ?? 0) === 0 && (
                                <div className="bg-yellow-50 rounded-2xl border border-yellow-200 p-6 text-center">
                                    <p className="text-yellow-700 text-sm">
                                        ⚠️ ไม่พบข้อมูลบุคลากรในไฟล์ (ต้องมีเลขบัตรประชาชน)
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Right */}
                <div className="space-y-6">
                    {preview && !summary && (
                        <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6 sticky top-6">
                            <h3 className="text-lg font-semibold text-gray-700 mb-4">
                                ยืนยันการนำเข้า
                            </h3>

                            <div className="text-sm text-gray-600 space-y-2 mb-6">
                                <div className="flex justify-between">
                                    <span>ไฟล์</span>
                                    <span className="font-medium text-gray-800 truncate max-w-[180px]">
                                        {selectedFile?.name}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span>จำนวนรายการ</span>
                                    <span className="font-medium">{preview.total_rows} รายการ</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>คำเตือน</span>
                                    <span className="font-medium text-yellow-600">
                                        {preview.warning_count} รายการ
                                    </span>
                                </div>
                            </div>

                            <p className="text-xs text-gray-400 mb-4">
                                ระบบจะอัปเดตข้อมูลบุคลากรตามเลขบัตรประชาชน
                                (ถ้ามีอยู่แล้วจะอัปเดต ถ้าไม่มีจะเพิ่มใหม่)
                            </p>

                            <button
                                onClick={handleImport}
                                disabled={importing}
                                className="w-full py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
                            >
                                <Upload size={18} />
                                {importing ? 'กำลังนำเข้า...' : 'ยืนยันนำเข้าข้อมูล'}
                            </button>

                            <button
                                onClick={handleClear}
                                className="w-full mt-3 py-2 text-gray-500 hover:text-gray-700 text-sm flex items-center justify-center gap-1"
                            >
                                <ArrowLeft size={14} />
                                เลือกไฟล์ใหม่
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

/**
 * ประเภทการนำเข้าข้อมูล
 * - employee: ข้อมูลบุคลากร (ไฟล์เงินเดือนรูปแบบใหม่ 39 คอลัมน์)
 * - duty_assignment: ข้อมูลการอยู่ภารกิจ (PID + DUTY)
 */
const IMPORT_TYPES = [
    {
        value: 'employee',
        label: 'นำเข้าข้อมูลบุคลากร',
        description:
            'รองรับไฟล์เงินเดือนรูปแบบใหม่ (.xlsx) — ระบบจะใช้ข้อมูลงวดล่าสุดของแต่ละคน'
            + ' แล้วเพิ่ม/อัปเดตทะเบียนบุคลากรตามเลขบัตรประชาชน',
    },
    {
        value: 'duty_assignment',
        label: 'นำเข้าข้อมูลการอยู่ภารกิจของบุคลากร',
        description: 'รองรับไฟล์ Excel (.xlsx) — เชื่อมบุคลากรผ่าน PID แล้วอัปเดตภารกิจ/กลุ่มงาน/งาน',
    },
];

const HrImportPage = () => {
    const [importType, setImportType] = useState('employee');

    const active = IMPORT_TYPES.find((t) => t.value === importType) ?? IMPORT_TYPES[0];

    return (
        <div className="max-w-7xl mx-auto">
            <div className="mb-6">
                <h2 className="text-2xl font-bold text-[#8B5E3C] mb-1">{active.label}</h2>
                <p className="text-gray-500 text-sm">{active.description}</p>
            </div>

            {/* เลือกประเภทการนำเข้าข้อมูล */}
            <div className="bg-white rounded-2xl border border-[#E6D3A3] p-5 mb-6">
                <label
                    htmlFor="import-type"
                    className="block text-sm font-medium text-gray-700 mb-2"
                >
                    ประเภทการนำเข้าข้อมูล
                </label>
                <select
                    id="import-type"
                    value={importType}
                    onChange={(e) => setImportType(e.target.value)}
                    className="w-full md:w-96 px-4 py-2.5 rounded-xl border border-gray-300 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent"
                >
                    {IMPORT_TYPES.map((type) => (
                        <option key={type.value} value={type.value}>
                            {type.label}
                        </option>
                    ))}
                </select>
            </div>

            {/* แต่ละประเภทใช้ component + service ของตัวเอง (state แยกกันสมบูรณ์) */}
            {importType === 'employee' && <HrEmployeeImportSection />}

            {importType === 'duty_assignment' && <DutyAssignmentImportPanel />}
        </div>
    );
};

export default HrImportPage;