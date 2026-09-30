import React, { useState } from 'react';
import toast from 'react-hot-toast';
import { AlertTriangle, FileText, ArrowLeft, CheckCircle2, Upload } from 'lucide-react';
import HrFileDropZone from './HrFileDropZone';
import { dutyAssignmentImportService } from '../../../services/dutyAssignmentImportService';

/**
 * ตารางตัวอย่างข้อมูล (10 แถวแรก)
 */
const PreviewTable = ({ preview }) => (
    <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
        <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-semibold text-gray-700">ตัวอย่างข้อมูลการอยู่ภารกิจ</h3>
            <span className="text-xs text-gray-500">
                จับคู่ได้ {preview.matched_rows} จาก {preview.total_rows} รายการ
            </span>
        </div>

        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="bg-[#FDFBF7] text-left text-gray-600">
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">แถว</th>
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">PID</th>
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">ชื่อ-นามสกุล</th>
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">ภารกิจ</th>
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">กลุ่มงาน</th>
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">งาน</th>
                        <th scope="col" className="px-3 py-2 whitespace-nowrap">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    {preview.rows.map((row, i) => (
                        <tr key={i} className="border-t border-gray-100">
                            <td className="px-3 py-2 text-gray-500">{row.row}</td>
                            <td className="px-3 py-2 text-gray-700">{row.pid || '-'}</td>
                            <td className="px-3 py-2 text-gray-700">{row.full_name || '-'}</td>
                            <td className="px-3 py-2 text-gray-700">{row.duty || '-'}</td>
                            <td className="px-3 py-2 text-gray-700">{row.group || '-'}</td>
                            <td className="px-3 py-2 text-gray-700">{row.work || '-'}</td>
                            <td className="px-3 py-2">
                                {row.status === 'ok' ? (
                                    <span className="text-green-600">พร้อมนำเข้า</span>
                                ) : (
                                    <span className="text-red-500">ตรวจสอบข้อมูล</span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>

        <div className="mt-4 text-xs text-gray-500 text-center">
            * แสดงตัวอย่างเพียง 10 แถวแรก — ข้อมูลจริงจะถูกนำเข้าทั้งหมด
        </div>
    </div>
);

/**
 * แผงยืนยันการนำเข้า
 */
const ConfirmPanel = ({ fileName, preview, importing, onImport, onClear }) => (
    <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6 sticky top-6">
        <h3 className="text-lg font-semibold text-gray-700 mb-4">ยืนยันการนำเข้า</h3>

        <div className="text-sm text-gray-600 space-y-2 mb-6">
            <div className="flex justify-between">
                <span>ไฟล์</span>
                <span className="font-medium text-gray-800 truncate max-w-[180px]">{fileName}</span>
            </div>
            <div className="flex justify-between">
                <span>จำนวนรายการ</span>
                <span className="font-medium">{preview.total_rows} รายการ</span>
            </div>
            <div className="flex justify-between">
                <span>จับคู่ได้</span>
                <span className="font-medium text-green-600">
                    {preview.preview?.matched_rows || 0} รายการ
                </span>
            </div>
            <div className="flex justify-between">
                <span>คำเตือน</span>
                <span className="font-medium text-yellow-600">{preview.warning_count} รายการ</span>
            </div>
        </div>

        <p className="text-xs text-gray-400 mb-4">
            ระบบจะอัปเดตภารกิจ/กลุ่มงาน/งาน ของบุคลากรตาม PID
            แถวที่จับคู่ไม่ได้จะถูกข้ามและแสดงในรายงาน
        </p>

        <button
            onClick={onImport}
            disabled={importing}
            className="w-full py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-medium transition-colors disabled:opacity-50 flex items-center justify-center gap-2"
        >
            <Upload size={18} />
            {importing ? 'กำลังนำเข้า...' : 'ยืนยันนำเข้าข้อมูล'}
        </button>

        <button
            onClick={onClear}
            className="w-full mt-3 py-2 text-gray-500 hover:text-gray-700 text-sm flex items-center justify-center gap-1"
        >
            <ArrowLeft size={14} />
            เลือกไฟล์ใหม่
        </button>
    </div>
);

/**
 * แผงนำเข้า "ข้อมูลการอยู่ภารกิจของบุคลากร"
 * แยกเป็น component ของตัวเอง — ไม่แชร์ state หรือ service กับการนำเข้าข้อมูลบุคลากรเดิม
 */
const DutyAssignmentImportPanel = () => {
    const [selectedFile, setSelectedFile] = useState(null);
    const [previewing, setPreviewing] = useState(false);
    const [preview, setPreview] = useState(null);
    const [importing, setImporting] = useState(false);
    const [summary, setSummary] = useState(null);
    const [rowErrors, setRowErrors] = useState([]);

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
        setRowErrors([]);
    };

    const handleClear = () => {
        setSelectedFile(null);
        setPreview(null);
        setSummary(null);
        setRowErrors([]);
    };

    const handlePreview = async () => {
        if (!selectedFile) return;

        setPreviewing(true);
        try {
            const result = await dutyAssignmentImportService.preview(selectedFile);
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
            const result = await dutyAssignmentImportService.store(selectedFile);
            setSummary(result.summary);
            setRowErrors(result.row_errors || []);
            toast.success('นำเข้าข้อมูลการอยู่ภารกิจสำเร็จ');
        } catch (error) {
            const message = error.response?.data?.message || 'เกิดข้อผิดพลาดในการนำเข้า';
            toast.error(message);
        } finally {
            setImporting(false);
        }
    };

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
                        นำเข้าข้อมูลการอยู่ภารกิจสำเร็จ
                    </h2>
                    <p className="text-center text-gray-500 mb-8">ไฟล์ {selectedFile?.name}</p>

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">ทั้งหมด</div>
                            <div className="text-2xl font-bold text-gray-700">{summary.total || 0}</div>
                        </div>
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">อัปเดต</div>
                            <div className="text-2xl font-bold text-green-600">{summary.updated || 0}</div>
                        </div>
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">ไม่เปลี่ยนแปลง</div>
                            <div className="text-2xl font-bold text-gray-500">{summary.unchanged || 0}</div>
                        </div>
                        <div className="border border-gray-100 rounded-xl p-4 text-center">
                            <div className="text-xs text-gray-500 mb-1">ข้าม/ผิดพลาด</div>
                            <div className="text-2xl font-bold text-red-600">
                                {(summary.skipped || 0) + (summary.errors || 0)}
                            </div>
                        </div>
                    </div>

                    {rowErrors.length > 0 && (
                        <div className="bg-red-50 border border-red-100 rounded-xl p-4 mb-6">
                            <h3 className="font-semibold text-red-700 mb-2">
                                แถวที่ผิดพลาด ({rowErrors.length})
                            </h3>
                            <div className="max-h-48 overflow-y-auto space-y-1">
                                {rowErrors.map((err, i) => (
                                    <div key={i} className="text-sm text-red-600">
                                        แถวที่ {err.row}: {err.error}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <button
                        onClick={handleClear}
                        className="w-full py-3 bg-[#C5A059] hover:bg-[#B8924F] text-white rounded-xl font-medium transition-colors"
                    >
                        นำเข้าไฟล์อื่นต่อ
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Left */}
            <div className="lg:col-span-2 space-y-6">
                <div className="bg-[#FDFBF7] border border-[#E6D3A3] rounded-2xl p-5 text-sm text-gray-600">
                    <p className="font-medium text-[#8B5E3C] mb-2">รูปแบบไฟล์ที่รองรับ</p>
                    <p>
                        แถวแรกเป็นหัวตาราง ต้องมีคอลัมน์{' '}
                        <span className="font-mono font-semibold">PID</span> และ{' '}
                        <span className="font-mono font-semibold">ภารกิจ</span> (หรือ DUTY)
                        และอาจมี <span className="font-mono font-semibold">PARTY</span> (กลุ่มงาน),{' '}
                        <span className="font-mono font-semibold">AGENCIES</span> (งาน)
                    </p>
                    <p className="mt-2 text-xs text-gray-500">
                        ระบบตัดรหัสนำหน้าให้อัตโนมัติ (เช่น <span className="font-mono">59_กลุ่มงาน…</span>)
                        และจับคู่ <span className="font-mono">ภารกิจด้านการพยาบาล</span> กับ{' '}
                        <span className="font-mono">ด้านการพยาบาล</span> ในฐานข้อมูลได้
                    </p>
                    <p className="mt-2 text-xs text-gray-500">
                        จับคู่บุคลากรด้วย PID แล้วอัปเดตเฉพาะภารกิจ/กลุ่มงาน/งาน — ไม่แก้ข้อมูลส่วนตัว
                    </p>
                </div>

                <HrFileDropZone
                    onFileSelect={handleFileSelect}
                    selectedFile={selectedFile}
                    onClear={handleClear}
                />

                {selectedFile && !preview && !previewing && (
                    <div className="bg-white rounded-2xl border border-[#E6D3A3] p-6">
                        <div className="flex items-center gap-3 mb-4">
                            <FileText size={20} className="text-[#8B5E3C]" />
                            <h3 className="text-lg font-semibold text-gray-700">{selectedFile.name}</h3>
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
                                        <div key={i} className="text-sm text-yellow-700">
                                            แถวที่ {w.row}: {w.message}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {preview.preview?.rows?.length > 0 ? (
                            <PreviewTable preview={preview.preview} />
                        ) : (
                            <div className="bg-yellow-50 rounded-2xl border border-yellow-200 p-6 text-center">
                                <p className="text-yellow-700 text-sm">
                                    ⚠️ ไม่พบข้อมูลในไฟล์ — กรุณาตรวจสอบรูปแบบไฟล์
                                </p>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Right */}
            <div className="space-y-6">
                {preview && (
                    <ConfirmPanel
                        fileName={selectedFile?.name}
                        preview={preview}
                        importing={importing}
                        onImport={handleImport}
                        onClear={handleClear}
                    />
                )}
            </div>
        </div>
    );
};

export default DutyAssignmentImportPanel;
