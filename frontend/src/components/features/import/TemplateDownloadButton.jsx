import React from 'react';
import toast from 'react-hot-toast';
import { Download, FileSpreadsheet } from 'lucide-react';
import api from '../../../services/api';

/**
 * ปุ่มดาวน์โหลดแบบฟอร์มกรอกข้อมูล
 *
 * ไฟล์ที่ได้จากปุ่มนี้ผ่านการตรวจด้วย parser ตัวจริงของระบบแล้ว
 * ผู้ใช้ดาวน์โหลดไปกรอกแล้วอัปกลับเข้ามาได้เลยโดยไม่เกิด error
 */
const TemplateDownloadButton = ({
    endpoint,
    label = 'ดาวน์โหลดแบบฟอร์ม',
    hint,
    className = '',
}) => {
    const handleDownload = async () => {
        try {
            const response = await api.get(endpoint, {
                responseType: 'blob',
                timeout: 30000,
            });

            // ชื่อไฟล์จริงมาจาก Content-Disposition (รองรับชื่อภาษาไทย)
            // ตัวอักษร UTF อาจถูกแปลงเป็นตัวพิมพ์เล็กโดยเบราว์เซอร์/เซิร์ฟเวอร์
            const disposition = response.headers['content-disposition'] || '';
            const match = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
            const fileName = match
                ? decodeURIComponent(match[1])
                : 'import-template.xlsx';

            const url = URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

            toast.success(`ดาวน์โหลด ${fileName} แล้ว`);
        } catch (error) {
            console.error(error);
            toast.error('ดาวน์โหลดแบบฟอร์มไม่สำเร็จ');
        }
    };

    return (
        <div className={className}>
            <button
                type="button"
                onClick={handleDownload}
                className="inline-flex items-center gap-2 rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] transition-colors hover:bg-[#FBF7EE]"
            >
                <Download className="w-4 h-4" />
                {label}
            </button>
            {hint && (
                <p className="mt-1 text-xs text-gray-500 flex items-start gap-1">
                    <FileSpreadsheet className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                    {hint}
                </p>
            )}
        </div>
    );
};

export default TemplateDownloadButton;
